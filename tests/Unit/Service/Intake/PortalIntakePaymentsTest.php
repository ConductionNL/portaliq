<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\Intake\PortalFee;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\Intake\PortalIntakePayments;
use OCA\Portaliq\Service\Intake\PortalIntakeQueue;
use OCA\Portaliq\Service\Intake\PortalPaymentIntents;
use OCA\Portaliq\Service\PortalActionForwarder;
use OCA\Portaliq\Service\PortalDeepLinkBuilder;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Starting an intake payment and reading its state.
 */
#[CoversClass(PortalIntakePayments::class)]
class PortalIntakePaymentsTest extends TestCase {
	private const SUBJECT = ['subjectRef' => 'abc'];

	private const SITE = ['slug' => 'zuid', 'paymentHosts' => ['pay.example']];

	private const FEE = ['amount' => 10, 'currency' => 'EUR', 'description' => 'Fee', 'payAction' => 'pay'];

	private PortalIntakeQueue $queue;

	private PortalFormBindingResolver $bindings;

	private PortalFee $fees;

	private PortalPaymentIntents $intents;

	private PortalContributionRegistry $registry;

	private PortalActionForwarder $forwarder;

	/**
	 * Build the doubles with a happy path as the default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->queue    = $this->createMock(PortalIntakeQueue::class);
		$this->bindings = $this->createMock(PortalFormBindingResolver::class);
		$this->fees     = $this->createMock(PortalFee::class);
		$this->intents  = $this->createMock(PortalPaymentIntents::class);
		$this->registry = $this->createMock(PortalContributionRegistry::class);
		$this->forwarder = $this->createMock(PortalActionForwarder::class);

		$this->bindings->method('bindingFor')->willReturn(['x' => 1]);
		$this->fees->method('forBinding')->willReturn(self::FEE);
		$this->registry->method('aggregateFor')->willReturn(['contributions' => [['actions' => ['junk', ['id' => 'other'], ['id' => 'pay']]]]]);
		$this->forwarder->method('isForwardable')->willReturn(true);
	}//end setUp()

	/**
	 * The payments service over the doubles.
	 *
	 * @param bool $withFees Whether the optional collaborators are present.
	 *
	 * @return PortalIntakePayments
	 */
	private function payments(bool $withFees=true): PortalIntakePayments {
		if ($withFees === false) {
			return new PortalIntakePayments($this->queue, $this->bindings);
		}

		$links = $this->createMock(PortalDeepLinkBuilder::class);
		$links->method('forSite')->willReturn('/index.php/apps/portaliq/?portal=zuid');

		return new PortalIntakePayments($this->queue, $this->bindings, $this->fees, $this->intents, $this->registry, $this->forwarder, $links);
	}//end payments()

