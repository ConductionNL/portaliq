<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\CaseTypeReader;
use OCA\Portaliq\Service\Intake\DestinationLookup;
use OCA\Portaliq\Service\Intake\PortalFee;
use OCA\Portaliq\Service\Intake\PortalIntakePayments;
use OCA\Portaliq\Service\PortalActionForwarder;
use OCA\Portaliq\Service\PortalDeepLinkBuilder;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Paying for a request whose case already exists (decision 181), and reading
 * the payment state from that case (submit-creates-the-case-directly 3.3).
 *
 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#3.3
 */
#[CoversClass(PortalIntakePayments::class)]
#[UsesClass(PortalFee::class)]
class PortalIntakePaymentsTest extends TestCase {
	private const SUBJECT = ['subjectRef' => 'abc'];

	private const SITE = ['slug' => 'zuid', 'paymentHosts' => ['pay.example']];

	private const FEE = ['amount' => 10, 'currency' => 'EUR', 'description' => 'Fee', 'payAction' => 'pay'];

	private DestinationLookup $lookup;

	private PortalFee $fees;

	private PortalContributionRegistry $registry;

	private PortalActionForwarder $forwarder;

	/**
	 * Build the doubles with a happy path as the default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->lookup    = $this->createMock(DestinationLookup::class);
		$this->fees      = $this->createMock(PortalFee::class);
		$this->registry  = $this->createMock(PortalContributionRegistry::class);
		$this->forwarder = $this->createMock(PortalActionForwarder::class);

		$this->fees->method('forBinding')->willReturn(self::FEE);
		$this->registry->method('aggregateFor')->willReturn(['contributions' => [['actions' => ['junk', ['id' => 'other'], ['id' => 'pay']]]]]);
		$this->forwarder->method('isForwardable')->willReturn(true);
	}//end setUp()

	/**
	 * The service over the doubles.
	 *
	 * @param PortalFee|null $fees The fee reader, the default double when null.
	 *
	 * @return PortalIntakePayments
	 */
	private function payments(?PortalFee $fees = null): PortalIntakePayments {
		$deepLinks = $this->createMock(PortalDeepLinkBuilder::class);
		$deepLinks->method('forSite')->willReturn('https://portal.example/?portal=zuid');

		return new PortalIntakePayments(
			lookup: $this->lookup,
			fees: ($fees ?? $this->fees),
			registry: $this->registry,
			forwarder: $this->forwarder,
			deepLinks: $deepLinks
		);
	}//end payments()

	/**
	 * The case a request created, as the lookup finds it.
	 *
	 * @param string      $subjectRef   Whose it is.
	 * @param string|null $paymentState Its payment state, or null for none yet.
	 *
	 * @return array<string, mixed>
	 */
	private function found(string $subjectRef = 'abc', ?string $paymentState = null): array {
		$object = ['identifier' => '2026-0042', 'portalSubject' => $subjectRef];
		if ($paymentState !== null) {
			$object['paymentState'] = $paymentState;
		}

		return [
			'object' => $object,
			'binding' => ['route' => 'leges', 'destination' => ['register' => 'dossiq', 'schema' => 'case', 'subjectField' => 'portalSubject', 'paymentField' => 'paymentState']],
			'schema' => [],
		];
	}//end found()

	public function testUnavailable(): void {
		$response = (new PortalIntakePayments())->start(self::SUBJECT, self::SITE, '2026-0042');
		$this->assertSame(502, $response->getStatus());

		$this->assertSame(502, $this->payments()->start(self::SUBJECT, null, '2026-0042')->getStatus());
	}//end testUnavailable()

	public function testTheCaseMustBeTheSubjectsOwn(): void {
		$this->lookup->method('find')->willReturnOnConsecutiveCalls($this->found(subjectRef: 'someone-else'), null);
		$this->forwarder->expects($this->never())->method('forward');

		$this->assertSame(404, $this->payments()->start(self::SUBJECT, self::SITE, '2026-0042')->getStatus());
		$this->assertSame(404, $this->payments()->start(self::SUBJECT, self::SITE, '2026-0099')->getStatus());
	}//end testTheCaseMustBeTheSubjectsOwn()

