<?php

/**
 * Portaliq Portal Task Gateway
 *
 * The server-to-server client for openregister's portal task seam
 * (openregister `feature/flow-portal-task`): subject-scoped list/detail reads
 * and the multipart completion, each authorised by a short-lived signed
 * `X-Portal-Subject` assertion minted HERE, per request, from the resolved
 * bearer subject — the resident's browser never holds the assertion and never
 * calls openregister directly (no CORS surface). The transport posture is
 * PortalActionForwarder's: instance-local absolute URL, the client bearer is
 * never forwarded, non-2xx answers are relayed rather than thrown, transport
 * failure degrades to null and the caller answers 502.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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

namespace OCA\Portaliq\Service;

use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Forwards portal task reads and completions to openregister, assertion-signed.
 *
 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-task-proxy-is-the-only-path-and-the-assertion-never-reaches-the-browser
 */
class PortalTaskGateway {
	/**
	 * The seam's base path on the co-installed openregister.
	 */
	private const TASKS_PATH = '/apps/openregister/api/portal-tasks';

	/**
	 * Timeout (seconds) for a task forward. Completion carries uploads, so it
	 * gets more room than the 10s action forward.
	 */
	private const FORWARD_TIMEOUT = 30;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService HTTP client for the server-to-server forward.
	 * @param IURLGenerator $urlGenerator Resolves the instance-local seam URL.
	 * @param PortalSessionService $session Mints the signed `X-Portal-Subject` assertion.
	 * @param IAppManager $appManager Answers whether openregister is installed at all.
	 * @param LoggerInterface $logger Where transport failures are reported.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IURLGenerator $urlGenerator,
		private readonly PortalSessionService $session,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the task seam can be reached at all: openregister is installed
	 * and the dedicated signing secret the assertion needs is configured.
	 * Drives the `tasks: {enabled}` announcement on the contributions
	 * aggregate — the SPA never shows a "Mijn taken" entry that can only 503.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-mijn-taken-lists-details-and-completes-the-partys-open-tasks
	 */
	public function isAvailable(): bool {
		return $this->appManager->isInstalled('openregister') === true && $this->session->isConfigured() === true;
	}//end isAvailable()

	/**
	 * The subject's open portal tasks, paged.
	 *
	 * @param array<string, mixed> $subject The resolved bearer subject.
	 * @param int $limit Page size.
	 * @param int $offset Page offset.
	 *
	 * @return array{status: int, body: array<string, mixed>}|null The relayed
	 *         answer, or null on transport failure / an unmintable assertion.
	 *
	 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-task-proxy-is-the-only-path-and-the-assertion-never-reaches-the-browser
	 */
	public function listTasks(array $subject, int $limit = 25, int $offset = 0): ?array {
		$query = '?limit=' . max(1, $limit) . '&offset=' . max(0, $offset);

		return $this->forward(subject: $subject, method: 'GET', path: self::TASKS_PATH . $query);
	}//end listTasks()

	/**
	 * One portal task, if it is the subject's.
	 *
	 * @param array<string, mixed> $subject The resolved bearer subject.
	 * @param string $uuid The task uuid.
	 *
	 * @return array{status: int, body: array<string, mixed>}|null The relayed
	 *         answer, or null on transport failure / an unmintable assertion.
	 *
	 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-task-proxy-is-the-only-path-and-the-assertion-never-reaches-the-browser
	 */
	public function getTask(array $subject, string $uuid): ?array {
		return $this->forward(subject: $subject, method: 'GET', path: self::TASKS_PATH . '/' . rawurlencode($uuid));
	}//end getTask()

	/**
	 * Complete a portal task as the subject, relaying answers, comment,
	 * outcome and the uploaded files as multipart.
	 *
	 * @param array<string, mixed> $subject The resolved bearer subject.
	 * @param string $uuid The task uuid.
	 * @param array<string, mixed> $answers The submitted answer fields.
	 * @param string|null $comment The resident's comment, when any.
	 * @param string $outcome The outcome to record ('' = the seam's default).
	 * @param array<int, array<string, mixed>> $files The uploads, each an
	 *                                                IRequest::getUploadedFile
	 *                                                shape {name, type, tmp_name, size}.
	 *
	 * @return array{status: int, body: array<string, mixed>}|null The relayed
	 *         answer, or null on transport failure / an unmintable assertion.
	 *
	 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-task-proxy-is-the-only-path-and-the-assertion-never-reaches-the-browser
	 */
	public function completeTask(
		array $subject,
		string $uuid,
		array $answers = [],
		?string $comment = null,
		string $outcome = '',
		array $files = [],
	): ?array {
		$multipart = [
			['name' => 'answers', 'contents' => (string)json_encode($answers)],
		];
		if ($comment !== null && $comment !== '') {
			$multipart[] = ['name' => 'comment', 'contents' => $comment];
		}

		if ($outcome !== '') {
			$multipart[] = ['name' => 'outcome', 'contents' => $outcome];
		}

		foreach ($files as $file) {
			$part = $this->filePart(file: $file);
			if ($part !== null) {
				$multipart[] = $part;
			}
		}

		return $this->forward(
			subject: $subject,
			method: 'POST',
			path: self::TASKS_PATH . '/' . rawurlencode($uuid) . '/complete',
			multipart: $multipart
		);
	}//end completeTask()

