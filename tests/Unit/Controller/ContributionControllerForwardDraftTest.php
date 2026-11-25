<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\CreateActionMatcher;
use OCA\Portaliq\Contribution\CreateBody;
use OCA\Portaliq\Contribution\InboxMessageFields;
use OCA\Portaliq\Contribution\PageChoice;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Contribution\RowIdentifier;
use OCA\Portaliq\Contribution\ThemeChoice;
use OCA\Portaliq\Controller\ContributionController;
use OCA\Portaliq\Kernel\Contracts\DestinationDraftInterface;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\CaseRowMarker;
use OCA\Portaliq\Service\Intake\ActionDraft;
use OCA\Portaliq\Service\NotificationDispatchService;
use OCA\Portaliq\Service\PortalActionForwarder;
use OCA\Portaliq\Service\PortalAuditHook;
use OCA\Portaliq\Service\PortalFileReader;
use OCA\Portaliq\Service\PortalFileWriter;
use OCA\Portaliq\Service\PortalInboxReader;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalSchemaReader;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\RequestedPortal;
use OCA\Portaliq\Service\RequiredFieldsGuard;
use OCA\Portaliq\Service\SubmissionReceiptService;
use OCA\Portaliq\Service\VisibleFromGate;
use OCA\Portaliq\Service\WriteRefusal;
use OCP\Http\Client\IResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * endpoint-forward-draft-schema T3: once the endpoint accepts the final body
 * of an action that keeps its draft on a `draftSchema`, the draft is
 * discarded; a refused body keeps it, and an action without `draftSchema`
 * never looks one up.
 *
 * @spec openspec/changes/endpoint-forward-draft-schema/tasks.md#T3
 */
class ContributionControllerForwardDraftTest extends TestCase {
	private const SUBJECT = ['subjectRef' => 'trainer-1', 'audience' => 'praktijkopleider', 'organisation' => '', 'trust' => 'low', 'jti' => 'jti-1'];

	private const ACTION = [
		'id' => 'assess',
		'type' => 'endpoint-forward',
		'endpoint' => '/apps/learniq/api/portal/werkproces-assessments',
		'method' => 'POST',
		'fields' => ['werkprocesCode'],
		'draft' => ['retentionDays' => 30],
		'draftSchema' => ['register' => 'learniq', 'schema' => 'werkproces-assessment-draft'],
	];

	/**
	 * An accepted body discards the draft kept on the draft schema.
	 *
	 * @return void
	 */
	public function testAnAcceptedBodyDiscardsTheDraft(): void {
		$destinations = $this->createMock(originalClassName: DestinationDraftInterface::class);
		$destinations->expects($this->once())->method('discardDraft')
			->with(['register' => 'learniq', 'schema' => 'werkproces-assessment-draft'], 'trainer-1', 'd-1')
			->willReturn(true);

		$response = $this->controller(action: self::ACTION, status: 201, destinations: $destinations, found: ['id' => 'd-1'])->action('learniq', 'assess');

		$this->assertSame(expected: 201, actual: $response->getStatus());
	}//end testAnAcceptedBodyDiscardsTheDraft()

	/**
	 * A refused body keeps the draft, and so does an action without a draft schema.
	 *
	 * @return void
	 */
	public function testARefusedBodyOrAnActionWithoutADraftSchemaKeepsIt(): void {
		$destinations = $this->createMock(originalClassName: DestinationDraftInterface::class);
		$destinations->expects($this->never())->method('discardDraft');

		$refused = $this->controller(action: self::ACTION, status: 422, destinations: $destinations, found: ['id' => 'd-1'])->action('learniq', 'assess');
		$this->assertSame(expected: 422, actual: $refused->getStatus());

		$plain = array_diff_key(self::ACTION, ['draftSchema' => true]);
		$sent = $this->controller(action: $plain, status: 201, destinations: $destinations, found: ['id' => 'd-1'])->action('learniq', 'assess');
		$this->assertSame(expected: 201, actual: $sent->getStatus());
	}//end testARefusedBodyOrAnActionWithoutADraftSchemaKeepsIt()