	public function testABindingThatStampsNoSubjectHasNoOwnerToPay(): void {
		$found = $this->found();
		unset($found['binding']['destination']['subjectField']);
		$this->lookup->method('find')->willReturn($found);

		$this->assertSame(404, $this->payments()->start(self::SUBJECT, self::SITE, '2026-0042')->getStatus());
	}//end testABindingThatStampsNoSubjectHasNoOwnerToPay()

	public function testNothingToPay(): void {
		$this->lookup->method('find')->willReturn($this->found());
		$fees = $this->createMock(PortalFee::class);
		$fees->method('forBinding')->willReturn(null);

		$this->assertSame(409, $this->payments(fees: $fees)->start(self::SUBJECT, self::SITE, '2026-0042')->getStatus());
	}//end testNothingToPay()

	public function testAPaidCaseHasNothingToPay(): void {
		$this->lookup->method('find')->willReturn($this->found(paymentState: 'paid'));
		$this->fees->method('stateOf')->willReturn('paid');
		$this->forwarder->expects($this->never())->method('forward');

		$this->assertSame(409, $this->payments()->start(self::SUBJECT, self::SITE, '2026-0042')->getStatus());
	}//end testAPaidCaseHasNothingToPay()

	public function testForbiddenWithoutPayAction(): void {
		$this->lookup->method('find')->willReturn($this->found());
		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn(['contributions' => []]);
		$this->registry = $registry;

		$this->assertSame(403, $this->payments()->start(self::SUBJECT, self::SITE, '2026-0042')->getStatus());
	}//end testForbiddenWithoutPayAction()

	public function testStartForwardsAgainstTheCaseAndStoresNothing(): void {
		$this->lookup->method('find')->willReturn($this->found());
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(201);
		$this->forwarder->expects($this->once())->method('forward')->with(
			['id' => 'pay'],
			self::SUBJECT,
			$this->callback(static fn (array $body): bool => $body['reference'] === '2026-0042' && $body['amount'] === 10 && str_contains($body['returnUrl'], 'route=%2Fleges'))
		)->willReturn($response);
		$this->forwarder->method('decodeBody')->willReturn(['checkoutUrl' => 'https://pay.example/c', 'paymentIntentId' => 'pi-1']);
		$this->fees->method('checkoutAllowed')->willReturn(true);

		$answer = $this->payments()->start(self::SUBJECT, self::SITE, '2026-0042');

		$this->assertSame(200, $answer->getStatus());
		$this->assertSame(['checkoutUrl' => 'https://pay.example/c'], $answer->getData());
	}//end testStartForwardsAgainstTheCaseAndStoresNothing()

	public function testProviderFailures(): void {
		$this->lookup->method('find')->willReturn($this->found());
		$failed = $this->createMock(IResponse::class);
		$failed->method('getStatusCode')->willReturn(500);
		$this->forwarder->method('forward')->willReturnOnConsecutiveCalls(null, $failed, $this->okResponse());
		$this->forwarder->method('decodeBody')->willReturn(['checkoutUrl' => 'https://evil.example/c']);
		$this->fees->method('checkoutAllowed')->willReturn(false);

		$payments = $this->payments();
		$this->assertSame(502, $payments->start(self::SUBJECT, self::SITE, '2026-0042')->getStatus());
		$this->assertSame(502, $payments->start(self::SUBJECT, self::SITE, '2026-0042')->getStatus());
		$this->assertSame(502, $payments->start(self::SUBJECT, self::SITE, '2026-0042')->getStatus());
	}//end testProviderFailures()

	public function testThePaymentStateIsTheCasesOwn(): void {
		$fees     = new PortalFee($this->createMock(CaseTypeReader::class));
		$payments = $this->payments(fees: $fees);

		$this->assertSame(['state' => 'paid'], $payments->paymentOf(found: $this->found(paymentState: 'paid')));
		$this->assertSame(['state' => 'unpaid'], $payments->paymentOf(found: $this->found(paymentState: 'open')));
		$this->assertNull($payments->paymentOf(found: $this->found()));

		$noField = $this->found(paymentState: 'paid');
		unset($noField['binding']['destination']['paymentField']);
		$this->assertNull($payments->paymentOf(found: $noField));
	}//end testThePaymentStateIsTheCasesOwn()

	/**
	 * A 200 response.
	 *
	 * @return IResponse
	 */
	private function okResponse(): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);

		return $response;
	}//end okResponse()
}//end class
