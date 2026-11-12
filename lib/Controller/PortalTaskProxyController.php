<?php

/**
 * Portaliq Portal Task Proxy Controller
 *
 * The resident-facing edge of openregister's portal task seam: three
 * bearer-guarded endpoints (`/portal/api/tasks[...]`) that resolve the portal
 * subject, hand the call to PortalTaskGateway (which mints the server-side
 * `X-Portal-Subject` assertion), and translate the seam's named refusals for
 * the SPA. The browser never talks to openregister and never sees an
 * assertion; a missing bearer is a 401 before any forward happens.
 *
 * The refusal mapping (design D-3): the seam's 404 `no-such-task`,
 * 400 `upload-constraint` and 409 `task-closed` pass through with their codes;
 * a seam 401 means OUR assertion was refused (a configuration defect, not the
 * resident's session) and becomes 503 `task-service-unavailable`; a transport
 * failure becomes 502 `task-service-unreachable`.
 *
 * A CONFIRMED completion is a submission in the WMEBV sense (art. 2:10): once
 * the seam answers 2xx the proxy records the same append-only audit fact
 * (verb `complete`) and the same ontvangstbevestiging + proof log a
 * create-action gets — the ContributionController::create() pattern. A
 * refused or failed relay records nothing, because nothing was submitted.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-task-delivery/spec.md#requirement-the-task-proxy-is-the-only-path-and-the-assertion-never-reaches-the-browser
 * @spec openspec/specs/portal-task-delivery/spec.md#requirement-mijn-taken-lists-details-and-completes-the-partys-open-tasks
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Event\PortalClientWriteEvent;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\CitizenWriteRecorder;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\PortalTaskGateway;
use OCA\Portaliq\Service\SubmissionReceiptService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Bearer-guarded proxy for the subject's portal tasks.
 *
 * @spec openspec/specs/portal-task-delivery/spec.md#requirement-the-task-proxy-is-the-only-path-and-the-assertion-never-reaches-the-browser
 */
class PortalTaskProxyController extends Controller implements PortalProtected {

	/**
	 * The audit verb recorded for a confirmed task completion.
	 */
	public const VERB_COMPLETE = 'complete';

	/**
	 * The proof-log `actionId` a task completion is logged under (WMEBV). A
	 * task has no declared manifest action to name, so a fixed id names the
	 * deed — in the `noun.verb` dialect of the notification rule keys.
	 */
	public const ACTION_COMPLETE = 'task.complete';

	/**
	 * The audit target's stand-in register. A portal task is openregister's
	 * own row, not an object in a register, so the owning app names the
	 * namespace — the same convention a forwarded action uses (its appId
	 * rides in the register slot).
	 */
	private const TASK_REGISTER = 'openregister';

