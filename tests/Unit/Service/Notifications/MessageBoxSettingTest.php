<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Notifications;

use OCA\Portaliq\Service\Notifications\MessageBoxSetting;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use PHPUnit\Framework\TestCase;

/**
 * portal-settings-side-cards: the Berichtenbox switch moves the organisation's
 * message box offer aside and back, refuses to switch on without a source,
 * and keeps the rest of the presentation settings.
 *
 * @spec openspec/changes/portal-settings-side-cards/specs/portal-settings/spec.md#requirement-an-administrator-switches-the-berichtenbox-offer-from-the-portals-settings
 */
class MessageBoxSettingTest extends TestCase {

	/**
	 * The organisation's stored presentation settings.
	 *
	 * @var array<string, mixed>
	 */
	private array $stored = [];

	/**
	 * Switching off keeps the source aside, switching on restores it, and the
	 * other settings stay.
	 *
	 * @return void
	 */
	public function testSwitchingOffAndOnRestoresTheSameSource(): void {
		$this->stored = ['oidc' => ['digid' => ['route' => 'broker']], 'messageBox' => ['sourceId' => 'mijnoverheid', 'label' => 'Berichtenbox']];
		$setting = $this->setting();

		$this->assertSame(['offered' => true, 'configured' => true, 'label' => 'Berichtenbox'], $setting->read(orgSlug: 'zuiddrecht'));

		$off = $setting->write(orgSlug: 'zuiddrecht', offered: false);
		$this->assertSame(['offered' => false, 'configured' => true, 'label' => 'Berichtenbox'], $off);
		$this->assertArrayNotHasKey('messageBox', $this->stored);
		$this->assertSame(['sourceId' => 'mijnoverheid', 'label' => 'Berichtenbox'], $this->stored['messageBoxOff']);

		$on = $setting->write(orgSlug: 'zuiddrecht', offered: true);
		$this->assertSame(['offered' => true, 'configured' => true, 'label' => 'Berichtenbox'], $on);
		$this->assertSame(['sourceId' => 'mijnoverheid', 'label' => 'Berichtenbox'], $this->stored['messageBox']);
		$this->assertArrayNotHasKey('messageBoxOff', $this->stored);
		$this->assertSame(['digid' => ['route' => 'broker']], $this->stored['oidc']);
	}//end testSwitchingOffAndOnRestoresTheSameSource()

	/**
	 * Without a source ever configured the offer cannot be switched on.
	 *
	 * @return void
	 */
	public function testWithoutASourceItCannotBeSwitchedOn(): void {
		$this->stored = [];
		$setting = $this->setting();

		$this->assertSame(['offered' => false, 'configured' => false, 'label' => ''], $setting->read(orgSlug: 'zuiddrecht'));
		$this->assertSame('no_source', $setting->write(orgSlug: 'zuiddrecht', offered: true));
		$this->assertSame([], $this->stored);
	}//end testWithoutASourceItCannotBeSwitchedOn()

	/**
	 * An unknown organisation reads as nothing and writes nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownOrganisationIsNotFound(): void {
		$setting = $this->setting(known: false);

		$this->assertNull($setting->read(orgSlug: 'nowhere'));
		$this->assertSame('not_found', $setting->write(orgSlug: 'nowhere', offered: true));
	}//end testAnUnknownOrganisationIsNotFound()

	/**
	 * The setting over a stored presentation.
	 *
	 * @param bool $known Whether the organisation exists.
	 *
	 * @return MessageBoxSetting
	 */
	private function setting(bool $known = true): MessageBoxSetting {
		$config = $this->createMock(PortalOrganisationConfigService::class);
		$config->method('presentationFor')->willReturnCallback(
			fn (string $orgSlug): ?array => ($known === true ? ['uuid' => 'org-1', 'overrides' => $this->stored] : null)
		);
		$config->method('writePresentation')->willReturnCallback(
			function (string $organisationUuid, array $overrides): bool {
				$this->stored = $overrides;
				return true;
			}
		);

		return new MessageBoxSetting($config);
	}//end setting()
}//end class
