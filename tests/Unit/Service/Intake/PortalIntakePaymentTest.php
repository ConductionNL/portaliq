<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\CaseTypeReader;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\Intake\PortalIntakeFee;
use OCA\Portaliq\Service\Intake\PortalIntakePayment;
use OCA\Portaliq\Service\Intake\PortalIntakeQueue;
use OCA\Portaliq\Service\PortalActionForwarder;
use OCA\Portaliq\Service\PortalDeepLinkBuilder;
use OCA\Portaliq\Tests\Unit\Service\Identity\PortalIdentityStoreTrait;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * intake-pay-on-submit REQ-IPS-001, REQ-IPS-003, REQ-IPS-004, REQ-IPS-005:
 * only the submitter pays, once, the declared amount, and only towards a
 * declared payment host; the state is read from integriq's record. The queue
 * and the fee reader are the real classes over the fake store; the case app's
 * answer and the case type are doubles.
 *
 * @spec openspec/changes/intake-pay-on-submit/specs/portal-intake-payment/spec.md#requirement-only-the-submitter-can-pay-once-req-ips-003
 */
class PortalIntakePaymentTest extends TestCase {
	use PortalIdentityStoreTrait;

	private const SITE = ['slug' => 'gemeente-x', 'paymentHosts' => ['www.mollie.com']];

	private const SUBJECT = ['subjectRef' => 'bsn-1', 'audience' => 'client', 'trust' => 'substantial'];

	private const FEE = ['amount' => '45.00', 'currency' => 'EUR', 'description' => 'Parkeervergunning', 'payApp' => 'dossiq', 'payAction' => 'pay-intake-fee'];

	/**
	 * What the forwarder was asked to send, per call.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $forwarded = [];

	/**
	 * What the case app answers, and the payment record's status.
	 *
	 * @var array{status: int, body: array<string, mixed>, paymentStatus: string|null, fee: array<string, string>|null, action: array<string, mixed>|null}
	 */
	private array $world = [];

	protected function setUp(): void {
		$this->rows = [];
		$this->forwarded = [];
		$this->world = [
			'status' => 201,
			'body' => ['checkoutUrl' => 'https://www.mollie.com/checkout/abc', 'paymentIntentId' => 'intent-1'],
			'paymentStatus' => null,
			'fee' => self::FEE,
			'action' => ['id' => 'pay-intake-fee', 'type' => 'endpoint', 'endpoint' => '/apps/dossiq/api/portal/intake-fee', 'method' => 'POST', 'minTrust' => 'substantial'],
		];

	}//end setUp()

	/**
	 * REQ-IPS-001: the case app receives the declared amount, whatever the
	 * browser sent, with the reference and a return address on the site.
	 *
	 * @return void
	 */
	public function testPayForwardsTheDeclaredAmount(): void {
		$reference = $this->submit(subjectRef: 'bsn-1');

		$answer = $this->payment()->pay(site: self::SITE, subject: self::SUBJECT, reference: $reference);

		$this->assertSame(200, $answer['status']);
		$this->assertSame(['checkoutUrl' => 'https://www.mollie.com/checkout/abc'], $answer['body']);
		$this->assertCount(1, $this->forwarded);
		$this->assertSame(
			[
				'reference' => $reference,
				'amount' => '45.00',
				'currency' => 'EUR',
				'description' => 'Parkeervergunning',
				'returnUrl' => 'https://gemeente-x.nl/site?reference='.$reference,
			],
			$this->forwarded[0]['whitelisted']
		);
		$this->assertSame('intent-1', $this->storedRows('portalIntakeSubmission')[0]['paymentIntentId']);

	}//end testPayForwardsTheDeclaredAmount()

	/**
	 * REQ-IPS-003: another resident's reference, and an anonymous one, read
	 * as not found, and nothing is forwarded.
	 *
	 * @return void
	 */
	public function testForeignReferenceIs404(): void {
		$theirs = $this->submit(subjectRef: 'bsn-2');
		$anonymous = $this->submit(subjectRef: '');

		foreach ([$theirs, $anonymous, 'AANVRAAG-NOPE'] as $reference) {
			$answer = $this->payment()->pay(site: self::SITE, subject: self::SUBJECT, reference: $reference);
			$this->assertSame([404, ['error' => 'reference_not_found']], [$answer['status'], $answer['body']]);
		}

		$this->assertSame([], $this->forwarded);

	}//end testForeignReferenceIs404()