	/**
	 * The audit target's stand-in schema for a portal task.
	 */
	private const TASK_SCHEMA = 'portalTask';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalSessionService $session Resolves the bearer subject (fail-closed).
	 * @param PortalTaskGateway $gateway The assertion-signed seam client.
	 * @param AuditTrailService $auditor Records the `complete` audit fact (fail-safe, never throws).
	 * @param SubmissionReceiptService $receiptService WMEBV ontvangstbevestiging + proof log (fail-safe, never throws).
	 * @param CitizenWriteRecorder $recorder Announces a citizen's task answer
	 *                                       as a portal write.
	 * @param IL10N $l10n The sentence a second answer is refused with.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionService $session,
		private readonly PortalTaskGateway $gateway,
		private readonly AuditTrailService $auditor,
		private readonly SubmissionReceiptService $receiptService,
		private readonly CitizenWriteRecorder $recorder,
		private readonly IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The authenticated party's open portal tasks.
	 *
	 * @param int $limit Page size.
	 * @param int $offset Page offset.
	 *
	 * @return JSONResponse The seam's page {results, total, limit, offset}, or a refusal.
	 *
	 * @spec openspec/specs/portal-task-delivery/spec.md#requirement-mijn-taken-lists-details-and-completes-the-partys-open-tasks
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function index(int $limit = 25, int $offset = 0): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		return $this->relay(answer: $this->gateway->listTasks(subject: $subject, limit: $limit, offset: $offset));
	}//end index()

	/**
	 * One portal task's detail, if it is the authenticated party's.
	 *
	 * @param string $uuid The task uuid.
	 *
	 * @return JSONResponse The seam's task row, or a refusal.
	 *
	 * @spec openspec/specs/portal-task-delivery/spec.md#requirement-mijn-taken-lists-details-and-completes-the-partys-open-tasks
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function show(string $uuid): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		return $this->relay(answer: $this->gateway->getTask(subject: $subject, uuid: $uuid));
	}//end show()

	/**
	 * Complete a portal task: relay comment, outcome, answers and the
	 * uploaded files multipart through the assertion-signed forward.
	 *
	 * When the seam CONFIRMS the completion (2xx) the completion is audited
	 * (verb `complete`, target the task) and acknowledged with the WMEBV
	 * receipt + proof log, exactly like a create-action (WOO-569). Both
	 * follow-ons are fail-safe by contract — they log and never throw — so
	 * neither can turn a completed task into a failed response. A refusal
	 * (4xx), a refused assertion (seam 401 → 503) or a transport failure
	 * (502) records nothing: there was no submission to acknowledge.
	 *
	 * @param string $uuid The task uuid.
	 * @param string $outcome The outcome ('' keeps the seam's default).
	 * @param string|null $comment The resident's comment.
	 *
	 * @return JSONResponse The completed task row, or a refusal.
	 *
	 * @spec openspec/specs/portal-task-delivery/spec.md#requirement-mijn-taken-lists-details-and-completes-the-partys-open-tasks
	 * @spec openspec/specs/supplier-portal/spec.md#append-only-portal-audit-trail-on-every-mutation-download-and-session-event
	 * @spec openspec/specs/supplier-portal/spec.md#automatic-ontvangstbevestiging-on-a-successful-create-action
	 * @spec openspec/specs/supplier-portal/spec.md#proof-of-receipt-log-satisfying-the-wmebv-burden-of-proof
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function complete(string $uuid, string $outcome = '', ?string $comment = null): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$run = new TaskCompletionRun(subject: $subject, uuid: $uuid);
		$run->outcome = $outcome;
		$run->comment = $comment;
		$refusal      = $this->answerRefusal(run: $run);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->relayCompletion(run: $run);
	}//end complete()

	/**
	 * The refusal when the task cannot be answered: the seam's own refusal
	 * or failure, or a task already answered.
	 *
	 * A task is answered once. The seam is asked for the task first, so a
	 * second answer is refused with a sentence instead of quietly
	 * overwriting the first one the citizen already sent. A refused answer
	 * audits and receipts nothing: no completion happened.
	 *
	 * @param TaskCompletionRun $run The answer.
	 *
	 * @return JSONResponse|null
	 */
	private function answerRefusal(TaskCompletionRun $run): ?JSONResponse {
		$existing = $this->gateway->getTask(subject: $run->subject, uuid: $run->uuid);
		if ($existing === null) {
			return $this->relay(answer: null);
		}

		if ($existing['status'] >= Http::STATUS_BAD_REQUEST) {
			return $this->relay(answer: $existing);
		}

		if ($this->isAnswered(task: $existing['body']) === true) {
			return $this->alreadyAnswered(task: $existing['body']);
		}

		return null;
	}//end answerRefusal()

	/**
	 * The refusal of a second answer, with the task as it stands.
	 *
	 * @param array<string, mixed> $task The seam's task row.
	 *
	 * @return JSONResponse
	 */
	private function alreadyAnswered(array $task): JSONResponse {
		return new JSONResponse(
			[
				'message' => $this->l10n->t('You have already answered this task.'),
				'error' => 'task-already-answered',
				'task' => $task,
			],
			Http::STATUS_CONFLICT
		);
	}//end alreadyAnswered()

	/**
	 * Relay the answer to the seam; a confirmed completion is audited and
	 * receipted, a client's answer announced.
	 *
	 * @param TaskCompletionRun $run The answer.
	 *
	 * @return JSONResponse
	 */
	private function relayCompletion(TaskCompletionRun $run): JSONResponse {
		$run->answers = $this->answers();
		$run->files   = $this->uploads();

		$answer = $this->gateway->completeTask(
			subject: $run->subject,
			uuid: $run->uuid,
			answers: $run->answers,
			comment: $run->comment,
			outcome: $run->outcome,
			files: $run->files
		);
		if ($this->isConfirmed(answer: $answer) === true) {
			$this->recordCompletion(run: $run, task: $answer['body']);
		}

		$this->announceClientAnswer(run: $run, answer: $answer);

		return $this->relay(answer: $answer);
	}//end relayCompletion()

	/**
	 * Whether the seam confirmed the completion (2xx).
	 *
	 * @param array{status: int, body: array<string, mixed>}|null $answer The seam's answer.
	 *
	 * @return bool
	 */
	private function isConfirmed(?array $answer): bool {
		return $answer !== null && $answer['status'] >= Http::STATUS_OK && $answer['status'] < Http::STATUS_MULTIPLE_CHOICES;
	}//end isConfirmed()

	/**
	 * Raise the citizen write event for a task the CLIENT audience answered.
	 * A partner or supplier answering a task of their own is not a citizen
	 * write, so it raises nothing here; `partner-tasks-in-the-portal` owns that
	 * audience's path.
	 *
	 * @param TaskCompletionRun                                   $run    The answer, with its answers relayed.
	 * @param array{status: int, body: array<string, mixed>}|null $answer The seam's answer.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-writes-on-their-own-case/spec.md
	 */
	private function announceClientAnswer(TaskCompletionRun $run, ?array $answer): void {
		if ($answer === null
			|| $answer['status'] >= Http::STATUS_BAD_REQUEST
			|| (string)($run->subject['audience'] ?? '') !== 'client'
		) {
			return;
		}

		$task = $answer['body'];
		$this->recorder->announce(
			register: (string)($task['objectRegister'] ?? ''),
			schema: (string)($task['objectSchema'] ?? ''),
			caseId: (string)($task['objectId'] ?? $run->uuid),
			act: PortalClientWriteEvent::ACT_TASK_ANSWER,
			fields: array_map(strval(...), array_keys($run->answers)),
			subject: $run->subject,
			action: ['id' => 'portal-task', 'minTrust' => ''],
			occurredAt: $this->recorder->now()
		);
	}//end announceClientAnswer()

	/**
	 * Whether the seam's task row already reads answered. The seam names the
	 * terminal state in one of a few ways depending on its age, so every one
	 * it has used is checked rather than assuming the newest.
	 *
	 * @param array<string, mixed> $task The seam's task row.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/citizen-writes-on-their-own-case/spec.md
	 */
	private function isAnswered(array $task): bool {
		return $this->hasAnsweredStamp(task: $task) === true || $this->hasAnsweredState(task: $task) === true;
	}//end isAnswered()

	/**
	 * Whether the task row carries a non-empty completion stamp.
	 *
	 * @param array<string, mixed> $task The seam's task row.
	 *
	 * @return bool
	 */
	private function hasAnsweredStamp(array $task): bool {
		foreach (['completedAt', 'completedOn', 'answeredAt'] as $stamp) {
			$value = ($task[$stamp] ?? null);
			if (is_string($value) === true && $value !== '') {
				return true;
			}
		}

		return false;
	}//end hasAnsweredStamp()

	/**
	 * Whether the task row's status names a terminal state.
	 *
	 * @param array<string, mixed> $task The seam's task row.
	 *
	 * @return bool
	 */
	private function hasAnsweredState(array $task): bool {
		foreach (['status', 'state', 'taskStatus'] as $key) {
			$value = ($task[$key] ?? null);
			if (is_string($value) === true
				&& in_array(strtolower($value), ['completed', 'done', 'answered', 'afgerond'], true) === true
			) {
				return true;
			}
		}

		return false;
	}//end hasAnsweredState()

	/**
	 * Resolve the subject from the bearer (fail-closed). PortalAuthMiddleware
	 * has already gated protected access; this re-derives the subject reliably
	 * for the handler, the ContributionController pattern.
	 *
	 * @return array<string, mixed>|null
	 */
	private function subject(): ?array {
		return $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
	}//end subject()

	/**
	 * Audit and acknowledge a CONFIRMED completion — the
	 * ContributionController::create() pattern: one proof record in
	 * OpenRegister's audit trail (verb `complete`, target the task; a fact,
	 * never payload) and the WMEBV ontvangstbevestiging + proof log through
	 * SubmissionReceiptService. Fired only after the seam answered 2xx, so
	 * the audited/acknowledged deed has already happened.
	 *
	 * @param TaskCompletionRun    $run  The confirmed answer.
	 * @param array<string, mixed> $task The seam's completed task row.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#append-only-portal-audit-trail-on-every-mutation-download-and-session-event
	 * @spec openspec/specs/supplier-portal/spec.md#automatic-ontvangstbevestiging-on-a-successful-create-action
	 * @spec openspec/specs/supplier-portal/spec.md#proof-of-receipt-log-satisfying-the-wmebv-burden-of-proof
	 * @spec openspec/changes/submission-records-its-action-title/specs/supplier-portal/spec.md#requirement-a-proof-log-record-names-the-submitted-action-as-the-resident-read-it
	 */
	private function recordCompletion(TaskCompletionRun $run, array $task): void {
		$run->taskUuid = (string)($task['uuid'] ?? $run->uuid);
		if ($run->taskUuid === '') {
			$run->taskUuid = $run->uuid;
		}

		$this->auditor->record(
			verb: self::VERB_COMPLETE,
			subjectRef: (string)($run->subject['subjectRef'] ?? ''),
			organisation: (string)($run->subject['organisation'] ?? ''),
			register: self::TASK_REGISTER,
			schema: self::TASK_SCHEMA,
			id: $run->taskUuid,
			jti: (string)($run->subject['jti'] ?? '')
		);

		$this->receiptCompletion(run: $run, task: $task);
	}//end recordCompletion()

	/**
	 * The WMEBV ontvangstbevestiging and proof log of a confirmed completion,
	 * named by the task's title.
	 *
	 * @param TaskCompletionRun    $run  The confirmed answer, with its completed uuid.
	 * @param array<string, mixed> $task The seam's completed task row.
	 *
	 * @return void
	 */
	private function receiptCompletion(TaskCompletionRun $run, array $task): void {
		$this->receiptService->record(
			subjectRef: (string)($run->subject['subjectRef'] ?? ''),
			organisation: (string)($run->subject['organisation'] ?? ''),
			appId: Application::APP_ID,
			actionId: self::ACTION_COMPLETE,
			whitelistedData: $this->completionCopy(run: $run, task: $task),
			audience: (string)($run->subject['audience'] ?? ''),
			actionTitle: ($task['displayTitle'] ?? $task['title'] ?? '')
		);
	}//end receiptCompletion()

	/**
	 * The WMEBV copy of the completion for the receipt and proof log.
	 *
	 * @param TaskCompletionRun    $run  The confirmed answer, with its completed uuid.
	 * @param array<string, mixed> $task The seam's completed task row.
	 *
	 * @return array<string, mixed>
	 */
	private function completionCopy(TaskCompletionRun $run, array $task): array {
		return $this->receiptService->taskCompletionCopy(
			taskUuid: $run->taskUuid,
			task: $task,
			answers: $run->answers,
			comment: $run->comment,
			outcome: $run->outcome,
			files: $run->files
		);
	}//end completionCopy()


	/**
	 * Translate the gateway's answer for the resident (design D-3).
	 *
	 * @param array{status: int, body: array<string, mixed>}|null $answer The
	 *        relayed seam answer, or null on transport failure.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/portal-task-delivery/spec.md#requirement-the-task-proxy-is-the-only-path-and-the-assertion-never-reaches-the-browser
	 */
	private function relay(?array $answer): JSONResponse {
		if ($answer === null) {
			return new JSONResponse(
				['error' => 'The task service could not be reached.', 'code' => 'task-service-unreachable'],
				Http::STATUS_BAD_GATEWAY
			);
		}

		// A seam 401 refused OUR assertion — a server configuration defect
		// (secret mismatch / unconfigured verifier), never the resident's
		// session. Relaying 401 would read as "log in again", which is wrong
		// and unfixable for the resident.
		if ($answer['status'] === Http::STATUS_UNAUTHORIZED) {
			return new JSONResponse(
				['error' => 'The task service is not available right now.', 'code' => 'task-service-unavailable'],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		return new JSONResponse($answer['body'], $answer['status']);
	}//end relay()

	/**
	 * The submitted answers: a JSON object string (multipart) or an array.
	 * Anything else reads as no answers.
	 *
	 * @return array<string, mixed>
	 */
	private function answers(): array {
		$raw = $this->request->getParam('answers');
		if (is_array($raw) === true) {
			return $raw;
		}

		if (is_string($raw) === true && $raw !== '') {
			$decoded = json_decode($raw, true);
			if (is_array($decoded) === true) {
				return $decoded;
			}
		}

		return [];
	}//end answers()

	/**
	 * The uploaded files, normalised to a list of {name, type, tmp_name, size}
	 * entries whichever way PHP shaped `$_FILES` (`file` single, `files[]`
	 * multiple).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function uploads(): array {
		$uploads = [];

		$single = $this->request->getUploadedFile('file');
		if (is_array($single) === true && isset($single['tmp_name']) === true && is_string($single['tmp_name']) === true) {
			$uploads[] = $single;
		}

		return array_merge($uploads, $this->manyUploads(many: $this->request->getUploadedFile('files')));
	}//end uploads()

	/**
	 * The uploads of the `files` field: PHP's multi-upload shape, parallel
	 * arrays per field, one upload per entry; a single upload as it came.
	 *
	 * @param mixed $many The uploaded `files` field.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function manyUploads(mixed $many): array {
		if (is_array($many) === false || isset($many['tmp_name']) === false) {
			return [];
		}

		if (is_array($many['tmp_name']) === false) {
			return $this->singleUpload(upload: $many);
		}

		$uploads = [];
		foreach (array_keys($many['tmp_name']) as $key) {
			$uploads[] = [
				'name' => ($many['name'][$key] ?? 'upload'),
				'type' => ($many['type'][$key] ?? 'application/octet-stream'),
				'tmp_name' => ($many['tmp_name'][$key] ?? ''),
				'size' => ($many['size'][$key] ?? 0),
			];
		}

		return $uploads;
	}//end manyUploads()

	/**
	 * A single upload of the `files` field, when it names its temp file.
	 *
	 * @param array<string, mixed> $upload The uploaded field.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function singleUpload(array $upload): array {
		if (is_string($upload['tmp_name']) === true) {
			return [$upload];
		}

		return [];
	}//end singleUpload()
}//end class
