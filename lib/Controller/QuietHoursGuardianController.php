<?php

/**
 * Quiet Hours Guardian Controller
 *
 * Guardian self-service: get/set their own quiet-hours window, entirely
 * through {@see QuietHoursPolicy} — the single source of truth
 * (push-notifications-quiet-hours). Guarded by `PortalAuthMiddleware` via
 * the `PortalProtected` marker; the subject is read from the validated
 * bearer, never from a client parameter.
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
 * @spec openspec/changes/archive/2026-09-28-push-notifications-quiet-hours/design.md#api-design
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Kernel\Contracts\PortalSessionServiceInterface;
use OCA\Portaliq\Kernel\Contracts\QuietHoursPolicyInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * @spec openspec/changes/archive/2026-09-28-push-notifications-quiet-hours/design.md#api-design
 */
class QuietHoursGuardianController extends Controller implements PortalProtected {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalSessionServiceInterface $session Resolves the subject from the bearer.
	 * @param QuietHoursPolicyInterface $quietHours The single source of truth.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionServiceInterface $session,
		private readonly QuietHoursPolicyInterface $quietHours,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The guardian's own configured window, or the documented default.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-09-28-push-notifications-quiet-hours/design.md#api-design
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function index(): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse($this->quietHours->resolveWindow(subjectRef: (string)($subject['subjectRef'] ?? '')));
	}//end index()

	/**
	 * Set the guardian's own window.
	 *
	 * @param string $start Window start, `HH:MM`.
	 * @param string $end Window end, `HH:MM`.
	 *
	 * @return JSONResponse 204 on success, 400 for an invalid window.
	 *
	 * @spec openspec/changes/archive/2026-09-28-push-notifications-quiet-hours/design.md#api-design
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function update(string $start, string $end): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$saved = $this->quietHours->setWindow(subjectRef: (string)($subject['subjectRef'] ?? ''), start: $start, end: $end);
		if ($saved === false) {
			return new JSONResponse(['error' => 'invalid_request'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse([], Http::STATUS_NO_CONTENT);
	}//end update()

	/**
	 * Resolve the subject from the bearer (fail-closed).
	 *
	 * @return array<string, mixed>|null
	 */
	private function subject(): ?array {
		return $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
	}//end subject()
}//end class