	/**
	 * REQ-IPS-003: a paid request is not paid twice; a failed payment may be
	 * tried again.
	 *
	 * @return void
	 */
	public function testPaidSubmissionIs409(): void {
		$reference = $this->submit(subjectRef: 'bsn-1');
		$this->payment()->pay(site: self::SITE, subject: self::SUBJECT, reference: $reference);
		$this->forwarded = [];

		$this->world['paymentStatus'] = 'paid';
		$answer = $this->payment()->pay(site: self::SITE, subject: self::SUBJECT, reference: $reference);
		$this->assertSame([409, ['error' => 'already_paid']], [$answer['status'], $answer['body']]);
		$this->assertSame([], $this->forwarded);

		$this->world['paymentStatus'] = 'canceled';
		$this->assertSame(200, $this->payment()->pay(site: self::SITE, subject: self::SUBJECT, reference: $reference)['status']);

	}//end testPaidSubmissionIs409()

	public function testARequestWithoutAFeeIs409(): void {
		$reference = $this->submit(subjectRef: 'bsn-1');
		$this->world['fee'] = null;

		$answer = $this->payment()->pay(site: self::SITE, subject: self::SUBJECT, reference: $reference);

		$this->assertSame([409, ['error' => 'no_fee']], [$answer['status'], $answer['body']]);
		$this->assertSame([], $this->forwarded);

	}//end testARequestWithoutAFeeIs409()

	/**
	 * REQ-IPS-003: the declared pay action must be in the resident's own
	 * manifest, be an instance-local endpoint, and fit their session.
	 *
	 * @return array<string, array{0: array<string, mixed>|null, 1: array<string, mixed>}>
	 */
	public static function unofferedActions(): array {
		$action = ['id' => 'pay-intake-fee', 'type' => 'endpoint', 'endpoint' => '/apps/dossiq/api/portal/intake-fee', 'method' => 'POST'];

		return [
			'not in the manifest' => [null, self::SUBJECT],
			'an outside address' => [['endpoint' => 'https://evil.example/pay'] + $action, self::SUBJECT],
			'a stronger session needed' => [['minTrust' => 'high'] + $action, self::SUBJECT],
		];

	}//end unofferedActions()

	/**
	 * @param array<string, mixed>|null $action The action the manifest offers.
	 * @param array<string, mixed> $subject The resident.
	 *
	 * @return void
	 */
	#[DataProvider('unofferedActions')]
	public function testAnUnofferedPayActionIs403(?array $action, array $subject): void {
		$reference = $this->submit(subjectRef: 'bsn-1');
		$this->world['action'] = $action;

		$answer = $this->payment()->pay(site: self::SITE, subject: $subject, reference: $reference);

		$this->assertSame([403, ['error' => 'pay_action_not_offered']], [$answer['status'], $answer['body']]);
		$this->assertSame([], $this->forwarded);

	}//end testAnUnofferedPayActionIs403()

	/**
	 * REQ-IPS-004: a checkout on a host the portal did not declare, or over
	 * http, is never handed to the browser.
	 *
	 * @return void
	 */
	public function testUndeclaredCheckoutHostIsRefused(): void {
		foreach (['https://pay.example.org/checkout', 'http://www.mollie.com/checkout/abc', 'javascript:alert(1)'] as $url) {
			$this->setUp();
			$reference = $this->submit(subjectRef: 'bsn-1');
			$this->world['body'] = ['checkoutUrl' => $url, 'paymentIntentId' => 'intent-1'];

			$answer = $this->payment()->pay(site: self::SITE, subject: self::SUBJECT, reference: $reference);

			$this->assertSame([502, ['error' => 'payment_unavailable']], [$answer['status'], $answer['body']], $url);
		}

		$this->setUp();
		$reference = $this->submit(subjectRef: 'bsn-1');
		$answer = $this->payment()->pay(site: ['slug' => 'gemeente-x'], subject: self::SUBJECT, reference: $reference);
		$this->assertSame(502, $answer['status'], 'no payment host declared, nothing redirects');

	}//end testUndeclaredCheckoutHostIsRefused()

	public function testACaseAppThatRefusesOrAnswersHalfIs502(): void {
		foreach ([[500, ['error' => 'x']], [201, ['checkoutUrl' => 'https://www.mollie.com/x']], [201, ['paymentIntentId' => 'intent-1']]] as [$status, $body]) {
			$this->setUp();
			$reference = $this->submit(subjectRef: 'bsn-1');
			$this->world['status'] = $status;
			$this->world['body'] = $body;

			$this->assertSame(502, $this->payment()->pay(site: self::SITE, subject: self::SUBJECT, reference: $reference)['status']);
			$this->assertArrayNotHasKey('paymentIntentId', $this->storedRows('portalIntakeSubmission')[0]);
		}

	}//end testACaseAppThatRefusesOrAnswersHalfIs502()

