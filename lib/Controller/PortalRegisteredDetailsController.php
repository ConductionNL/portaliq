<?php

/**
 * Portaliq Portal Registered Details Controller
 *
 * `GET /portal/api/identity/registered-details`: what the BRP or the KvK
 * holds about the bearer (identity-registered-details). The subject comes
 * from the bearer only; the request carries no identifier, and any it does
 * carry is ignored.
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
 * @spec openspec/changes/identity-registered-details/specs/registered-details/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\CaseTypeVisibility;
use OCA\Portaliq\Service\Identity\PortalRegisteredDetailsLinks;
use OCA\Portaliq\Service\Identity\PortalRegisteredDetailsService;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The bearer's own registered details.

 *
 * @spec openspec/changes/identity-registered-details/specs/registered-details/spec.md
 */
class PortalRegisteredDetailsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                       $request  The request.
	 * @param PortalSessionService           $session  Resolves the subject from the bearer.
	 * @param PortalRegisteredDetailsService $details  Reads the BRP or KvK record.
	 * @param PortalRegisteredDetailsLinks   $links    The portal's request links.
	 * @param CaseTypeVisibility             $portals  Finds the portal the request is served from.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionService $session,
		private readonly PortalRegisteredDetailsService $details,
		private readonly PortalRegisteredDetailsLinks $links,
		private readonly CaseTypeVisibility $portals,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The bearer's own BRP or KvK record, with the portal's request links.
	 *
	 * @return JSONResponse 401 without a session; otherwise the record or
	 *                      `{available: false, reason}`.
	 *
	 * @spec openspec/changes/identity-registered-details/specs/registered-details/spec.md#requirement-a-resident-sees-their-own-brp-record-req-ird-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function show(): JSONResponse {
		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$answer = $this->details->forSubject(subjectRef: (string)($subject['subjectRef'] ?? ''));
		if (($answer['available'] ?? false) === true) {
			$answer['links'] = $this->links->forPortal(
				portal: $this->portals->servingPortal(request: $this->request, subject: $subject),
				kind: (string)($answer['kind'] ?? '')
			);
		}

		return new JSONResponse($answer);
	}//end show()
}//end class
