<?php

/**
 * Portaliq Settings Controller
 *
 * Controller for managing Portaliq application settings.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\PageEditorService;
use OCA\Portaliq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Controller for managing Portaliq application settings.
 */
class SettingsController extends Controller {
	/**
	 * Constructor for the SettingsController.
	 *
	 * @param IRequest $request The request object
	 * @param SettingsService $settingsService The settings service
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private SettingsService $settingsService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Retrieve all current settings.
	 *
	 * Admin-sensitive fields (register binding) are stripped for non-admin users
	 * so the register UUID is not exposed to regular authenticated users.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/settings-management/spec.md#REQ-CFG-001
	 */
	public function index(): JSONResponse {
		$settings = $this->settingsService->getSettings();
		$isAdmin = ($settings['isAdmin'] ?? false);

		if ($isAdmin === false) {
			unset($settings['register']);
			// Which groups may edit pages is an administrator's configuration,
			// and naming them hands every authenticated caller a slice of the
			// instance's group structure for a field they have no form for.
			// `mayEditPages` stays: it says only what THIS caller may do, which
			// is what the interface asking the question needs.
			unset($settings[PageEditorService::CONFIG_KEY]);
		}

		return new JSONResponse($settings);
	}//end index()

	/**
	 * Update settings with provided data — the canonical write.
	 *
	 * OpenRegister's AppHost dialect
	 * (`OCA\OpenRegister\AppHost\Routes::standard()`, mirrored by
	 * `GenericSettingsControllerBase::update()`) makes `PUT /api/settings` the
	 * canonical settings write and `POST /api/settings` the legacy alias.
	 * Portaliq builds its own route table rather than calling
	 * `Routes::standard()`, so before this method existed the PUT verb had no
	 * route at all and the URL answered 405 Method Not Allowed (measured
	 * 2026-08-08 against the dev instance) — not the 500 that leaf apps which
	 * DO adopt the AppHost table produce for a missing method.
	 *
	 * Auth posture: deliberately NO auth attribute at all, exactly as the
	 * legacy `create()` alias below. Nextcloud's SecurityMiddleware defaults to
	 * requiring an admin session unless a method opts out, and this method does
	 * not opt out — which is what REQ-CFG-002 mandates. The net privilege
	 * change is therefore zero: `update()` is the same write path as
	 * `create()`, reachable by exactly the same callers. An
	 * AuthorizedAdminSetting attribute is intentionally NOT used here — it
	 * would additionally admit delegated settings admins, widening access
	 * relative to the POST route this method mirrors.
	 *
	 * Note for readers: the opt-out attribute names are deliberately NOT
	 * spelled in bracket form anywhere in this docblock. Gate-5 (route-auth)
	 * decides whether a routed method declares an auth posture by grepping the
	 * lines above `public function` for that bracket form, and a comment
	 * mentioning one is indistinguishable from a real declaration — so writing
	 * the name out would have silently exempted this method from a security
	 * gate. See the PR for the upstream report.
	 *
	 * @return JSONResponse
	 *
	 * @auth admin-only Instance-wide configuration write mandated admin-only by
	 *       REQ-CFG-002. Nextcloud expresses that as the ABSENCE of an opt-out
	 *       attribute, so there is no attribute to add; this tag is the
	 *       declaration. Deliberately not the AuthorizedAdminSetting attribute
	 *       (named here without its bracket form on purpose, per the note
	 *       above), which would widen the route to delegated settings admins.
	 *
	 * @spec openspec/specs/settings-management/spec.md#REQ-CFG-002
	 */
	public function update(): JSONResponse {
		$data = $this->request->getParams();
		$config = $this->settingsService->updateSettings($data);

		return new JSONResponse(
			[
				'success' => true,
				'config' => $config,
			]
		);
	}//end update()

	/**
	 * Legacy alias for {@see update()} — `POST /api/settings`.
	 *
	 * Retained verbatim in behaviour so every existing POST caller keeps
	 * working; this is a strict addition, nothing was removed. The alias must
	 * keep declaring its own auth posture (here: the admin-required default, by
	 * declaring no attribute) because Nextcloud's middleware only evaluates
	 * attributes on the DISPATCHED method, never on the delegate target.
	 *
	 * @return JSONResponse
	 *
	 * @auth admin-only Legacy POST alias for the same instance-wide settings
	 *       write, so it carries the identical admin-only posture required by
	 *       REQ-CFG-002. Nextcloud middleware evaluates attributes only on the
	 *       DISPATCHED method, never on the delegate target, so this alias must
	 *       declare the posture in its own right.
	 *
	 * @spec openspec/specs/settings-management/spec.md#REQ-CFG-002
	 */
	public function create(): JSONResponse {
		return $this->update();
	}//end create()

	/**
	 * Re-import the configuration from app_template_register.json.
	 *
	 * Forces a fresh import regardless of version, auto-configuring
	 * all schema and register IDs from the import result.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/settings-management/spec.md#REQ-CFG-003
	 */
	public function load(): JSONResponse {
		$result = $this->settingsService->loadConfiguration(force: true);

		return new JSONResponse($result);
	}//end load()
}//end class
