<?php

/**
 * Unit tests for the e-mail link switch: OFF by default, administrators only.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity\EmailLink;

use OCA\Portaliq\Controller\SettingsController;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkSetting;
use OCA\Portaliq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The switch, read and written through the admin settings.
 */
class EmailLinkSettingTest extends TestCase {
	/**
	 * The stored app config.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * @return void
	 */
	public function testOffByDefaultAndAPortalMustAlsoDeclareTheMode(): void {
		$setting   = $this->setting();
		$declaring = ['authentication' => ['modes' => ['email-link']]];

		$this->assertFalse($setting->isEnabled());
		$this->assertFalse($setting->offeredBy(portal: $declaring));

		$setting->setEnabled(enabled: true);
		$this->assertTrue($setting->offeredBy(portal: $declaring));
		$this->assertFalse($setting->offeredBy(portal: ['authentication' => ['modes' => ['digid']]]));
		$this->assertFalse($setting->offeredBy(portal: null));

		$this->stored['email_link_signin'] = 'yes';
		$this->assertFalse($setting->isEnabled(), 'Only the stored value 1 is on.');
	}//end testOffByDefaultAndAPortalMustAlsoDeclareTheMode()

	/**
	 * An administrator sees and sets the switch; others do not see it; only a
	 * real boolean switches it.
	 *
	 * @return void
	 */
	public function testOnlyAnAdministratorSeesAndSetsTheSwitch(): void {
		$admin = $this->createMock(SettingsService::class);
		$admin->method('getSettings')->willReturn(['isAdmin' => true]);
		$admin->method('updateSettings')->willReturn([]);
		$this->assertFalse((new SettingsController($this->createMock(IRequest::class), $admin, null, $this->setting()))->index()->getData()['email_link_signin_enabled']);

		$user = $this->createMock(SettingsService::class);
		$user->method('getSettings')->willReturn(['isAdmin' => false]);
		$this->assertArrayNotHasKey('email_link_signin_enabled', (new SettingsController($this->createMock(IRequest::class), $user, null, $this->setting()))->index()->getData());

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturnOnConsecutiveCalls(['email_link_signin_enabled' => 'yes'], ['email_link_signin_enabled' => true]);
		$controller = new SettingsController($request, $admin, null, $this->setting());

		$controller->update();
		$this->assertArrayNotHasKey('email_link_signin', $this->stored);
		$this->assertTrue($controller->update()->getData()['config']['email_link_signin_enabled']);
		$this->assertSame('1', $this->stored['email_link_signin']);
	}//end testOnlyAnAdministratorSeesAndSetsTheSwitch()

	/**
	 * @return EmailLinkSetting
	 */
	private function setting(): EmailLinkSetting {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = '') => ($this->stored[$key] ?? $default));
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$key] = $value;
				return true;
			}
		);

		return new EmailLinkSetting($config);
	}//end setting()
}//end class
