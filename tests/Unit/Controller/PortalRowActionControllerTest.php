<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\PortalRowActionController;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\PortalActionForwarder;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\Http\Client\IResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The row-scoped endpoint action forward (contribution-pay-screen): a guardian
 * pays one of their own school contributions. The registry, reader and
 * forwarder are doubles, so every test asserts what was, and what was never,
 * reached. The manifest is shillinq's parent manifest after its rowField
 * follow-up.
 *
 * @spec openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-a-row-scoped-forward-must-prove-the-row-before-it-forwards
 */
class PortalRowActionControllerTest extends TestCase {
	/**
	 * The guardian.
	 */
	private const SUBJECT = [
		'subjectRef' => 'guardian-1',
		'audience' => 'parent',
		'organisation' => 'school-1',
		'trust' => 'low',
		'jti' => 'jti-1',
	];

	/**
	 * The guardian's own issued contribution.
	 */
	private const INVOICE_ID = '00000000-0000-0000-0000-000000000010';

	/**
	 * Shillinq's pay action.
	 *
	 * @param array<string, mixed> $overrides Keys to replace or add.
	 *
	 * @return array<string, mixed>
	 */
	private function pay(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'pay',
				'label' => 'Pay now',
				'type' => 'endpoint-forward',
				'endpoint' => '/apps/shillinq/api/portal/payments/initiate',
				'method' => 'POST',
				'minTrust' => 'low',
				'rowField' => 'invoiceId',
				'rowWhen' => ['field' => 'state', 'in' => ['issued', 'partially-paid', 'overdue']],
			],
			$overrides
		);
	}//end pay()

	/**
	 * Shillinq's parent `salesInvoices` collection, normalised.
	 *
	 * @param array<string, mixed> $overrides Keys to replace or add.
	 *
	 * @return array<string, mixed>
	 */
	private function salesInvoices(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'salesInvoices',
				'register' => 'shillinq',
				'schema' => 'ARInvoice',
				'scopeField' => 'customerId',
				'scopeClaim' => 'customerMasterId',
				'fields' => ['invoiceNumber', 'grossAmount', 'state', 'invoiceNote'],
				'rowActions' => ['pay'],
				'noticeField' => 'invoiceNote',
			],
			$overrides
		);
	}//end salesInvoices()

	/**
	 * An invoice row as the scoped reader returns it.
	 *
	 * @param string $state The invoice state.
	 *
	 * @return array<string, mixed>
	 */
	private function invoice(string $state = 'issued'): array {
		return ['id' => self::INVOICE_ID, 'invoiceNumber' => 'CTB-2026-0001', 'grossAmount' => 60.0, 'state' => $state];
	}//end invoice()

	/**
	 * Build the controller around the given doubles.
	 *
	 * @param array<string, mixed> $collection The collection in the aggregate.
	 * @param array<string, mixed> $action The action in the aggregate.
	 * @param PortalObjectReader|null $reader The reader double.
	 * @param PortalActionForwarder|null $forwarder The forwarder double.
	 * @param AuditTrailService|null $auditor The auditor double.
	 * @param array<string, mixed>|null $subject The resolved subject, or null.
	 * @param array<string, mixed> $params The request params.
	 *
	 * @return PortalRowActionController
	 */
	private function controller(
		array $collection,
		array $action,
		?PortalObjectReader $reader = null,
		?PortalActionForwarder $forwarder = null,
		?AuditTrailService $auditor = null,
		?array $subject = self::SUBJECT,
		array $params = ['collection' => 'salesInvoices'],
	): PortalRowActionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null) => ($params[$key] ?? $default));

		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn(
			['contributions' => [['app' => 'shillinq', 'collections' => [$collection], 'actions' => [$action]]]]
		);

		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);

		return new PortalRowActionController(
			$request,
			$registry,
			$session,
			($reader ?? $this->createMock(PortalObjectReader::class)),
			($forwarder ?? $this->forwarderThatMustNotForward()),
			($auditor ?? $this->createMock(AuditTrailService::class)),
		);
	}//end controller()

	/**
	 * A forwarder whose endpoint check passes and whose forward must never run.
	 *
	 * @return PortalActionForwarder&MockObject
	 */
	private function forwarderThatMustNotForward(): PortalActionForwarder {
		$forwarder = $this->createMock(PortalActionForwarder::class);
		$forwarder->method('isForwardable')->willReturn(true);
		$forwarder->expects($this->never())->method('forward');

		return $forwarder;
	}//end forwarderThatMustNotForward()

	/**
	 * A reader that returns the given row for the guardian's scope.
	 *
	 * @param array<string, mixed>|null $row The row, or null for "not yours".
	 *
	 * @return PortalObjectReader&MockObject
	 */
	private function readerReturning(?array $row): PortalObjectReader {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->expects($this->once())->method('readObject')
			->with('shillinq', 'ARInvoice', 'customerId', 'guardian-1', self::INVOICE_ID, 'school-1', 'customerMasterId', 'shillinq')
			->willReturn($row);

		return $reader;
	}//end readerReturning()

	/**
	 * The happy path: shillinq receives exactly the proven row id under
	 * `invoiceId`, whatever the browser sent, and its answer is relayed.
	 *
	 * @return void
	 */
	public function testTheProvenRowIdIsStampedAndTheClientBodyIsIgnored(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);

		$forwarder = $this->createMock(PortalActionForwarder::class);
		$forwarder->method('isForwardable')->willReturn(true);
		$forwarder->expects($this->once())->method('forward')
			->with($this->pay(), self::SUBJECT, ['invoiceId' => self::INVOICE_ID])
			->willReturn($response);
		$forwarder->method('decodeBody')->willReturn(['checkoutUrl' => 'https://pay.example.nl/checkout/REPLACE_ME']);

		$auditor = $this->createMock(AuditTrailService::class);
		$auditor->expects($this->once())->method('record')
			->with('forward', 'guardian-1', 'school-1', 'shillinq', 'pay', self::INVOICE_ID, 'jti-1');

		$result = $this->controller(
			collection: $this->salesInvoices(),
			action: $this->pay(),
			reader: $this->readerReturning($this->invoice()),
			forwarder: $forwarder,
			auditor: $auditor,
			params: ['collection' => 'salesInvoices', 'invoiceId' => 'someone-elses', 'amount' => 1],
		)->forward('shillinq', 'ARInvoice', self::INVOICE_ID, 'pay');

		$this->assertSame(Http::STATUS_OK, $result->getStatus());
		$this->assertSame(['checkoutUrl' => 'https://pay.example.nl/checkout/REPLACE_ME'], $result->getData());
	}//end testTheProvenRowIdIsStampedAndTheClientBodyIsIgnored()

	/**
	 * A declared `fields` whitelist forwards only those params, and the stamp
	 * still wins over a client value under the row field.
	 *
	 * @return void
	 */
	public function testAFieldsWhitelistIsForwardedAndTheStampWins(): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(503);

		$action = $this->pay(['fields' => ['consent', 'invoiceId']]);
		$forwarder = $this->createMock(PortalActionForwarder::class);
		$forwarder->method('isForwardable')->willReturn(true);
		$forwarder->expects($this->once())->method('forward')
			->with($action, self::SUBJECT, ['consent' => true, 'invoiceId' => self::INVOICE_ID])
			->willReturn($response);
		$forwarder->method('decodeBody')->willReturn(['status' => 'deferred']);

		$result = $this->controller(
			collection: $this->salesInvoices(),
			action: $action,
			reader: $this->readerReturning($this->invoice()),
			forwarder: $forwarder,
			params: ['collection' => 'salesInvoices', 'consent' => true, 'invoiceId' => 'someone-elses', 'amount' => 1],
		)->forward('shillinq', 'ARInvoice', self::INVOICE_ID, 'pay');

		// Shillinq's "no provider bound" answer is relayed as it came.
		$this->assertSame(503, $result->getStatus());
		$this->assertSame(['status' => 'deferred'], $result->getData());
	}//end testAFieldsWhitelistIsForwardedAndTheStampWins()

	/**
	 * A row the guardian's scope does not read is one 404, and shillinq is
	 * never called.
	 *
	 * @return void
	 */
	public function testARowOutsideTheScopeIs404WithoutForwarding(): void {
		$auditor = $this->createMock(AuditTrailService::class);
		$auditor->expects($this->never())->method('record');

		$result = $this->controller(
			collection: $this->salesInvoices(),
			action: $this->pay(),
			reader: $this->readerReturning(null),
			auditor: $auditor,
		)->forward('shillinq', 'ARInvoice', self::INVOICE_ID, 'pay');

		$this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
	}//end testARowOutsideTheScopeIs404WithoutForwarding()

	/**
	 * A paid contribution does not match `rowWhen`: 409, no forward.
	 *
	 * @return void
	 */
	public function testARowOutsideRowWhenIs409WithoutForwarding(): void {
		$result = $this->controller(
			collection: $this->salesInvoices(),
			action: $this->pay(),
			reader: $this->readerReturning($this->invoice('paid')),
		)->forward('shillinq', 'ARInvoice', self::INVOICE_ID, 'pay');

		$this->assertSame(Http::STATUS_CONFLICT, $result->getStatus());
		$this->assertSame(['error' => 'not_offered'], $result->getData());
	}//end testARowOutsideRowWhenIs409WithoutForwarding()

	/**
	 * An action the collection does not offer on rows, an action that is not
	 * an endpoint row action, and an unknown collection id: 403, no read.
	 *
	 * @return void
	 */
	public function testAnActionTheCollectionDoesNotOfferIs403(): void {
		$withoutRowField = $this->pay();
		unset($withoutRowField['rowField']);

		$cases = [
			'not in rowActions' => [$this->salesInvoices(['rowActions' => []]), $this->pay(), ['collection' => 'salesInvoices']],
			'no rowField' => [$this->salesInvoices(), $withoutRowField, ['collection' => 'salesInvoices']],
			'other collection' => [$this->salesInvoices(), $this->pay(), ['collection' => 'paymentRequests']],
		];

		foreach ($cases as $label => [$collection, $action, $params]) {
			$reader = $this->createMock(PortalObjectReader::class);
			$reader->expects($this->never())->method('readObject');

			$result = $this->controller(collection: $collection, action: $action, reader: $reader, params: $params)
				->forward('shillinq', 'ARInvoice', self::INVOICE_ID, 'pay');

			$this->assertSame(Http::STATUS_FORBIDDEN, $result->getStatus(), $label);
		}
	}//end testAnActionTheCollectionDoesNotOfferIs403()

	/**
	 * Trust below the action or the collection, or an endpoint the forwarder
	 * refuses: 403 before any read.
	 *
	 * @return void
	 */
	public function testTrustBelowTheActionIs403(): void {
		$cases = [
			'action' => [$this->salesInvoices(), $this->pay(['minTrust' => 'substantial']), true],
			'collection' => [$this->salesInvoices(['minTrust' => 'high']), $this->pay(), true],
			'endpoint' => [$this->salesInvoices(), $this->pay(), false],
		];

		foreach ($cases as $label => [$collection, $action, $forwardable]) {
			$reader = $this->createMock(PortalObjectReader::class);
			$reader->expects($this->never())->method('readObject');
			$forwarder = $this->createMock(PortalActionForwarder::class);
			$forwarder->method('isForwardable')->willReturn($forwardable);
			$forwarder->expects($this->never())->method('forward');

			$result = $this->controller(collection: $collection, action: $action, reader: $reader, forwarder: $forwarder)
				->forward('shillinq', 'ARInvoice', self::INVOICE_ID, 'pay');

			$this->assertSame(Http::STATUS_FORBIDDEN, $result->getStatus(), $label);
		}
	}//end testTrustBelowTheActionIs403()

	/**
	 * No subject is 401, before the registry is asked.
	 *
	 * @return void
	 */
	public function testNoSubjectIs401(): void {
		$result = $this->controller(collection: $this->salesInvoices(), action: $this->pay(), subject: null)
			->forward('shillinq', 'ARInvoice', self::INVOICE_ID, 'pay');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $result->getStatus());
	}//end testNoSubjectIs401()

	/**
	 * A declared `subjectField` is stamped with the resolved scope, and an
	 * unresolvable scope stops the forward with 403.
	 *
	 * @return void
	 */
	public function testASubjectFieldIsStampedOrTheForwardStops(): void {
		$action = $this->pay(['subjectField' => 'guardianRef', 'scopeClaim' => 'guardianRef']);

		$reader = $this->readerReturning($this->invoice());
		$reader->method('resolveScopeValue')->willReturn(null);
		$result = $this->controller(collection: $this->salesInvoices(), action: $action, reader: $reader)
			->forward('shillinq', 'ARInvoice', self::INVOICE_ID, 'pay');
		$this->assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$forwarder = $this->createMock(PortalActionForwarder::class);
		$forwarder->method('isForwardable')->willReturn(true);
		$forwarder->expects($this->once())->method('forward')
			->with($action, self::SUBJECT, ['invoiceId' => self::INVOICE_ID, 'guardianRef' => 'guardian-ref-1'])
			->willReturn($response);
		$forwarder->method('decodeBody')->willReturn([]);

		$reader = $this->readerReturning($this->invoice());
		$reader->method('resolveScopeValue')->with('guardianRef', 'shillinq', self::SUBJECT)->willReturn('guardian-ref-1');
		$result = $this->controller(collection: $this->salesInvoices(), action: $action, reader: $reader, forwarder: $forwarder)
			->forward('shillinq', 'ARInvoice', self::INVOICE_ID, 'pay');
		$this->assertSame(Http::STATUS_OK, $result->getStatus());
	}//end testASubjectFieldIsStampedOrTheForwardStops()

	/**
	 * A transport failure is 502 and never leaks its cause.
	 *
	 * @return void
	 */
	public function testATransportFailureIs502(): void {
		$forwarder = $this->createMock(PortalActionForwarder::class);
		$forwarder->method('isForwardable')->willReturn(true);
		$forwarder->expects($this->once())->method('forward')->willReturn(null);

		$result = $this->controller(
			collection: $this->salesInvoices(),
			action: $this->pay(),
			reader: $this->readerReturning($this->invoice('overdue')),
			forwarder: $forwarder,
		)->forward('shillinq', 'ARInvoice', self::INVOICE_ID, 'pay');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $result->getStatus());
		$this->assertSame(['error' => 'forward_failed'], $result->getData());
	}//end testATransportFailureIs502()
}//end class
