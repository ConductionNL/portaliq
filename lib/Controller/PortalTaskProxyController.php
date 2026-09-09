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
 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-task-proxy-is-the-only-path-and-the-assertion-never-reaches-the-browser
 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-mijn-taken-lists-details-and-completes-the-partys-open-tasks
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\PortalTaskGateway;
use OCA\Portaliq\Service\SubmissionReceiptService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Bearer-guarded proxy for the subject's portal tasks.
 *
 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-task-proxy-is-the-only-path-and-the-assertion-never-reaches-the-browser
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
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionService $session,
		private readonly PortalTaskGateway $gateway,
		private readonly AuditTrailService $auditor,
		private readonly SubmissionReceiptService $receiptService,
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
	 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-mijn-taken-lists-details-and-completes-the-partys-open-tasks
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
	 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-mijn-taken-lists-details-and-completes-the-partys-open-tasks
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
	 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-mijn-taken-lists-details-and-completes-the-partys-open-tasks
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

		$answers = $this->answers();
		$files = $this->uploads();

		$answer = $this->gateway->completeTask(
			subject: $subject,
			uuid: $uuid,
			answers: $answers,
			comment: $comment,
			outcome: $outcome,
			files: $files
		);

		if ($answer !== null && $answer['status'] >= Http::STATUS_OK && $answer['status'] < Http::STATUS_MULTIPLE_CHOICES) {
			$this->recordCompletion(
				subject: $subject,
				uuid: $uuid,
				task: $answer['body'],
				answers: $answers,
				comment: $comment,
				outcome: $outcome,
				files: $files
			);
		}

		return $this->relay(answer: $answer);
	}//end complete()

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
	 * ContributionController::create() pattern: one append-only
	 * `portalAuditEntry` (verb `complete`, target the task; a fact, never
	 * payload) and the WMEBV ontvangstbevestiging + proof log through
	 * SubmissionReceiptService. Fired only after the seam answered 2xx, so
	 * the audited/acknowledged deed has already happened.
	 *
	 * @param array<string, mixed> $subject The resolved bearer subject.
	 * @param string $uuid The task uuid the resident addressed.
	 * @param array<string, mixed> $task The seam's completed task row.
	 * @param array<string, mixed> $answers The submitted answers.
	 * @param string|null $comment The resident's comment.
	 * @param string $outcome The requested outcome ('' = the seam's default).
	 * @param array<int, array<string, mixed>> $files The relayed uploads.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#append-only-portal-audit-trail-on-every-mutation-download-and-session-event
	 * @spec openspec/specs/supplier-portal/spec.md#automatic-ontvangstbevestiging-on-a-successful-create-action
	 * @spec openspec/specs/supplier-portal/spec.md#proof-of-receipt-log-satisfying-the-wmebv-burden-of-proof
	 */
	private function recordCompletion(
		array $subject,
		string $uuid,
		array $task,
		array $answers,
		?string $comment,
		string $outcome,
		array $files,
	): void {
		$subjectRef = (string)($subject['subjectRef'] ?? '');
		$organisation = (string)($subject['organisation'] ?? '');
		$taskUuid = (string)($task['uuid'] ?? $uuid);
		if ($taskUuid === '') {
			$taskUuid = $uuid;
		}

		$this->auditor->record(
			verb: self::VERB_COMPLETE,
			subjectRef: $subjectRef,
			organisation: $organisation,
			register: self::TASK_REGISTER,
			schema: self::TASK_SCHEMA,
			id: $taskUuid,
			jti: (string)($subject['jti'] ?? '')
		);

		$this->receiptService->record(
			subjectRef: $subjectRef,
			organisation: $organisation,
			appId: Application::APP_ID,
			actionId: self::ACTION_COMPLETE,
			whitelistedData: $this->submissionCopy(
				taskUuid: $taskUuid,
				task: $task,
				answers: $answers,
				comment: $comment,
				outcome: $outcome,
				files: $files
			),
			audience: (string)($subject['audience'] ?? '')
		);
	}//end recordCompletion()

	/**
	 * The WMEBV "copy of the submitted data" for a completion — the role the
	 * whitelisted field map plays for a create. It carries what the resident
	 * submitted (answers, comment), the outcome the seam RECORDED (falling
	 * back to the requested one), the task it answered and the NAMES of the
	 * uploads. Never file content, never a temp path: this copy lands in the
	 * resident's receipt (`dataCopy`) and in the proof log (`payloadCopy`).
	 *
	 * @param string $taskUuid The completed task's uuid.
	 * @param array<string, mixed> $task The seam's completed task row.
	 * @param array<string, mixed> $answers The submitted answers.
	 * @param string|null $comment The resident's comment.
	 * @param string $outcome The requested outcome.
	 * @param array<int, array<string, mixed>> $files The relayed uploads.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#proof-of-receipt-log-satisfying-the-wmebv-burden-of-proof
	 */
	private function submissionCopy(
		string $taskUuid,
		array $task,
		array $answers,
		?string $comment,
		string $outcome,
		array $files,
	): array {
		$names = [];
		foreach ($files as $file) {
			$names[] = (string)($file['name'] ?? 'upload');
		}

		$recorded = (string)($task['outcome'] ?? '');
		if ($recorded === '') {
			$recorded = $outcome;
		}

		return [
			'taskUuid' => $taskUuid,
			'title' => (string)($task['title'] ?? ''),
			'outcome' => $recorded,
			'comment' => (string)($comment ?? ''),
			'answers' => $answers,
			'files' => $names,
		];
	}//end submissionCopy()

	/**
	 * Translate the gateway's answer for the resident (design D-3).
	 *
	 * @param array{status: int, body: array<string, mixed>}|null $answer The
	 *        relayed seam answer, or null on transport failure.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-task-proxy-is-the-only-path-and-the-assertion-never-reaches-the-browser
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

		$many = $this->request->getUploadedFile('files');
		if (is_array($many) === true && isset($many['tmp_name']) === true) {
			if (is_array($many['tmp_name']) === true) {
				// PHP's multi-upload shape: parallel arrays per field.
				foreach (array_keys($many['tmp_name']) as $key) {
					$uploads[] = [
						'name' => ($many['name'][$key] ?? 'upload'),
						'type' => ($many['type'][$key] ?? 'application/octet-stream'),
						'tmp_name' => ($many['tmp_name'][$key] ?? ''),
						'size' => ($many['size'][$key] ?? 0),
					];
				}
			} elseif (is_string($many['tmp_name']) === true) {
				$uploads[] = $many;
			}
		}

		return $uploads;
	}//end uploads()
}//end class