	/**
	 * One multipart file part from an IRequest::getUploadedFile() entry, or
	 * null when the entry carries no readable upload.
	 *
	 * @param array<string, mixed> $file The upload entry {name, type, tmp_name, size}.
	 *
	 * @return array<string, mixed>|null
	 */
	private function filePart(array $file): ?array {
		$tmpName = (string)($file['tmp_name'] ?? '');
		if ($tmpName === '' || is_readable($tmpName) === false) {
			return null;
		}

		$handle = fopen($tmpName, 'rb');
		if ($handle === false) {
			return null;
		}

		return [
			'name' => 'files[]',
			'contents' => $handle,
			'filename' => (string)($file['name'] ?? 'upload'),
			'headers' => ['Content-Type' => (string)($file['type'] ?? 'application/octet-stream')],
		];
	}//end filePart()

	/**
	 * Perform one assertion-signed, instance-local forward.
	 *
	 * The client's own Authorization header is NEVER part of the request: the
	 * only credential on the wire is the fresh, short-lived assertion. Non-2xx
	 * answers are relayed (the seam's named refusals must reach the proxy's
	 * mapping); only transport-level failure degrades to null.
	 *
	 * @param array<string, mixed> $subject The resolved bearer subject.
	 * @param string $method GET or POST.
	 * @param string $path The instance-local seam path (with query).
	 * @param array<int, array<string, mixed>>|null $multipart Multipart parts for a POST, when any.
	 *
	 * @return array{status: int, body: array<string, mixed>}|null
	 *
	 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-task-proxy-is-the-only-path-and-the-assertion-never-reaches-the-browser
	 */
	private function forward(array $subject, string $method, string $path, ?array $multipart = null): ?array {
		try {
			$assertion = $this->session->issueAssertion($subject);
		} catch (RuntimeException $unconfigured) {
			// No dedicated signing secret: the seam refuses everything anyway.
			// isAvailable() already keeps the surface hidden; log for the
			// operator and let the caller answer unavailable.
			$this->logger->warning('[PortalTaskGateway] Cannot mint the portal-subject assertion: ' . $unconfigured->getMessage());

			return null;
		}

		$options = [
			'headers' => ['X-Portal-Subject' => $assertion, 'Accept' => 'application/json'],
			'timeout' => self::FORWARD_TIMEOUT,
			// Relay the seam's named refusals (401/400/404/409) instead of
			// throwing, so the proxy's refusal mapping sees them.
			'http_errors' => false,
			// The seam is instance-local by contract, so the request
			// legitimately targets our own (possibly private) address.
			'nextcloud' => ['allow_local_address' => true],
		];
		if ($multipart !== null) {
			$options['multipart'] = $multipart;
		}

		try {
			$response = $this->send(method: $method, path: $path, options: $options);
		} catch (Throwable $failure) {
			$this->logger->warning('[PortalTaskGateway] Task forward failed in transport: ' . $failure->getMessage());

			return null;
		}

		$body = $response->getBody();
		$decoded = null;
		if (is_string($body) === true && $body !== '') {
			$decoded = json_decode($body, true);
		}

		if (is_array($decoded) === false) {
			$decoded = [];
		}

		return ['status' => (int)$response->getStatusCode(), 'body' => $decoded];
	}//end forward()

	/**
	 * Perform the HTTP call for one forward.
	 *
	 * @param string $method GET or POST.
	 * @param string $path The instance-local seam path (with query).
	 * @param array<string, mixed> $options The prepared client options.
	 *
	 * @return IResponse
	 */
	private function send(string $method, string $path, array $options): IResponse {
		$client = $this->clientService->newClient();
		$url = $this->urlGenerator->getAbsoluteURL($path);
		if ($method === 'POST') {
			return $client->post($url, $options);
		}

		return $client->get($url, $options);
	}//end send()
}//end class
