<?php

/**
 * Tests for the resident-facing task proxy and the contributions announcement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\ContributionController;
use OCA\Portaliq\Controller\PortalTaskProxyController;
use OCA\Portaliq\Service\AuditTrailService;
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
use OCA\Portaliq\Service\PortalTaskGateway;
use OCA\Portaliq\Service\SubmissionReceiptService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The proxy's fail-closed edge and refusal mapping (design D-3): no bearer
 * means 401 with ZERO forwards (the mutation check — an unauthenticated or
 * unmatched party sees nothing); the seam's named refusals pass through with
 * their codes; a seam 401 (our assertion refused: a configuration defect)
 * becomes 503; a transport failure becomes 502. Plus the contributions
 * announcement: `tasks.enabled` for authenticated subjects only, and never on
 * the anonymous aggregate.
 *
 * And the completion follow-ons (WOO-569): a seam-confirmed completion (2xx)
 * is audited (verb `complete`, target the task) and acknowledged with the
 * WMEBV receipt + proof log carrying a copy of the submitted data — never
 * file content; a refused, unavailable or unreachable relay records neither.
 *
 * @covers \OCA\Portaliq\Controller\PortalTaskProxyController
 * @covers \OCA\Portaliq\Controller\ContributionController
 *
 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-task-proxy-is-the-only-path-and-the-assertion-never-reaches-the-browser
 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-mijn-taken-lists-details-and-completes-the-partys-open-tasks
 * @spec openspec/specs/supplier-portal/spec.md#append-only-portal-audit-trail-on-every-mutation-download-and-session-event
 * @spec openspec/specs/supplier-portal/spec.md#automatic-ontvangstbevestiging-on-a-successful-create-action
 * @spec openspec/specs/supplier-portal/spec.md#proof-of-receipt-log-satisfying-the-wmebv-burden-of-proof
 */
class PortalTaskProxyControllerTest extends TestCase {
	private const SUBJECT = ['subjectRef' => 's1', 'audience' => 'client', 'organisation' => 'org-1', 'trust' => 'substantial', 'jti' => 'jti-1'];

