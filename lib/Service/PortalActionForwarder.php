<?php

/**
 * Portaliq Portal Action Forwarder
 *
 * The transport half of the contract v2 A6 endpoint-action forward. The
 * AUTHORISATION half stays with the controller — this class is only ever
 * reached once the subject's own (already trust-filtered) manifest has proven
 * the action forwardable, so it never sees a call it should refuse.
 *
 * SECURITY: the short-lived signed `X-Portal-Subject` assertion is minted here;
 * the client's own Authorization header is NEVER forwarded. The endpoint is
 * instance-local by contract (the caller's SSRF guard rejects schemes and
 * protocol-relative paths before this runs), which is why the request is
 * allowed to target our own possibly-private address.
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
 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T8
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T09
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCP\Http\Client\IResponse;
use OCP\IRequest;
use Throwable;

/**
 * Performs the authorised server-to-server endpoint-action forward.
 *
 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T8
 */
class PortalActionForwarder {
	/**
	 * Timeout (seconds) for the server-to-server action forward.
	 */
	private const FORWARD_TIMEOUT = 10;

	/**
	 * HTTP methods an endpoint action may declare (contract v2, A6).
	 */
	private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request (source of the raw relayed body).
	 * @param InstanceLoopback $loopback Sends the A6 action forward to this instance.
	 * @param PortalSessionService $session Mints the signed `X-Portal-Subject` assertion.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly InstanceLoopback $loopback,
		private readonly PortalSessionService $session,
	) {
	}//end __construct()

	/**
	 * Forward an already-authorised action to its domain endpoint.
	 *
	 * When the action declares a `fields` whitelist, the caller passes the
	 * rebuilt map and it becomes the ENTIRE forwarded body — an undeclared
	 * field (subjectRef, or anything else a client smuggles into the body) can
	 * never reach the domain app through the forward. A null `$whitelisted`
	 * means the action declares no `fields` and the raw request body is relayed
	 * as-is (contract v2, A6): forwards like shillinq's `pay` carry an opaque
	 * domain payload with no whitelist.
	 *
	 * @param array<string, mixed> $action The already-authorised action declaration.
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param array<string, mixed>|null $whitelisted The rebuilt whitelisted body, or null to relay raw.
	 * @param string $scopeValue The server-resolved value of the action's declared `scopeClaim`,
	 *                           signed into the assertion; '' when the action declares none.
	 * @param list<array{name: string, type: string, tmp_name: string, size: int}> $files Checked uploads of a row action
	 *                                                                           that declares `files`; sent multipart.
	 *
	 * @return IResponse|null The domain app's response, or null on transport failure.
	 *
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T8
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-frozen-assertion-wire-format
	 * @spec openspec/changes/instance-loopback-self-calls/specs/instance-loopback/spec.md#requirement-every-call-to-this-instance-goes-through-one-loopback-service
	 * @spec openspec/changes/row-action-carries-files/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-row-action-may-carry-the-files-the-resident-adds-req-raf-001
	 */
	public function forward(array $action, array $subject, ?array $whitelisted = null, string $scopeValue = '', array $files = []): ?IResponse {
		$scopeClaim = '';
		if (is_string($action['scopeClaim'] ?? null) === true) {
			$scopeClaim = $action['scopeClaim'];
		}

		$body = $this->requestBody();
		if ($whitelisted !== null) {
			$body = (string)json_encode($whitelisted);
		}

		$options = [
			'headers' => [
				'X-Portal-Subject' => $this->session->issueAssertion(
					subject: $subject,
					scopeClaim: $scopeClaim,
					scopeValue: $scopeValue
				),
				'Content-Type' => 'application/json',
			],
			'timeout' => self::FORWARD_TIMEOUT,
			'body' => $body,
			// Relay non-2xx domain responses instead of throwing, so the
			// domain app's own status codes (e.g. 422) reach the caller.
			'http_errors' => false,
			// The endpoint is instance-local by contract, so the request
			// legitimately targets our own (possibly private) address.
			'nextcloud' => ['allow_local_address' => true],
		];

		// Files the action lets the resident add go multipart beside the
		// fields; without files the forward stays JSON, exactly as before
		// (row-action-carries-files REQ-RAF-001).
		$multipart = $this->multipart(action: $action, whitelisted: ($whitelisted ?? []), files: $files);
		if ($multipart !== null) {
			unset($options['body'], $options['headers']['Content-Type']);
			$options['multipart'] = $multipart;
		}

		try {
			// InstanceLoopback picks the address that answers from inside the
			// server (configured, absolute, or the loopback after a transport
			// failure); the method maps as before, anything unknown is a POST.
			return $this->loopback->request(
				method: strtoupper((string)($action['method'] ?? 'POST')),
				path: (string)$action['endpoint'],
				options: $options
			);
		} catch (Throwable) {
			// Transport failure. The caller mirrors the writer's 502 posture;
			// transport internals never leak to the portal client.
			return null;
		}//end try
	}//end forward()

	/**
	 * The multipart parts of a forward that carries files, or null when it carries none.
	 *
	 * Each whitelisted field is one part (an array as its JSON), each file one
	 * part named `<files.field>[]` with its own name and type. A file that
	 * cannot be opened is left out rather than sent empty.
	 *
	 * @param array<string, mixed>                                                      $action      The normalised action.
	 * @param array<string, mixed>                                                      $whitelisted The forwarded fields.
	 * @param list<array{name: string, type: string, tmp_name: string, size: int}> $files       The checked uploads.
	 *
	 * @return list<array<string, mixed>>|null
	 *
	 * @spec openspec/changes/row-action-carries-files/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-row-action-may-carry-the-files-the-resident-adds-req-raf-001
	 */
	private function multipart(array $action, array $whitelisted, array $files): ?array {
		$field = ($action['files']['field'] ?? null);
		if ($files === [] || is_string($field) === false) {
			return null;
		}

		$parts = [];
		foreach ($whitelisted as $name => $value) {
			$contents = $value;
			if (is_string($value) === false) {
				$contents = (string)json_encode($value);
			}

			$parts[] = ['name' => (string)$name, 'contents' => $contents];
		}

		foreach ($files as $file) {
			$handle = $this->open(path: $file['tmp_name']);
			if ($handle === null) {
				continue;
			}

			$parts[] = [
				'name' => $field . '[]',
				'contents' => $handle,
				'filename' => $file['name'],
				'headers' => ['Content-Type' => $file['type']],
			];
		}

		return $parts;
	}//end multipart()

	/**
	 * Open an uploaded file for reading, or null when it cannot be read.
	 *
	 * @param string $path The upload's temporary path.
	 *
	 * @return resource|null
	 */
	protected function open(string $path) {
		if ($path === '' || is_readable($path) === false) {
			return null;
		}

		$handle = fopen($path, 'rb');
		if ($handle === false) {
			return null;
		}

		return $handle;
	}//end open()

	/**
	 * Whether an action may be forwarded at all: a non-empty INSTANCE-LOCAL
	 * endpoint path (SSRF guard: a leading slash, no protocol-relative `//`,
	 * no scheme) and an allowed method. Trust is the caller's check.
	 *
	 * The same rule `ContributionController::isForwardableAction()` applies to
	 * the id-addressed forward; the row-scoped forward reads it from here.
	 *
	 * @param array<string, mixed> $action The matched action declaration.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-a-row-scoped-forward-must-prove-the-row-before-it-forwards
	 */
	public function isForwardable(array $action): bool {
		$endpoint = ($action['endpoint'] ?? null);
		if (is_string($endpoint) === false
			|| str_starts_with($endpoint, '/') === false
			|| str_starts_with($endpoint, '//') === true
			|| str_contains($endpoint, '://') === true
		) {
			return false;
		}

		return in_array(strtoupper((string)($action['method'] ?? 'POST')), self::ALLOWED_METHODS, true);
	}//end isForwardable()

	/**
	 * Decode a relayed domain response body to an array, degrading to `[]` for
	 * an empty, non-string or non-array-decoding body.
	 *
	 * @param IResponse $response The domain app's response.
	 *
	 * @return array<mixed>
	 *
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T8
	 */
	public function decodeBody(IResponse $response): array {
		$body = $response->getBody();
		if (is_string($body) === false || $body === '') {
			return [];
		}

		$decoded = json_decode($body, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end decodeBody()

	/**
	 * The raw request body to relay verbatim to the domain endpoint. Portaliq
	 * never interprets it: the domain app validates its own input.
	 *
	 * Nextcloud's runtime request declares getContent() protected, so it is
	 * called only when callable; otherwise the raw body comes from the input
	 * stream. A method_exists() guard let the protected call through and every
	 * forwarded action answered 500.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-a-row-scoped-forward-must-prove-the-row-before-it-forwards
	 */
	protected function requestBody(): string {
		if (is_callable([$this->request, 'getContent']) === true) {
			$content = $this->request->getContent();
			if (is_string($content) === true) {
				return $content;
			}
		}

		return $this->rawInput();
	}//end requestBody()

	/**
	 * The raw body of the current HTTP request, read from the input stream.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-a-row-scoped-forward-must-prove-the-row-before-it-forwards
	 */
	protected function rawInput(): string {
		$raw = file_get_contents('php://input');
		if (is_string($raw) === false) {
			return '';
		}

		return $raw;
	}//end rawInput()
}//end class