	/**
	 * Integriq's paymentStatus, as the resident reads it (design D4).
	 *
	 * @return array<string, array{0: string|null, 1: string}>
	 */
	public static function states(): array {
		return [
			'paid' => ['paid', PortalIntakePayment::STATE_PAID],
			'authorized' => ['authorized', PortalIntakePayment::STATE_PAID],
			'open' => ['open', PortalIntakePayment::STATE_OPEN],
			'pending' => ['pending', PortalIntakePayment::STATE_OPEN],
			'failed' => ['failed', PortalIntakePayment::STATE_FAILED],
			'canceled' => ['canceled', PortalIntakePayment::STATE_FAILED],
			'expired' => ['expired', PortalIntakePayment::STATE_FAILED],
			'refunded' => ['refunded', PortalIntakePayment::STATE_UNKNOWN],
			'chargeback' => ['chargeback', PortalIntakePayment::STATE_UNKNOWN],
			'no record' => [null, PortalIntakePayment::STATE_UNKNOWN],
		];

	}//end states()

	/**
	 * @param string|null $paymentStatus The record's status, or null for no record.
	 * @param string $shown What the resident reads.
	 *
	 * @return void
	 */
	#[DataProvider('states')]
	public function testThePaymentStateIsReadFromTheRecord(?string $paymentStatus, string $shown): void {
		$this->world['paymentStatus'] = $paymentStatus;

		$this->assertSame($shown, $this->payment()->stateOf(paymentIntentId: 'intent-1'));

	}//end testThePaymentStateIsReadFromTheRecord()

	/**
	 * Submit a request through the real queue.
	 *
	 * @param string $subjectRef Who submitted it.
	 *
	 * @return string The reference.
	 */
	private function submit(string $subjectRef): string {
		$queue = new PortalIntakeQueue($this->fakeReader(), $this->fakeWriter(), $this->fakeRandom());

		return $queue->accept(portal: 'gemeente-x', route: 'aanvragen/parkeren', answers: ['kenteken' => 'AB-12-CD'], subjectRef: $subjectRef)['reference'];
	}//end submit()

	/**
	 * The payment service over the fake store and the doubles of this world.
	 *
	 * @return PortalIntakePayment
	 */
	private function payment(): PortalIntakePayment {
		$records = $this->createMock(CaseTypeReader::class);
		$records->method('readCaseType')->willReturnCallback(
			fn (string $register, string $schema, string $id): ?array => match ($schema) {
				PortalIntakePayment::INTENT_SCHEMA => ($this->world['paymentStatus'] === null ? null : ['paymentStatus' => $this->world['paymentStatus']]),
				'zaaktype' => ($this->world['fee'] === null ? ['title' => 'Parkeren'] : ['title' => 'Parkeren', 'portalFee' => $this->world['fee']]),
				default => null,
			}
		);

		$bindings = $this->getMockBuilder(PortalFormBindingResolver::class)->disableOriginalConstructor()->onlyMethods(['bindingFor'])->getMock();
		$bindings->method('bindingFor')->willReturnCallback(
			static fn (string $portal, string $route): ?array => ($route === 'aanvragen/parkeren') ? ['portal' => $portal, 'route' => $route, 'typeRegister' => 'dossiq', 'typeSchema' => 'zaaktype', 'typeId' => 'parkeren'] : null
		);

		$registry = $this->getMockBuilder(PortalContributionRegistry::class)->disableOriginalConstructor()->onlyMethods(['aggregateFor'])->getMock();
		$actions = [];
		if ($this->world['action'] !== null) {
			$actions[] = $this->world['action'];
		}

		$registry->method('aggregateFor')->willReturn(['contributions' => [['app' => 'shillinq', 'actions' => [['id' => 'pay-intake-fee', 'type' => 'endpoint', 'endpoint' => '/apps/shillinq/pay']]], ['app' => 'dossiq', 'actions' => $actions]]]);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturnCallback(fn (): int => $this->world['status']);
		$response->method('getBody')->willReturnCallback(fn (): string => (string)json_encode($this->world['body']));
		$forwarder = $this->getMockBuilder(PortalActionForwarder::class)->disableOriginalConstructor()->onlyMethods(['forward'])->getMock();
		$forwarder->method('forward')->willReturnCallback(
			function (array $action, array $subject, ?array $whitelisted = null, string $scopeValue = '') use ($response): IResponse {
				$this->forwarded[] = ['action' => $action, 'subject' => $subject, 'whitelisted' => $whitelisted];
				return $response;
			}
		);

		$links = $this->getMockBuilder(PortalDeepLinkBuilder::class)->disableOriginalConstructor()->onlyMethods(['forSite'])->getMock();
		$links->method('forSite')->willReturnCallback(static fn (string $portalSlug): string => 'https://'.$portalSlug.'.nl/site');

		return new PortalIntakePayment(
			$records,
			new PortalIntakeFee($records),
			new PortalIntakeQueue($this->fakeReader(), $this->fakeWriter(), $this->fakeRandom()),
			$bindings,
			$registry,
			$forwarder,
			$links
		);
	}//end payment()
}//end class