	/**
	 * No bearer: 401, and the gateway is NEVER consulted — an
	 * unauthenticated caller triggers no forward and learns nothing.
	 */
	public function testNoBearerIsRefusedWithZeroForwards(): void {
		$gateway = $this->createMock(PortalTaskGateway::class);
		$gateway->expects($this->never())->method('listTasks');
		$gateway->expects($this->never())->method('getTask');
		$gateway->expects($this->never())->method('completeTask');

		$auditor = $this->createMock(AuditTrailService::class);
		$auditor->expects($this->never())->method('record');
		$receiptService = $this->createMock(SubmissionReceiptService::class);
		$receiptService->expects($this->never())->method('record');

		$controller = $this->controller(subject: null, gateway: $gateway, auditor: $auditor, receiptService: $receiptService);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->index()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->show('t-1')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->complete('t-1')->getStatus());
	}//end testNoBearerIsRefusedWithZeroForwards()

	/**
	 * A successful list is relayed as-is.
	 */
	public function testAListIsRelayed(): void {
		$gateway = $this->createMock(PortalTaskGateway::class);
		$gateway->method('listTasks')->willReturn(['status' => 200, 'body' => ['results' => [['uuid' => 't-1']], 'total' => 1]]);

		$response = $this->controller(subject: self::SUBJECT, gateway: $gateway)->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['total']);
	}//end testAListIsRelayed()

	/**
	 * The seam's named refusals pass through with their status and code:
	 * an unmatched party keeps the unrevealing 404, a violated upload
	 * constraint stays 400, a terminal task stays 409.
	 */
	public function testNamedRefusalsPassThrough(): void {
		$cases = [
			[404, 'no-such-task'],
			[400, 'upload-constraint'],
			[409, 'task-closed'],
		];
		foreach ($cases as [$status, $code]) {
			$gateway = $this->createMock(PortalTaskGateway::class);
			$gateway->method('getTask')->willReturn(['status' => $status, 'body' => ['error' => 'x', 'code' => $code]]);

			$response = $this->controller(subject: self::SUBJECT, gateway: $gateway)->show('t-1');

			$this->assertSame($status, $response->getStatus());
			$this->assertSame($code, $response->getData()['code']);
		}
	}//end testNamedRefusalsPassThrough()

	/**
	 * A seam 401 refused OUR assertion — a configuration defect, not the
	 * resident's session — and becomes 503 `task-service-unavailable`, never
	 * a relayed "log in again".
	 */
	public function testASeamUnauthorizedBecomesUnavailable(): void {
		$gateway = $this->createMock(PortalTaskGateway::class);
		$gateway->method('listTasks')->willReturn(['status' => 401, 'body' => ['error' => 'No acting portal subject', 'code' => 'portal-subject-invalid']]);

		$response = $this->controller(subject: self::SUBJECT, gateway: $gateway)->index();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame('task-service-unavailable', $response->getData()['code']);
	}//end testASeamUnauthorizedBecomesUnavailable()

	/**
	 * Transport failure (gateway null) becomes 502 `task-service-unreachable`.
	 */
	public function testTransportFailureBecomesBadGateway(): void {
		$gateway = $this->createMock(PortalTaskGateway::class);
		$gateway->method('completeTask')->willReturn(null);

		$response = $this->controller(subject: self::SUBJECT, gateway: $gateway)->complete('t-1');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame('task-service-unreachable', $response->getData()['code']);
	}//end testTransportFailureBecomesBadGateway()

	/**
	 * Multipart answers (a JSON object string), the single `file` field and
	 * PHP's parallel-array `files[]` shape are all normalised before the
	 * forward: the gateway sees one decoded answers map and a flat file list.
	 */
	public function testAnswersAndUploadsAreNormalisedForTheForward(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($key === 'answers' ? '{"veld": "waarde"}' : $default)
		);
		$request->method('getUploadedFile')->willReturnMap([
			['file', ['name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/a', 'size' => 1]],
			['files', [
				'name' => ['b.pdf', 'c.png'],
				'type' => ['application/pdf', 'image/png'],
				'tmp_name' => ['/tmp/b', '/tmp/c'],
				'size' => [2, 3],
			]],
		]);

		$captured = [];
		$gateway = $this->createMock(PortalTaskGateway::class);
		$gateway->expects($this->once())->method('completeTask')->willReturnCallback(
			function (array $subject, string $uuid, array $answers, ?string $comment, string $outcome, array $files) use (&$captured) {
				$captured = ['answers' => $answers, 'files' => $files, 'outcome' => $outcome, 'comment' => $comment];

				return ['status' => 200, 'body' => ['uuid' => $uuid]];
			}
		);

		$response = $this->controllerWithRequest(request: $request, subject: self::SUBJECT, gateway: $gateway)
			->complete('t-1', 'submitted', 'klaar');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['veld' => 'waarde'], $captured['answers']);
		$this->assertSame('submitted', $captured['outcome']);
		$this->assertSame('klaar', $captured['comment']);
		$this->assertSame(['a.pdf', 'b.pdf', 'c.png'], array_column($captured['files'], 'name'));
	}//end testAnswersAndUploadsAreNormalisedForTheForward()

	/**
	 * An already-decoded answers array passes through, a single-entry `files`
	 * field (string tmp_name) is accepted, and malformed answers JSON reads as
	 * no answers at all — never a crash, never a guessed payload.
	 */
	public function testArrayAnswersAndMalformedJsonAreHandled(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($key === 'answers' ? ['al' => 'array'] : $default)
		);
		$request->method('getUploadedFile')->willReturnMap([
			['file', []],
			['files', ['name' => 'solo.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/solo', 'size' => 5]],
		]);

		$captured = [];
		$gateway = $this->createMock(PortalTaskGateway::class);
		$gateway->method('completeTask')->willReturnCallback(
			function (array $subject, string $uuid, array $answers, ?string $comment, string $outcome, array $files) use (&$captured) {
				$captured = ['answers' => $answers, 'files' => $files];

				return ['status' => 200, 'body' => []];
			}
		);

		$this->controllerWithRequest(request: $request, subject: self::SUBJECT, gateway: $gateway)->complete('t-1');
		$this->assertSame(['al' => 'array'], $captured['answers']);
		$this->assertSame(['solo.pdf'], array_column($captured['files'], 'name'));

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($key === 'answers' ? '{not-json' : $default)
		);
		$request->method('getUploadedFile')->willReturn([]);

		$this->controllerWithRequest(request: $request, subject: self::SUBJECT, gateway: $gateway)->complete('t-1');
		$this->assertSame([], $captured['answers']);
		$this->assertSame([], $captured['files']);
	}//end testArrayAnswersAndMalformedJsonAreHandled()

	/**
	 * WOO-569: a seam-CONFIRMED completion is audited and acknowledged like a
	 * create-action — one `complete` audit fact naming the task (openregister
	 * / portalTask / uuid, with the session jti; no payload), and one WMEBV
	 * receipt + proof log under portaliq / `task.complete` whose data copy
	 * carries the task, the outcome the seam recorded (not the empty
	 * requested one), the comment, the answers and the upload NAMES only.
	 */
	public function testASuccessfulCompletionIsAuditedAndReceipted(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($key === 'answers' ? '{"veld": "waarde"}' : $default)
		);
		$request->method('getUploadedFile')->willReturnMap([
			['file', ['name' => 'bewijs.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/php-upload-a', 'size' => 1]],
			['files', []],
		]);

		$gateway = $this->createMock(PortalTaskGateway::class);
		$gateway->method('completeTask')->willReturn(
			['status' => 200, 'body' => ['uuid' => 't-1', 'title' => 'Stuur uw bewijsstuk', 'state' => 'completed', 'outcome' => 'submitted']]
		);

		$audited = [];
		$auditor = $this->createMock(AuditTrailService::class);
		$auditor->expects($this->once())->method('record')->willReturnCallback(
			function (string $verb, string $subjectRef, string $organisation, string $register, string $schema, string $id, string $jti = '', string $appId = 'portaliq') use (&$audited) {
				$audited = compact('verb', 'subjectRef', 'organisation', 'register', 'schema', 'id', 'jti', 'appId');
			}
		);

		$received = [];
		$receiptService = $this->createMock(SubmissionReceiptService::class);
		$receiptService->expects($this->once())->method('record')->willReturnCallback(
			function (string $subjectRef, string $organisation, string $appId, string $actionId, array $whitelistedData, string $audience = '') use (&$received) {
				$received = compact('subjectRef', 'organisation', 'appId', 'actionId', 'whitelistedData', 'audience');
			}
		);

		$response = $this->controllerWithRequest(request: $request, subject: self::SUBJECT, gateway: $gateway, auditor: $auditor, receiptService: $receiptService)
			->complete('t-1', '', 'klaar');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('completed', $response->getData()['state']);

		$this->assertSame(
			['verb' => 'complete', 'subjectRef' => 's1', 'organisation' => 'org-1', 'register' => 'openregister', 'schema' => 'portalTask', 'id' => 't-1', 'jti' => 'jti-1', 'appId' => 'portaliq'],
			$audited
		);

		$this->assertSame('s1', $received['subjectRef']);
		$this->assertSame('org-1', $received['organisation']);
		$this->assertSame('portaliq', $received['appId']);
		$this->assertSame('task.complete', $received['actionId']);
		$this->assertSame('client', $received['audience']);
		$this->assertSame(
			[
				'taskUuid' => 't-1',
				'title' => 'Stuur uw bewijsstuk',
				'outcome' => 'submitted',
				'comment' => 'klaar',
				'answers' => ['veld' => 'waarde'],
				'files' => ['bewijs.pdf'],
			],
			$received['whitelistedData']
		);
		// Privacy: the copy names the upload, never its temp path or content.
		$this->assertStringNotContainsString('/tmp/', (string)json_encode($received['whitelistedData']));
	}//end testASuccessfulCompletionIsAuditedAndReceipted()

	/**
	 * A seam row without an outcome (or uuid) falls back to what the resident
	 * requested and addressed, and no upload means an empty file list — the
	 * copy is always well-formed.
	 */
	public function testTheSubmissionCopyFallsBackToTheRequestedOutcomeAndUuid(): void {
		$gateway = $this->createMock(PortalTaskGateway::class);
		$gateway->method('completeTask')->willReturn(['status' => 200, 'body' => []]);

		$received = [];
		$receiptService = $this->createMock(SubmissionReceiptService::class);
		$receiptService->expects($this->once())->method('record')->willReturnCallback(
			function (string $subjectRef, string $organisation, string $appId, string $actionId, array $whitelistedData) use (&$received) {
				$received = $whitelistedData;
			}
		);

		$auditor = $this->createMock(AuditTrailService::class);
		$auditor->expects($this->once())->method('record')->with('complete', 's1', 'org-1', 'openregister', 'portalTask', 't-9', 'jti-1');

		$this->controller(subject: self::SUBJECT, gateway: $gateway, auditor: $auditor, receiptService: $receiptService)
			->complete('t-9', 'rejected');

		$this->assertSame('t-9', $received['taskUuid']);
		$this->assertSame('rejected', $received['outcome']);
		$this->assertSame('', $received['comment']);
		$this->assertSame([], $received['answers']);
		$this->assertSame([], $received['files']);
	}//end testTheSubmissionCopyFallsBackToTheRequestedOutcomeAndUuid()

	/**
	 * WOO-569, the negative path: a refused completion (400 upload-constraint,
	 * 404 no-such-task, 409 task-closed), a seam 5xx, a refused assertion
	 * (seam 401 → 503) and a transport failure (null → 502) each record
	 * NEITHER an audit fact NOR a receipt — nothing was submitted, so there
	 * is nothing to acknowledge — and the D-3 mapping is unchanged.
	 */
	public function testARefusedOrFailedCompletionRecordsNothing(): void {
		$cases = [
			[['status' => 400, 'body' => ['error' => 'x', 'code' => 'upload-constraint']], Http::STATUS_BAD_REQUEST],
			[['status' => 404, 'body' => ['error' => 'x', 'code' => 'no-such-task']], Http::STATUS_NOT_FOUND],
			[['status' => 409, 'body' => ['error' => 'x', 'code' => 'task-closed']], Http::STATUS_CONFLICT],
			[['status' => 500, 'body' => ['error' => 'boom']], Http::STATUS_INTERNAL_SERVER_ERROR],
			[['status' => 401, 'body' => ['error' => 'No acting portal subject']], Http::STATUS_SERVICE_UNAVAILABLE],
			[null, Http::STATUS_BAD_GATEWAY],
		];
		foreach ($cases as [$answer, $expectedStatus]) {
			$gateway = $this->createMock(PortalTaskGateway::class);
			$gateway->method('completeTask')->willReturn($answer);

			$auditor = $this->createMock(AuditTrailService::class);
			$auditor->expects($this->never())->method('record');
			$receiptService = $this->createMock(SubmissionReceiptService::class);
			$receiptService->expects($this->never())->method('record');

			$response = $this->controller(subject: self::SUBJECT, gateway: $gateway, auditor: $auditor, receiptService: $receiptService)
				->complete('t-1', '', 'klaar');

			$this->assertSame($expectedStatus, $response->getStatus());
		}
	}//end testARefusedOrFailedCompletionRecordsNothing()

	/**
	 * A task detail is relayed untouched on success.
	 */
	public function testADetailIsRelayed(): void {
		$gateway = $this->createMock(PortalTaskGateway::class);
		$gateway->method('getTask')->willReturn(['status' => 200, 'body' => ['uuid' => 't-1', 'title' => 'Stuur uw bewijsstuk']]);

		$response = $this->controller(subject: self::SUBJECT, gateway: $gateway)->show('t-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('t-1', $response->getData()['uuid']);
	}//end testADetailIsRelayed()

	/**
	 * An authenticated contributions aggregate announces the tasks surface
	 * when (and only when) the seam is available.
	 */
	public function testContributionsAnnounceTasksForAuthenticatedSubjects(): void {
		$gateway = $this->createMock(PortalTaskGateway::class);
		$gateway->method('isAvailable')->willReturn(true);

		$response = $this->contributionController(subject: self::SUBJECT, gateway: $gateway)->index();

		$this->assertTrue($response->getData()['tasks']['enabled']);
	}//end testContributionsAnnounceTasksForAuthenticatedSubjects()

	/**
	 * An unavailable seam (openregister absent / secret unconfigured) is
	 * announced as disabled, so the SPA never shows a dead "Mijn taken".
	 */
	public function testAnUnavailableSeamIsAnnouncedDisabled(): void {
		$gateway = $this->createMock(PortalTaskGateway::class);
		$gateway->method('isAvailable')->willReturn(false);

		$response = $this->contributionController(subject: self::SUBJECT, gateway: $gateway)->index();

		$this->assertFalse($response->getData()['tasks']['enabled']);
	}//end testAnUnavailableSeamIsAnnouncedDisabled()

	/**
	 * The ANONYMOUS aggregate never announces tasks — an unauthenticated
	 * visitor sees no task surface at all (mutation check).
	 */
	public function testTheAnonymousAggregateNeverAnnouncesTasks(): void {
		$gateway = $this->createMock(PortalTaskGateway::class);
		$gateway->method('isAvailable')->willReturn(true);

		$response = $this->contributionController(subject: null, gateway: $gateway)->index();

		$this->assertArrayNotHasKey('tasks', $response->getData());
	}//end testTheAnonymousAggregateNeverAnnouncesTasks()

	/**
	 * Build the proxy controller around a session outcome and a gateway.
	 *
	 * @param array<string, mixed>|null $subject The resolved subject, or null (no bearer).
	 * @param PortalTaskGateway $gateway The gateway mock.
	 * @param AuditTrailService|null $auditor The audit mock (an inert one when null).
	 * @param SubmissionReceiptService|null $receiptService The receipt mock (an inert one when null).
	 */
	private function controller(
		?array $subject,
		PortalTaskGateway $gateway,
		?AuditTrailService $auditor = null,
		?SubmissionReceiptService $receiptService = null,
	): PortalTaskProxyController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');
		$request->method('getParam')->willReturn(null);
		$request->method('getUploadedFile')->willReturn([]);

		return $this->controllerWithRequest(request: $request, subject: $subject, gateway: $gateway, auditor: $auditor, receiptService: $receiptService);
	}//end controller()

	/**
	 * Build the proxy controller around a fully prepared request mock.
	 *
	 * @param IRequest $request The prepared request.
	 * @param array<string, mixed>|null $subject The resolved subject, or null.
	 * @param PortalTaskGateway $gateway The gateway mock.
	 * @param AuditTrailService|null $auditor The audit mock (an inert one when null).
	 * @param SubmissionReceiptService|null $receiptService The receipt mock (an inert one when null).
	 */
	private function controllerWithRequest(
		IRequest $request,
		?array $subject,
		PortalTaskGateway $gateway,
		?AuditTrailService $auditor = null,
		?SubmissionReceiptService $receiptService = null,
	): PortalTaskProxyController {
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);

		return new PortalTaskProxyController(
			$request,
			$session,
			$gateway,
			($auditor ?? $this->createMock(AuditTrailService::class)),
			($receiptService ?? $this->createMock(SubmissionReceiptService::class))
		);
	}//end controllerWithRequest()

	/**
	 * Build a ContributionController whose registry serves a minimal
	 * aggregate, wired to the task gateway under test.
	 *
	 * @param array<string, mixed>|null $subject The resolved subject, or null (anonymous).
	 * @param PortalTaskGateway $gateway The gateway mock.
	 */
	private function contributionController(?array $subject, PortalTaskGateway $gateway): ContributionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');

		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);

		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn(['contributions' => []]);
		$registry->method('aggregateAnonymous')->willReturn(['contributions' => []]);

		$inboxReader = $this->createMock(PortalInboxReader::class);
		$inboxReader->method('unreadCount')->willReturn(0);

		return new ContributionController(
			$request,
			$registry,
			$session,
			$this->createMock(PortalObjectReader::class),
			$this->createMock(PortalObjectWriter::class),
			$this->createMock(PortalFileWriter::class),
			$this->createMock(PortalFileReader::class),
			$this->createMock(PortalSchemaReader::class),
			$inboxReader,
			$this->createMock(PortalAuditHook::class),
			$this->createMock(PortalActionForwarder::class),
			$this->createMock(AuditTrailService::class),
			$this->createMock(SubmissionReceiptService::class),
			$this->createMock(NotificationDispatchService::class),
			$this->createMock(LoggerInterface::class),
			$gateway
		);
	}//end contributionController()
}//end class