	/**
	 * A forwarder response with a status.
	 *
	 * @param int $status The HTTP status.
	 *
	 * @return IResponse
	 */
	private function response(int $status): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);

		return $response;
	}//end response()

	/**
	 * Without a site or the optional collaborators payment is unavailable.
	 *
	 * @return void
	 */
	public function testUnavailable(): void {
		$this->assertSame(502, $this->payments(false)->start(self::SUBJECT, self::SITE, 'R1')->getStatus());
		$this->assertSame(502, $this->payments()->start(self::SUBJECT, null, 'R1')->getStatus());
	}//end testUnavailable()

	/**
	 * Only the submitter may pay: unknown, foreign or failing lookups are 404.
	 *
	 * @return void
	 */
	public function testSubmissionMustBeOwn(): void {
		$this->queue->method('find')->willReturnOnConsecutiveCalls(null, ['subjectRef' => 'other'], ['subjectRef' => '']);
		$p = $this->payments();

		foreach ([1, 2, 3] as $ignored) {
			$response = $p->start(self::SUBJECT, self::SITE, 'R1');
			$this->assertSame(404, $response->getStatus());
			$this->assertSame(['error' => 'reference_not_found'], $response->getData());
		}
	}//end testSubmissionMustBeOwn()

	/**
	 * A thrown lookup counts as not found.
	 *
	 * @return void
	 */
	public function testLookupFailureIsNotFound(): void {
		$this->queue->method('find')->willThrowException(new \RuntimeException('db'));

		$this->assertSame(404, $this->payments()->start(self::SUBJECT, self::SITE, 'R1')->getStatus());
		$this->assertNull($this->payments()->paymentOf('R1', 'zuid'));
	}//end testLookupFailureIsNotFound()

	/**
	 * An already paid submission has nothing to pay.
	 *
	 * @return void
	 */
	public function testNothingToPay(): void {
		$this->queue->method('find')->willReturn(['subjectRef' => 'abc', 'route' => '/r', 'paymentIntentId' => 'pi_1']);
		$this->intents->method('status')->willReturn('paid');
		$this->fees->method('stateOf')->willReturn('paid');

		$response = $this->payments()->start(self::SUBJECT, self::SITE, 'R1');

		$this->assertSame(409, $response->getStatus());
		$this->assertSame(['error' => 'nothing_to_pay'], $response->getData());
	}//end testNothingToPay()

	/**
	 * Without a forwardable pay action the caller is refused.
	 *
	 * @return void
	 */
	public function testForbiddenWithoutPayAction(): void {
		$this->queue->method('find')->willReturn(['subjectRef' => 'abc', 'route' => '/r']);
		$forwarder = $this->createMock(PortalActionForwarder::class);
		$forwarder->method('isForwardable')->willReturn(false);
		$links = $this->createMock(PortalDeepLinkBuilder::class);
		$p = new PortalIntakePayments($this->queue, $this->bindings, $this->fees, $this->intents, $this->registry, $forwarder, $links);

		$this->assertSame(403, $p->start(self::SUBJECT, self::SITE, 'R1')->getStatus());
	}//end testForbiddenWithoutPayAction()

	/**
	 * A good provider answer returns the checkout URL and records the intent.
	 *
	 * @return void
	 */
	public function testStartSucceeds(): void {
		$submission = ['subjectRef' => 'abc', 'route' => 'r/x'];
		$this->queue->method('find')->willReturn($submission);
		$this->queue->expects($this->once())->method('markPaymentIntent')->with($submission, 'pi_9');
		$this->forwarder->expects($this->once())->method('forward')->with(
			$this->anything(),
			self::SUBJECT,
			$this->callback(static fn (array $w): bool => $w['amount'] === 10 && $w['reference'] === 'R1' && str_contains($w['returnUrl'], 'route=%2Fr%2Fx&reference=R1'))
		)->willReturn($this->response(200));
		$this->forwarder->method('decodeBody')->willReturn(['checkoutUrl' => 'https://pay.example/c', 'paymentIntentId' => ' pi_9 ']);
		$this->fees->method('checkoutAllowed')->willReturn(true);

		$response = $this->payments()->start(self::SUBJECT, self::SITE, 'R1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['checkoutUrl' => 'https://pay.example/c'], $response->getData());
	}//end testStartSucceeds()

	/**
	 * A failing provider, a missing intent or a disallowed checkout host is a 502.
	 *
	 * @return void
	 */
	public function testProviderFailures(): void {
		$this->queue->method('find')->willReturn(['subjectRef' => 'abc', 'route' => '/r']);
		$this->queue->expects($this->never())->method('markPaymentIntent');
		$this->forwarder->method('forward')->willReturnOnConsecutiveCalls(null, $this->response(500), $this->response(200), $this->response(200));
		$this->forwarder->method('decodeBody')->willReturnOnConsecutiveCalls(
			['checkoutUrl' => 'https://pay.example/c'],
			['checkoutUrl' => 'https://evil.example/c', 'paymentIntentId' => 'pi']
		);
		$this->fees->method('checkoutAllowed')->willReturn(false);
		$p = $this->payments();

		for ($i = 0; $i < 4; $i++) {
			$response = $p->start(self::SUBJECT, self::SITE, 'R1');
			$this->assertSame(502, $response->getStatus());
			$this->assertSame(['error' => 'payment_unavailable'], $response->getData());
		}
	}//end testProviderFailures()

	/**
	 * Payment state: none without an intent, unknown when the status is lost.
	 *
	 * @return void
	 */
	public function testPaymentOf(): void {
		$this->queue->method('find')->willReturnOnConsecutiveCalls(['x' => 1], ['paymentIntentId' => 'pi'], ['paymentIntentId' => 'pi'], ['paymentIntentId' => 'pi']);
		$this->intents->method('status')->willReturnOnConsecutiveCalls(null, 'open');
		$this->fees->method('stateOf')->with('open')->willReturn('pending');
		$p = $this->payments();

		$this->assertNull($p->paymentOf('R1', 'zuid'));
		$this->assertSame(['state' => 'unknown'], $p->paymentOf('R1', 'zuid'));
		$this->assertSame(['state' => 'pending'], $p->paymentOf('R1', 'zuid'));
		$this->assertNull($this->payments(false)->paymentOf('R1', 'zuid'));
	}//end testPaymentOf()
}//end class
