<?php

/**
 * Portaliq Admin Settings
 *
 * Provides the admin settings form for the Portaliq application.
 *
 * @category Settings
 * @package  OCA\Portaliq\Settings
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

namespace OCA\Portaliq\Settings;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

/**
 * Provides the admin settings form for the Portaliq application.
 *
 * Implements ISettings (full-admin-only access). If your app needs delegated
 * admin support — allowing group-restricted sub-admins to manage settings —
 * migrate to IDelegatedSettings and implement getAuthorizedGroupId(). See
 * OCP\Settings\IDelegatedSettings for the interface contract and
 * https://docs.nextcloud.com/server/latest/developer_manual/app_development/settings.html
 * for usage guidance. For most apps, ISettings is the correct choice.
 *
 * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#1.4
 */
class AdminSettings implements ISettings {
	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager The app manager.
	 * @param PortalSessionService $session Reports the signing-secret state.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly PortalSessionService $session,
	) {
	}//end __construct()

	/**
	 * Get the settings form template.
	 *
	 * Surfaces whether the portal auth edge's dedicated `jwt_signing_secret`
	 * is configured — never the secret's value — so an operator can see the
	 * auth edge is not yet safe to use instead of discovering it via failed
	 * supplier/client logins.
	 *
	 * @return TemplateResponse
	 *
	 * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#1.4
	 */
	public function getForm(): TemplateResponse {
		$version = $this->appManager->getAppVersion(appId: Application::APP_ID);

		return new TemplateResponse(
			Application::APP_ID,
			'settings/admin',
			[
				'version' => $version,
				'jwtSigningSecretConfigured' => $this->session->isConfigured(),
			]
		);
	}//end getForm()

	/**
	 * Get the section ID this settings page belongs to.
	 *
	 * @return string
	 */
	public function getSection(): string {
		return 'portaliq';
	}//end getSection()

	/**
	 * Get the priority for ordering within the section.
	 *
	 * @return int
	 */
	public function getPriority(): int {
		return 10;
	}//end getPriority()
}//end class
