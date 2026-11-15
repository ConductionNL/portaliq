<?php

/**
 * Portaliq Portal Cosign Controller
 *
 * The co-signer's side of a form that needs a second signature: open the
 * request with the code from the invitation, read it, then co-sign or refuse.
 * Every call needs the co-signer's own DigiD session at level substantial.
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
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use DateTimeImmutable;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Kernel\Contracts\PortalResolverInterface;
use OCA\Portaliq\Kernel\Contracts\PortalSessionServiceInterface;
use OCA\Portaliq\Kernel\Support\SessionTrust;
use OCA\Portaliq\Service\Intake\PortalCosign;
use OCA\Portaliq\Service\Intake\PortalCosignFlow;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Open, co-sign or refuse a request that waits for a co-signer.
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
class PortalCosignController extends Controller implements PortalProtected {

	/**
	 * The sign-in level a co-signer needs: their own DigiD.
	 */
	public const MIN_TRUST = 'substantial';

	/**
	 * The HTTP status for each reason the core refuses.
	 *
	 * @var array<string, int>
	 */
	private const STATUS = [
		'no-subject' => Http::STATUS_UNAUTHORIZED,
		'unknown' => Http::STATUS_NOT_FOUND,
		'expired' => Http::STATUS_GONE,
		'same-subject' => Http::STATUS_FORBIDDEN,
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest                      $request The request.
	 * @param PortalResolverInterface       $portals Resolves the portal being visited.
	 * @param PortalSessionServiceInterface $session Reads the co-signer's session.
	 * @param PortalCosign                  $cosign  Opens and signs.
	 * @param PortalCosignFlow              $flow    Refuses and tells the submitter.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalResolverInterface $portals,
		private readonly PortalSessionServiceInterface $session,
		private readonly PortalCosign $cosign,
		private readonly PortalCosignFlow $flow,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The request to co-sign, read-only.
	 *
	 * @param string $code   The code from the invitation.
	 * @param string $portal The portal's slug; empty resolves it from the host.
	 *
	 * @return JSONResponse `{submission}`, or the refusal with its reason.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function open(string $code = '', string $portal = ''): JSONResponse {
		$checked = $this->checked(code: $code, portal: $portal);
		if ($checked instanceof JSONResponse) {
			return $checked;
		}

		return new JSONResponse(['submission' => $checked['submission']]);
	}//end open()

	/**
	 * Co-sign: only after the co-signer confirmed they read the request.
	 *
	 * @param string $code      The code from the invitation.
	 * @param bool   $confirmed Whether "Ik heb de aanvraag gelezen en onderteken mee." is ticked.
	 * @param string $portal    The portal's slug; empty resolves it from the host.
	 *
	 * @return JSONResponse `{state: signed, reference}`, or the refusal.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function sign(string $code = '', bool $confirmed = false, string $portal = ''): JSONResponse {
		if ($confirmed !== true) {
			return new JSONResponse(['error' => 'not_confirmed'], Http::STATUS_BAD_REQUEST);
		}

		$checked = $this->checked(code: $code, portal: $portal);
		if ($checked instanceof JSONResponse) {
			return $checked;
		}

		$signed = $this->cosign->sign(
			code: $code,
			subjectRef: $checked['subjectRef'],
			levelOfAssurance: $checked['trust'],
			now: new DateTimeImmutable()
		);

		return $this->answered(result: $signed, state: PortalCosign::SIGNED);
	}//end sign()

	/**
	 * Refuse to co-sign: the request is not delivered and the submitter is told.
	 *
	 * @param string $code   The code from the invitation.
	 * @param string $portal The portal's slug; empty resolves it from the host.
	 *
	 * @return JSONResponse `{state: refused, reference}`, or the refusal.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function refuse(string $code = '', string $portal = ''): JSONResponse {
		$checked = $this->checked(code: $code, portal: $portal);
		if ($checked instanceof JSONResponse) {
			return $checked;
		}

		$refused = $this->flow->refuse(site: $checked['site'], code: $code, subjectRef: $checked['subjectRef'], now: new DateTimeImmutable());

		return $this->answered(result: $refused, state: PortalCosign::REFUSED);
	}//end refuse()

	/**
	 * The portal, a co-signer signed in at the needed level, and a request of this portal the code opens.
	 *
	 * @param string $code   The code.
	 * @param string $portal The portal's slug.
	 *
	 * @return array{site: array<string, mixed>, subjectRef: string, trust: string, submission: array<string, mixed>}|JSONResponse
	 */
	private function checked(string $code, string $portal): array|JSONResponse {
		$slug = null;
		if ($portal !== '') {
			$slug = $portal;
		}

		$site = $this->portals->resolve(request: $this->request, portalSlug: $slug);
		if ($site === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		$trust   = SessionTrust::normaliseTrust(trust: ($subject['trust'] ?? ''));
		if ($subject === null || SessionTrust::trustSatisfies(subjectTrust: $trust, minTrust: self::MIN_TRUST) === false) {
			return new JSONResponse(['error' => 'sign_in_required', 'minTrust' => self::MIN_TRUST], Http::STATUS_UNAUTHORIZED);
		}

		$subjectRef = (string)($subject['subjectRef'] ?? '');
		$opened     = $this->cosign->open(code: $code, subjectRef: $subjectRef, now: new DateTimeImmutable());
		if ($opened['ok'] === false) {
			return self::refusal(reason: $opened['reason']);
		}

		if (($opened['submission']['portal'] ?? '') !== (string)($site['slug'] ?? '')) {
			return self::refusal(reason: 'unknown');
		}

		unset($opened['submission']['portal']);

		return ['site' => $site, 'subjectRef' => $subjectRef, 'trust' => $trust, 'submission' => $opened['submission']];
	}//end checked()

	/**
	 * The answer to a sign or refuse.
	 *
	 * @param array{ok: bool, reason: string, reference: string} $result What the core answered.
	 * @param string                                             $state  The state it reached.
	 *
	 * @return JSONResponse
	 */
	private function answered(array $result, string $state): JSONResponse {
		if ($result['ok'] === false) {
			return self::refusal(reason: $result['reason']);
		}

		return new JSONResponse(['state' => $state, 'reference' => $result['reference']]);
	}//end answered()

	/**
	 * A refusal with its reason and the status that fits it.
	 *
	 * @param string $reason The core's reason.
	 *
	 * @return JSONResponse
	 */
	private static function refusal(string $reason): JSONResponse {
		return new JSONResponse(
			['error' => str_replace('-', '_', $reason)],
			(self::STATUS[$reason] ?? Http::STATUS_SERVICE_UNAVAILABLE)
		);
	}//end refusal()
}//end class