	/**
	 * A discard that fails never changes the answer.
	 *
	 * @return void
	 */
	public function testAFailingDiscardNeverChangesTheAnswer(): void {
		$destinations = $this->createMock(originalClassName: DestinationDraftInterface::class);
		$destinations->method('discardDraft')->willThrowException(new \RuntimeException(message: 'down'));

		$response = $this->controller(action: self::ACTION, status: 201, destinations: $destinations, found: ['id' => 'd-1'])->action('learniq', 'assess');

		$this->assertSame(expected: 201, actual: $response->getStatus());
	}//end testAFailingDiscardNeverChangesTheAnswer()

	/**
	 * The controller with the action contributed and the forward answering `$status`.
	 *
	 * @param array<string, mixed>      $action       The contributed action.
	 * @param int                       $status       The endpoint's status.
	 * @param DestinationDraftInterface $destinations The draft store.
	 * @param array<string, mixed>|null $found        The live draft.
	 *
	 * @return ContributionController
	 */
	private function controller(array $action, int $status, DestinationDraftInterface $destinations, ?array $found): ContributionController {
		$params  = ['werkprocesCode' => 'WP1'];
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');
		$request->method('getParam')->willReturnCallback(
			callback: static fn (string $key, mixed $default = null) => ($params[$key] ?? $default)
		);
		$request->method('getParams')->willReturn($params);

		$registry = $this->createMock(originalClassName: PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn(
			value: ['contributions' => [['app' => 'learniq', 'collections' => [], 'actions' => [$action]]]]
		);

		$session = $this->createMock(originalClassName: PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(self::SUBJECT);

		$reader = $this->createMock(originalClassName: PortalObjectReader::class);
		$reader->method('resolveScopeValue')->willReturn('trainer-1');

		$answer = $this->createMock(originalClassName: IResponse::class);
		$answer->method('getStatusCode')->willReturn($status);
		$forwarder = $this->createMock(originalClassName: PortalActionForwarder::class);
		$forwarder->method('forward')->willReturn($answer);
		$forwarder->method('decodeBody')->willReturn(['ok' => $status < 300]);

		$drafts = $this->createMock(originalClassName: ActionDraft::class);
		$drafts->method('find')->willReturn($found);
		$drafts->method('destinationOf')->willReturn(['register' => 'learniq', 'schema' => 'werkproces-assessment-draft']);

		return new ContributionController(
			request: $request,
			registry: $registry,
			session: $session,
			reader: $reader,
			writer: $this->createMock(originalClassName: PortalObjectWriter::class),
			fileWriter: $this->createMock(originalClassName: PortalFileWriter::class),
			fileReader: $this->createMock(originalClassName: PortalFileReader::class),
			schemaReader: $this->createMock(originalClassName: PortalSchemaReader::class),
			inboxReader: $this->createMock(originalClassName: PortalInboxReader::class),
			auditHook: $this->createMock(originalClassName: PortalAuditHook::class),
			forwarder: $forwarder,
			auditor: $this->createMock(originalClassName: AuditTrailService::class),
			receiptService: $this->createMock(originalClassName: SubmissionReceiptService::class),
			notificationDispatch: $this->createMock(originalClassName: NotificationDispatchService::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			drafts: $drafts,
			destinations: $destinations,
			caseRowMarker: new CaseRowMarker(),
			createActionMatcher: new CreateActionMatcher(),
			createBody: new CreateBody(),
			inboxMessageFields: new InboxMessageFields(),
			pageChoice: new PageChoice(),
			requestedPortal: new RequestedPortal(),
			requiredFieldsGuard: new RequiredFieldsGuard(),
			rowIdentifier: new RowIdentifier(),
			themeChoice: new ThemeChoice(),
			visibleFromGate: new VisibleFromGate(),
			writeRefusal: new WriteRefusal()
		);
	}//end controller()
}//end class
