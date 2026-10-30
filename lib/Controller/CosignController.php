<?php

/**
 * Portaliq Cosign Controller
 *
 * The co-signer's two routes (decision 271). The person a form named opens
 * the link from their mail, signs in through the portal's own sign-in and
 * sees what they are asked to co-sign; their answer is forwarded to the case
 * app the binding names, with the portal's signed assertion. The case
 * already exists and waits; nothing is queued here.
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
 * @spec openspec/changes/resident-identity-in-forms/specs/resident-identity-in-forms/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\Intake\CosignInvitation;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\PortalActionForwarder;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Shows a co-sign request to the signed-in co-signer and forwards their answer.
 *
 * Order of refusals, both routes: 400 for a token this instance did not sign,
 * 401 without a session, 403 below the form's level or for the applicant
 * themselves, 410 after the last day.
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/resident-identity-in-forms/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 *
 * @SuppressWarnings(PHPMD.StaticAccess) -- PortalSessionService::trustSatisfies, the one trust ordering every portal gate shares.
 */
class CosignController extends Controller implements PortalProtected {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param CosignInvitation $invitation Reads the signed link.
	 * @param PortalSessionService $session Resolves the co-signer from the bearer.
	 * @param PortalResolver $portals Resolves the portal the link names.
	 * @param PortalFormBindingResolver $bindings The binding: its sign endpoint and level.
	 * @param PortalActionForwarder $forwarder Forwards the answer with the signed assertion.
	 * @param AuditTrailService $auditor Records each answer.
	 */
	public function __construct(
		IRequest $request,
		private readonly CosignInvitation $invitation,
		private readonly PortalSessionService $session,
		private readonly PortalResolver $portals,
		private readonly PortalFormBindingResolver $bindings,
		private readonly PortalActionForwarder $forwarder,
		private readonly AuditTrailService $auditor,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * What the co-signer is asked to sign, read-only.
	 *
	 * @param string $token The token from the link.
	 *
	 * @return JSONResponse `{reference, formName, title, holderName, dueAt}` or a refusal.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/resident-identity-in-forms/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function show(string $token = ''): JSONResponse {
		$checked = $this->check(token: $token);
		if ($checked instanceof JSONResponse) {
			return $checked;
		}

		$claims = $checked['claims'];

		return new JSONResponse(
			[
				'reference' => $claims['reference'] ?? '',
				'formName' => $claims['formName'] ?? '',
				'title' => $claims['title'] ?? '',
				'holderName' => $claims['holderName'] ?? '',
				'dueAt' => $claims['dueAt'] ?? '',
			]
		);
	}//end show()

	/**
	 * Co-sign or decline: forwarded to the case app the binding names.
	 *
	 * @param string $token The token from the link.
	 * @param string $answer `sign` or `refuse`.
	 * @param string $confirmed `true` when the co-signer ticked that they read the request (required to sign).
	 *
	 * @return JSONResponse The case app's answer, or a refusal.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/resident-identity-in-forms/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function answer(string $token = '', string $answer = '', string $confirmed = ''): JSONResponse {
		if (in_array($answer, ['sign', 'refuse'], true) === false || ($answer === 'sign' && in_array($confirmed, ['true', '1'], true) === false)) {
			return new JSONResponse(['error' => 'invalid'], Http::STATUS_BAD_REQUEST);
		}

		$checked = $this->check(token: $token);
		if ($checked instanceof JSONResponse) {
			return $checked;
		}

		['claims' => $claims, 'subject' => $subject, 'endpoint' => $endpoint] = $checked;
		if ($endpoint === '') {
			return new JSONResponse(['error' => 'try_again_later'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$this->auditor->record(
			verb: 'cosign-'.$answer,
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			organisation: (string)($subject['organisation'] ?? ''),
			register: (string)($claims['portal'] ?? ''),
			schema: (string)($claims['kind'] ?? ''),
			id: (string)($claims['case'] ?? ''),
			jti: (string)($subject['jti'] ?? '')
		);

		$response = $this->forwarder->forward(
			action: ['endpoint' => $endpoint, 'method' => 'POST'],
			subject: $subject,
			whitelisted: ['case' => (string)($claims['case'] ?? ''), 'kind' => (string)($claims['kind'] ?? ''), 'answer' => $answer]
		);
		if ($response === null) {
			return $this->forwarder->failureResponse();
		}

		return new JSONResponse($this->forwarder->decodeBody(response: $response), $response->getStatusCode());
	}//end answer()

	/**
	 * The claims, the co-signer and the sign endpoint, or the refusal.
	 *
	 * @param string $token The token from the link.
	 *
	 * @return array{claims: array<string, string>, subject: array<string, mixed>, endpoint: string}|JSONResponse
	 */
	private function check(string $token): array|JSONResponse {
		$claims = $this->invitation->read(token: $token);
		if ($claims === null) {
			return new JSONResponse(['error' => 'invalid_link'], Http::STATUS_BAD_REQUEST);
		}

		$slug = null;
		if (($claims['portal'] ?? '') !== '') {
			$slug = $claims['portal'];
		}

		$site    = $this->portals->resolve(request: $this->request, portalSlug: $slug);
		$binding = null;
		if ($site !== null) {
			$binding = $this->bindings->bindingFor(portal: (string)($site['slug'] ?? ''), route: (string)($claims['route'] ?? ''));
		}

		$required = null;
		if ($site !== null && $binding !== null) {
			$required = $this->bindings->requiredTrust(site: $site, binding: $binding, render: $this->bindings->render(binding: $binding));
		}

		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		if ($subject === null) {
			return new JSONResponse(['error' => 'sign_in_required', 'minTrust' => $required], Http::STATUS_UNAUTHORIZED);
		}

		$refusal = $this->refusal(claims: $claims, subject: $subject, required: $required);
		if ($refusal !== null) {
			return $refusal;
		}

		return ['claims' => $claims, 'subject' => $subject, 'endpoint' => trim((string)($binding['cosign']['endpoint'] ?? ''))];
	}//end check()

	/**
	 * Why this signed-in person may not answer, or null.
	 *
	 * @param array<string, string> $claims The claims.
	 * @param array<string, mixed> $subject The co-signer.
	 * @param string|null $required The form's level, or null for none.
	 *
	 * @return JSONResponse|null
	 */
	private function refusal(array $claims, array $subject, ?string $required): ?JSONResponse {
		if ($required !== null && PortalSessionService::trustSatisfies(subjectTrust: ($subject['trust'] ?? ''), minTrust: $required) === false) {
			return new JSONResponse(['error' => 'sign_in_required', 'minTrust' => $required], Http::STATUS_FORBIDDEN);
		}

		if ($this->invitation->isApplicant(claims: $claims, subjectRef: (string)($subject['subjectRef'] ?? '')) === true) {
			return new JSONResponse(['error' => 'same_subject'], Http::STATUS_FORBIDDEN);
		}

		if ($this->invitation->isPast(claims: $claims) === true) {
			return new JSONResponse(['error' => 'expired', 'dueAt' => $claims['dueAt'] ?? ''], Http::STATUS_GONE);
		}

		return null;
	}//end refusal()
}//end class
