<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Signin\PortalSigninSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The Sign-in widget on a portal's page: per provider the login route, and
 * the integriq broker settings (signin-integriq-broker-login T11).
 *
 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
 */
class PortalSigninController extends Controller {


	/**
	 * Constructor.
	 *
	 * @param IRequest             $request  The request.
	 * @param PortalSigninSettings $settings The sign-in settings.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSigninSettings $settings,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()


	/**
	 * The portal's sign-in settings, secret-free.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return JSONResponse 200; 404 for an unknown portal or one without an organisation.
	 *
	 * @auth admin-only How residents sign in is an administrator's choice.
	 *       Nextcloud expresses admin-only as the ABSENCE of an opt-out
	 *       attribute, so this tag is the declaration.
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
	 */
	public function index(string $slug): JSONResponse {
		$portal = $this->settings->portalBySlug(slug: $slug);
		$view = null;
		if ($portal !== null) {
			$view = $this->settings->view(portal: $portal);
		}

		if ($view === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($view);
	}//end index()


	/**
	 * Save the routes and the broker settings. The secret is write-only: an
	 * empty value keeps the stored one.
	 *
	 * @param string               $slug   The portal slug.
	 * @param array<string, mixed> $routes Provider to `oidc` or `broker`.
	 * @param array<string, mixed> $broker `startUrl`, `exchangeUrl`, `consumerId`.
	 * @param string               $secret A new consumer secret, or ''.
	 *
	 * @return JSONResponse 200 with the settings; 404; 422 `broker_incomplete`; 502 `save_failed`.
	 *
	 * @auth admin-only How residents sign in is an administrator's choice.
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
	 */
	public function update(string $slug, array $routes = [], array $broker = [], string $secret = ''): JSONResponse {
		$portal = $this->settings->portalBySlug(slug: $slug);
		if ($portal === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		$result = $this->settings->save(portal: $portal, routes: $routes, broker: $broker, secret: $secret);
		$status = match ($result['error'] ?? null) {
			null => Http::STATUS_OK,
			'no_organisation' => Http::STATUS_NOT_FOUND,
			'broker_incomplete' => Http::STATUS_UNPROCESSABLE_ENTITY,
			default => Http::STATUS_BAD_GATEWAY,
		};

		return new JSONResponse($result, $status);
	}//end update()
}//end class
