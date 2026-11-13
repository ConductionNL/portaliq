<?php

/**
 * Tests for the admin settings page of portaliq.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Settings
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Settings\AdminSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use PHPUnit\Framework\TestCase;

/**
 * The admin page renders the app's own template with the installed version
 * and whether the session signing secret is set, in portaliq's section.
 *
 * @covers \OCA\Portaliq\Settings\AdminSettings
 */
final class AdminSettingsTest extends TestCase {

	/**
	 * The page over a version and a signing-secret state.
	 *
	 * @param bool $configured Whether the signing secret is set.
	 *
	 * @return AdminSettings
	 */
	private function settings(bool $configured): AdminSettings {
		$apps = $this->createMock(IAppManager::class);
		$apps->expects($this->once())->method('getAppVersion')->with('portaliq')->willReturn('1.4.2');

		$session = $this->createMock(PortalSessionService::class);
		$session->method('isConfigured')->willReturn($configured);

		return new AdminSettings(appManager: $apps, session: $session);
	}//end settings()

	public function testTheFormRendersTheAdminTemplateWithTheVersion(): void {
		$form = $this->settings(configured: true)->getForm();

		$this->assertInstanceOf(TemplateResponse::class, $form);
		$this->assertSame('portaliq', $form->getApp());
		$this->assertSame('settings/admin', $form->getTemplateName());
		$this->assertSame(
			['version' => '1.4.2', 'jwtSigningSecretConfigured' => true],
			$form->getParams()
		);
	}//end testTheFormRendersTheAdminTemplateWithTheVersion()

	public function testTheFormSaysWhenNoSigningSecretIsSet(): void {
		$form = $this->settings(configured: false)->getForm();

		$this->assertFalse($form->getParams()['jwtSigningSecretConfigured']);
	}//end testTheFormSaysWhenNoSigningSecretIsSet()

	public function testThePageSitsInPortaliqsSectionAtPriorityTen(): void {
		$settings = new AdminSettings(
			appManager: $this->createMock(IAppManager::class),
			session: $this->createMock(PortalSessionService::class)
		);

		$this->assertSame('portaliq', $settings->getSection());
		$this->assertSame(10, $settings->getPriority());
	}//end testThePageSitsInPortaliqsSectionAtPriorityTen()
}//end class
