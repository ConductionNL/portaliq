<?php

/**
 * Portaliq Guest Action Controller
 *
 * The two public routes behind the guest page for signed links
 * (identity-guest-page-for-signed-links D3). A person without a portal
 * account holds a link a contributing app signed and mailed them; the page
 * posts its token here and portaliq forwards it to the action the app
 * declared for the `guest` audience. Portaliq verifies nothing about the
 * token, the app that signed it does, and nothing here creates an account or
 * a session.
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
 * @spec openspec/changes/archive/2026-10-01-identity-guest-page-for-signed-links/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\PortalActionForwarder;
use OCA\Portaliq\Service\PortalResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Forwards one declared guest action, or its preview, for a signed token.
 *
 * @spec openspec/changes/archive/2026-10-01-identity-guest-page-for-signed-links/tasks.md#T02
 */
class GuestActionController extends Controller {
	/**
	 * The longest token accepted; a signed link is far shorter.
	 */
	private const MAX_TOKEN_LENGTH = 2048;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalContributionRegistry $registry Finds the declared guest action.
	 * @param PortalActionForwarder $forwarder Forwards with the signed assertion.
	 * @param PortalResolver $portals Resolves the serving portal for its organisation.
	 * @param AuditTrailService $auditor Records each forward with the token's hash.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalContributionRegistry $registry,
		private readonly PortalActionForwarder $forwarder,
		private readonly PortalResolver $portals,
		private readonly AuditTrailService $auditor,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Forward the token to the action's preview endpoint and relay the answer:
	 * what the link is for and whether the act is still possible.
	 *
	 * @param string $appId The contributing app.
	 * @param string $actionId The declared guest action.
	 *
	 * @return JSONResponse The relayed answer, or 400 / 404 / 502.
	 *
	 * @spec openspec/changes/archive/2026-10-01-identity-guest-page-for-signed-links/tasks.md#T02
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function preview(string $appId, string $actionId): JSONResponse {
		$token = $this->token();
		if ($token === null) {
			return new JSONResponse(['error' => 'token_missing'], Http::STATUS_BAD_REQUEST);
		}

		$action = $this->registry->guestAction(appId: $appId, actionId: $actionId);
		if ($action === null || is_string(($action['previewEndpoint'] ?? null)) === false) {
			return $this->notFound();
		}

		$preview = ['endpoint' => $action['previewEndpoint'], 'method' => 'POST'];

		$answer = $this->relay(
			action: $preview,
			appId: $appId,
			auditAs: $actionId . ':preview',
			token: $token,
			body: [(string)$action['tokenField'] => $token]
		);
		if ($answer->getStatus() === Http::STATUS_BAD_GATEWAY) {
			return $answer;
		}

		// The page shows the app's summary next to what the action declares:
		// its button label, confirmation, fields, never its endpoints.
		return new JSONResponse(
			['preview' => $answer->getData(), 'action' => $this->declaration(action: $action)],
			$answer->getStatus()
		);
	}//end preview()

	/**
	 * What the guest page may know of an action: the texts it shows and the
	 * fields it asks for, without the token field and without any endpoint.
	 *
	 * @param array<string, mixed> $action The declared guest action.
	 *
	 * @return array<string, mixed>
	 */
	private function declaration(array $action): array {
		$tokenField = (string)$action['tokenField'];
		$fields = array_values(
			array_filter(
				(array)($action['fields'] ?? []),
				static fn ($field) => is_string($field) === true && $field !== $tokenField
			)
		);

		$declaration = ['fields' => $fields];
		foreach (['label', 'confirmText', 'successText'] as $key) {
			if (is_string(($action[$key] ?? null)) === true) {
				$declaration[$key] = $action[$key];
			}
		}

		$configs = [];
		foreach ((array)($action['fieldConfigs'] ?? []) as $field => $config) {
			if (in_array($field, $fields, true) === true && is_array($config) === true) {
				$configs[$field] = $config;
			}
		}

		if ($configs !== []) {
			$declaration['fieldConfigs'] = $configs;
		}

		return $declaration;
	}//end declaration()

