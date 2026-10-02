<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\AttachedActionResolver;
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
	 * #804, case-actions-sign-a-document D3: a row action that declares a
	 * `scopeClaim` forwards with the resolved value for the assertion (the
	 * way filinq's `sign` gets its `signerEmail`), and a claim that does not
	 * resolve stops the forward with 403.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-frozen-assertion-wire-format
	 */
	public function testADeclaredScopeClaimIsResolvedForTheAssertionOrTheForwardStops(): void {
		$action = $this->pay(['scopeClaim' => 'customerMasterId']);

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
			->with($action, self::SUBJECT, ['invoiceId' => self::INVOICE_ID], 'customer-master-1')
			->willReturn($response);
		$forwarder->method('decodeBody')->willReturn([]);

		$reader = $this->readerReturning($this->invoice());
		$reader->method('resolveScopeValue')->with('customerMasterId', 'shillinq', self::SUBJECT)->willReturn('customer-master-1');
		$result = $this->controller(collection: $this->salesInvoices(), action: $action, reader: $reader, forwarder: $forwarder)
			->forward('shillinq', 'ARInvoice', self::INVOICE_ID, 'pay');
		$this->assertSame(Http::STATUS_OK, $result->getStatus());
	}//end testADeclaredScopeClaimIsResolvedForTheAssertionOrTheForwardStops()

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
	/**
	 * The dossier id every attached-action test acts on.
	 */
	private const DOSSIER_ID = '00000000-0000-0000-0000-00000000d055';

	/**
	 * pipelinq's askAboutDossier, attached to opencatalogi's dossiers.
	 *
	 * @return array<string, mixed>
	 */
	private function askAboutDossier(): array {
		return [
			'id' => 'askAboutDossier',
			'label' => 'Stel een vraag over dit dossier',
			'type' => 'endpoint-forward',
			'endpoint' => '/index.php/apps/pipelinq/api/portal/dossier-questions',
			'method' => 'POST',
			'fields' => ['question'],
			'rowField' => 'collectionId',
			'attachTo' => ['app' => 'opencatalogi', 'schema' => 'collection'],
		];
	}//end askAboutDossier()

	/**
	 * A controller over opencatalogi's dossiers and pipelinq's attached action,
	 * resolved the way the registry resolves them.
	 *
	 * @param PortalObjectReader    $reader    The reader double.
	 * @param PortalActionForwarder $forwarder The forwarder double.
	 *
	 * @return PortalRowActionController
	 */
	private function dossierController(PortalObjectReader $reader, PortalActionForwarder $forwarder): PortalRowActionController {
		$params = ['collection' => 'mijnDossiers', 'actionApp' => 'pipelinq', 'question' => 'Wanneer wordt dit besloten?', 'collectionId' => 'someone-elses'];
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null) => ($params[$key] ?? $default));

		$aggregate = (new AttachedActionResolver())->resolve(contributions: [
			['app' => 'opencatalogi', 'collections' => [['id' => 'mijnDossiers', 'register' => 'opencatalogi', 'schema' => 'collection', 'scopeField' => 'owner']], 'actions' => []],
			['app' => 'pipelinq', 'collections' => [], 'actions' => [$this->askAboutDossier()]],
		]);
		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn(['contributions' => $aggregate]);

		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(self::SUBJECT);

		return new PortalRowActionController($request, $registry, $session, $reader, $forwarder, $this->createMock(AuditTrailService::class));
	}//end dossierController()

	/**
	 * Attached action forwards with the proven row: the dossier is read
	 * through opencatalogi's scope, the action goes to pipelinq with the
	 * proven id under its rowField.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-journey-entry-points/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-action-must-be-able-to-attach-to-another-apps-collection-req-wje-004
	 */
	public function testAttachedActionForwardsWithTheProvenRow(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->expects($this->once())->method('readObject')
			->with('opencatalogi', 'collection', 'owner', 'guardian-1', self::DOSSIER_ID, 'school-1', '', 'opencatalogi')
			->willReturn(['id' => self::DOSSIER_ID, 'title' => 'Fietspad Oost']);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(201);
		$forwarder = $this->createMock(PortalActionForwarder::class);
		$forwarder->method('isForwardable')->willReturn(true);
		$forwarder->expects($this->once())->method('forward')
			->with($this->askAboutDossier(), self::SUBJECT, ['question' => 'Wanneer wordt dit besloten?', 'collectionId' => self::DOSSIER_ID])
			->willReturn($response);
		$forwarder->method('decodeBody')->willReturn(['id' => 'ticket-1']);

		$result = $this->dossierController($reader, $forwarder)->forward('opencatalogi', 'collection', self::DOSSIER_ID, 'askAboutDossier');

		$this->assertSame(201, $result->getStatus());
		$this->assertSame(['id' => 'ticket-1'], $result->getData());
	}//end testAttachedActionForwardsWithTheProvenRow()

	/**
	 * Attached action on a foreign row: 404, nothing forwarded.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-journey-entry-points/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-action-must-be-able-to-attach-to-another-apps-collection-req-wje-004
	 */
	public function testAttachedActionOnAForeignRow(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->expects($this->once())->method('readObject')->willReturn(null);

		$result = $this->dossierController($reader, $this->forwarderThatMustNotForward())
			->forward('opencatalogi', 'collection', self::DOSSIER_ID, 'askAboutDossier');

		$this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
	}//end testAttachedActionOnAForeignRow()

	/**
	 * An action id the collection does not list is refused before any read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-journey-entry-points/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-action-must-be-able-to-attach-to-another-apps-collection-req-wje-004
	 */
	public function testAnUnlistedAttachedActionIsForbidden(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->expects($this->never())->method('readObject');

		$result = $this->dossierController($reader, $this->forwarderThatMustNotForward())
			->forward('opencatalogi', 'collection', self::DOSSIER_ID, 'deleteEverything');

		$this->assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());
	}//end testAnUnlistedAttachedActionIsForbidden()

	/**
	 * The question every own-collection test acts on.
	 */
	private const QUESTION_ID = '00000000-0000-0000-0000-0000000a5c00';

	/**
	 * pipelinq's reply, attached to its own `myQuestions` only.
	 *
	 * @return array<string, mixed>
	 */
	private function replyToQuestion(): array {
		return [
			'id' => 'replyToQuestion',
			'label' => 'Reageren op het antwoord',
			'endpoint' => '/index.php/apps/pipelinq/api/portal/questions/reply',
			'method' => 'POST',
			'fields' => ['ticket', 'message'],
			'rowField' => 'ticket',
			'rowWhen' => ['field' => 'status', 'in' => ['awaiting_customer']],
			'attachTo' => ['app' => 'pipelinq', 'schema' => 'ticket', 'collection' => 'myQuestions'],
		];
	}//end replyToQuestion()

	/**
	 * A controller over pipelinq's own questions with the reply attached to
	 * them, resolved the way the registry resolves them. The browser sends a
	 * forged `ticket`; the proven row id must win.
	 *
	 * @param PortalObjectReader    $reader    The reader double.
	 * @param PortalActionForwarder $forwarder The forwarder double.
	 *
	 * @return PortalRowActionController
	 */
	private function questionController(PortalObjectReader $reader, PortalActionForwarder $forwarder): PortalRowActionController {
		$params = ['collection' => 'myQuestions', 'actionApp' => 'pipelinq', 'message' => 'Dank u, nog een vraag.', 'ticket' => 'someone-elses'];
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null) => ($params[$key] ?? $default));

		$aggregate = (new AttachedActionResolver())->resolve(contributions: [
			[
				'app' => 'pipelinq',
				'collections' => [
					['id' => 'ownRequests', 'register' => 'pipelinq', 'schema' => 'ticket', 'scopeField' => 'portalSubject'],
					['id' => 'myQuestions', 'register' => 'pipelinq', 'schema' => 'ticket', 'scopeField' => 'portalSubject'],
				],
				'actions' => [$this->replyToQuestion()],
			],
		]);
		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn(['contributions' => $aggregate]);

		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(self::SUBJECT);

		return new PortalRowActionController($request, $registry, $session, $reader, $forwarder, $this->createMock(AuditTrailService::class));
	}//end questionController()

	/**
	 * An app answers on its own collection: the reply is forwarded to pipelinq
	 * with the proven question id, without the collection listing it as a row
	 * action (which would add a table button that cannot ask the message).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attach-to-own-collection/specs/portal-contribution-contract/spec.md#requirement-an-attached-action-must-be-able-to-name-one-collection-and-its-own-app-req-ato-001
	 */
	public function testAnActionAttachedToItsOwnCollectionForwards(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->expects($this->once())->method('readObject')
			->with('pipelinq', 'ticket', 'portalSubject', 'guardian-1', self::QUESTION_ID, 'school-1', '', 'pipelinq')
			->willReturn(['id' => self::QUESTION_ID, 'status' => 'awaiting_customer']);

		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$forwarder = $this->createMock(PortalActionForwarder::class);
		$forwarder->method('isForwardable')->willReturn(true);
		$forwarder->expects($this->once())->method('forward')
			->with($this->replyToQuestion(), self::SUBJECT, ['ticket' => self::QUESTION_ID, 'message' => 'Dank u, nog een vraag.'])
			->willReturn($response);
		$forwarder->method('decodeBody')->willReturn(['status' => 'in_progress']);

		$result = $this->questionController($reader, $forwarder)->forward('pipelinq', 'ticket', self::QUESTION_ID, 'replyToQuestion');

		$this->assertSame(200, $result->getStatus());
		$this->assertSame(['status' => 'in_progress'], $result->getData());
	}//end testAnActionAttachedToItsOwnCollectionForwards()

	/**
	 * A row outside the attached action's `rowWhen` is refused with 409 and
	 * nothing is forwarded: no reply while the question is not waiting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/attach-to-own-collection/specs/portal-contribution-contract/spec.md#requirement-an-attached-action-must-carry-its-rowwhen-to-the-renderer-req-ato-002
	 */
	public function testAnAttachedActionOutsideItsRowWhenIs409(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readObject')->willReturn(['id' => self::QUESTION_ID, 'status' => 'converted']);

		$result = $this->questionController($reader, $this->forwarderThatMustNotForward())
			->forward('pipelinq', 'ticket', self::QUESTION_ID, 'replyToQuestion');

		$this->assertSame(Http::STATUS_CONFLICT, $result->getStatus());
	}//end testAnAttachedActionOutsideItsRowWhenIs409()
}//end class
