<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\ExampleResident;

use OCA\Portaliq\Service\ExampleResident\ExampleResidentSignIn;
use OCA\Portaliq\Service\ExampleResident\ExampleSetResidents;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * The demo switch and the installed example resident of a portal: set
 * `zuiddrecht` installed `sanne.devries` as the resident of portal
 * `zuiddrecht` and added the `nextcloud` mode to it.
 *
 * @spec openspec/changes/example-resident-demo-login/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
 */
class ExampleResidentSignInTest extends TestCase {

	/**
	 * The service over the switch, with or without readable install records.
	 *
	 * @param string $switch      The app config `example_resident_demo_login`.
	 * @param bool   $withRecords Whether the install records can be read.
	 * @param bool   $modeAdded   Whether the set added the sign-in mode.
	 *
	 * @return ExampleResidentSignIn
	 */
	private function signIn(string $switch, bool $withRecords = true, bool $modeAdded = true): ExampleResidentSignIn {
		$config = $this->createMock(originalClassName: IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, mixed $default = ''): string => ($key === 'example_resident_demo_login' ? $switch : (string)$default)
		);
		$residents = $this->createMock(originalClassName: ExampleSetResidents::class);
		$residents->method('available')->willReturn($withRecords);
		$residents->method('forPortal')->willReturnCallback(
			static fn (string $portal): ?array => (
				($withRecords === true && $portal === 'zuiddrecht')
					? ['set' => 'zuiddrecht', 'uid' => 'sanne.devries', 'mode' => 'nextcloud', 'modeAdded' => $modeAdded]
					: null
			)
		);

		return new ExampleResidentSignIn(config: $config, residents: $residents);
	}//end signIn()

	/**
	 * Open, the resident's own portal yields its user id; another set,
	 * another portal or a portal without a slug yields nothing.
	 *
	 * @return void
	 */
	public function testTheUserIsTheInstalledResidentOfTheNamedPortal(): void {
		$signIn = $this->signIn(switch: 'yes');

		$this->assertTrue($signIn->open());
		$this->assertSame('zuiddrecht', $signIn->offered(portal: ['slug' => 'zuiddrecht']));
		$this->assertSame('sanne.devries', $signIn->userFor(id: 'zuiddrecht', portal: ['slug' => 'zuiddrecht']));
		$this->assertSame('', $signIn->userFor(id: 'someone', portal: ['slug' => 'zuiddrecht']));
		$this->assertSame('', $signIn->userFor(id: 'zuiddrecht', portal: ['slug' => 'other']));
		$this->assertSame('', $signIn->userFor(id: 'zuiddrecht', portal: []));
	}//end testTheUserIsTheInstalledResidentOfTheNamedPortal()

	/**
	 * Closed by the switch, or without readable install records, nothing is
	 * offered and no user is named.
	 *
	 * @return void
	 */
	public function testClosedOrWithoutRecordsNamesNoOne(): void {
		foreach (['switch off' => $this->signIn(switch: 'no'), 'no records' => $this->signIn(switch: 'yes', withRecords: false)] as $case => $signIn) {
			$this->assertFalse($signIn->open(), $case);
			$this->assertSame('', $signIn->userFor(id: 'zuiddrecht', portal: ['slug' => 'zuiddrecht']), $case);
			$this->assertSame('', $signIn->offered(portal: ['slug' => 'zuiddrecht']), $case);
		}

		$this->assertSame('', $this->signIn(switch: 'yes')->offered(portal: []));
	}//end testClosedOrWithoutRecordsNamesNoOne()

	/**
	 * With the switch off the site hides the way in the set added, and only that one.
	 *
	 * @return void
	 */
	public function testTheHiddenWayInIsTheModeTheSetAdded(): void {
		$this->assertSame('nextcloud', $this->signIn(switch: 'no')->hiddenWayIn(portal: ['slug' => 'zuiddrecht']));
		$this->assertSame('', $this->signIn(switch: 'no', modeAdded: false)->hiddenWayIn(portal: ['slug' => 'zuiddrecht']));
		$this->assertSame('', $this->signIn(switch: 'yes')->hiddenWayIn(portal: ['slug' => 'zuiddrecht']));
		$this->assertSame('', $this->signIn(switch: 'no')->hiddenWayIn(portal: ['slug' => 'other']));
	}//end testTheHiddenWayInIsTheModeTheSetAdded()
}//end class
