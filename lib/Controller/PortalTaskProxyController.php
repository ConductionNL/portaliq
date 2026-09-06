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
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\PortalTaskGateway;
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
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalSessionService $session Resolves the bearer subject (fail-closed).
	 * @param PortalTaskGateway $gateway The assertion-signed seam client.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionService $session,
		private readonly PortalTaskGateway $gateway,
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
	 * @param string $uuid The task uuid.
	 * @param string $outcome The outcome ('' keeps the seam's default).
	 * @param string|null $comment The resident's comment.
	 *
	 * @return JSONResponse The completed task row, or a refusal.
	 *
	 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-mijn-taken-lists-details-and-completes-the-partys-open-tasks
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function complete(string $uuid, string $outcome = '', ?string $comment = null): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		return $this->relay(
			answer: $this->gateway->completeTask(
				subject: $subject,
				uuid: $uuid,
				answers: $this->answers(),
				comment: $comment,
				outcome: $outcome,
				files: $this->uploads()
			)
		);
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