	/**
	 * Forward the confirmed act: the declared fields only, with the token
	 * stamped under the action's `tokenField` over any client value.
	 *
	 * @param string $appId The contributing app.
	 * @param string $actionId The declared guest action.
	 *
	 * @return JSONResponse The relayed answer, or 400 / 404 / 502.
	 *
	 * @spec openspec/changes/archive/2026-10-01-identity-guest-page-for-signed-links/tasks.md#T02
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function act(string $appId, string $actionId): JSONResponse {
		$token = $this->token();
		if ($token === null) {
			return new JSONResponse(['error' => 'token_missing'], Http::STATUS_BAD_REQUEST);
		}

		$action = $this->registry->guestAction(appId: $appId, actionId: $actionId);
		if ($action === null) {
			return $this->notFound();
		}

		$body = [];
		foreach ((array)($action['fields'] ?? []) as $field) {
			if (is_string($field) === false) {
				continue;
			}

			$value = $this->request->getParam($field);
			if ($value !== null) {
				$body[$field] = $value;
			}
		}

		$body[(string)$action['tokenField']] = $token;

		return $this->relay(action: $action, appId: $appId, auditAs: $actionId, token: $token, body: $body);
	}//end act()

	/**
	 * Audit, forward with a guest subject, and relay status and body. An
	 * answer's `redirectUrl` reaches the page only when it is `https`.
	 *
	 * @param array<string, mixed> $action The action (or preview) to forward to.
	 * @param string $appId The contributing app.
	 * @param string $auditAs The action id the audit row names.
	 * @param string $token The signed token.
	 * @param array<string, mixed> $body The whole forwarded body.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-10-01-identity-guest-page-for-signed-links/tasks.md#T02
	 */
	private function relay(array $action, string $appId, string $auditAs, string $token, array $body): JSONResponse {
		$subject = $this->guestSubject(token: $token);

		$this->auditor->record(
			verb: 'forward',
			subjectRef: $subject['subjectRef'],
			organisation: $subject['organisation'],
			register: $appId,
			schema: $auditAs,
			id: '',
			jti: $subject['jti']
		);

		$response = $this->forwarder->forward(action: $action, subject: $subject, whitelisted: $body);
		if ($response === null) {
			return new JSONResponse(['error' => 'forward_failed'], Http::STATUS_BAD_GATEWAY);
		}

		$decoded = $this->forwarder->decodeBody(response: $response);
		if (array_key_exists('redirectUrl', $decoded) === true
			&& (is_string($decoded['redirectUrl']) === false || str_starts_with($decoded['redirectUrl'], 'https://') === false)
		) {
			unset($decoded['redirectUrl']);
		}

		return new JSONResponse($decoded, $response->getStatusCode());
	}//end relay()

	/**
	 * The guest subject of design D3: a hashed token as the subject, audience
	 * `guest`, trust `low`, the serving portal's organisation and a fresh jti.
	 *
	 * @param string $token The signed token.
	 *
	 * @return array{subjectRef: string, audience: string, organisation: string, trust: string, jti: string}
	 */
	private function guestSubject(string $token): array {
		$named = $this->request->getParam('portal', '');
		if (is_string($named) === false) {
			$named = '';
		}

		$site = $this->portals->resolve(request: $this->request, portalSlug: trim($named));

		return [
			'subjectRef' => 'guest:' . hash('sha256', $token),
			'audience' => PortalContributionRegistry::GUEST_AUDIENCE,
			'organisation' => (string)($site['organisation'] ?? ''),
			'trust' => 'low',
			'jti' => bin2hex(random_bytes(16)),
		];
	}//end guestSubject()

	/**
	 * The posted token, or null when it is missing, not a string or too long.
	 *
	 * @return string|null
	 */
	private function token(): ?string {
		$token = $this->request->getParam('token');
		if (is_string($token) === false || $token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
			return null;
		}

		return $token;
	}//end token()

	/**
	 * The one refusal for an unknown app, an unknown action and an action
	 * without a preview, so the route is no oracle (REQ-GST-003).
	 *
	 * @return JSONResponse
	 */
	private function notFound(): JSONResponse {
		return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
	}//end notFound()
}//end class
