<?php

/**
 * Quiet Hours Staff Controller
 *
 * Staff self-service: get/set their own quiet-hours window (PA-new-1 names
 * "parents' and staff's quiet hours" together), through the SAME
 * {@see QuietHoursPolicy} the guardian side uses — one schema, one rule,
 * addressed by subjectRef regardless of role. Requires a Nextcloud session.
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
 * @spec openspec/changes/push-notifications-quiet-hours/design.md#api-design
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Notifications\QuietHoursPolicy;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * @spec openspec/changes/push-notifications-quiet-hours/design.md#api-design
 */
class QuietHoursStaffController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession Resolves the calling staff member's Nextcloud user id, used as their subjectRef.
	 * @param QuietHoursPolicy $quietHours The single source of truth.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly QuietHoursPolicy $quietHours,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The staff member's own configured window, or the documented default.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/push-notifications-quiet-hours/design.md#api-design
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$this->requireAuthenticatedStaff();
		return new JSONResponse($this->quietHours->resolveWindow(subjectRef: $this->staffRef()));
	}//end index()

	/**
	 * Set the staff member's own window.
	 *
	 * @param string $start Window start, `HH:MM`.
	 * @param string $end Window end, `HH:MM`.
	 *
	 * @return JSONResponse 204 on success, 400 for an invalid window.
	 *
	 * @spec openspec/changes/push-notifications-quiet-hours/design.md#api-design
	 */
	#[NoAdminRequired]
	public function update(string $start, string $end): JSONResponse {
		$this->requireAuthenticatedStaff();
		$saved = $this->quietHours->setWindow(subjectRef: $this->staffRef(), start: $start, end: $end);
		if ($saved === false) {
			return new JSONResponse(['error' => 'invalid_request'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse([], Http::STATUS_NO_CONTENT);
	}//end update()

	/**
	 * The staff authorization guard every `#[NoAdminRequired]` method calls
	 * FIRST, before any read or write (see `NewsController`'s identical
	 * guard for the full rationale).
	 *
	 * @return void
	 *
	 * @throws OCSForbiddenException When no Nextcloud user is authenticated.
	 */
	private function requireAuthenticatedStaff(): void {
		if ($this->userSession->getUser() === null) {
			throw new OCSForbiddenException('Authentication required');
		}
	}//end requireAuthenticatedStaff()

	/**
	 * The calling staff member's own subjectRef — their Nextcloud user id.
	 * Callers MUST call `requireAuthenticatedStaff()` first; this method
	 * itself performs no guard.
	 *
	 * @return string
	 */
	private function staffRef(): string {
		return (string)($this->userSession->getUser()?->getUID() ?? '');
	}//end staffRef()
}//end class
