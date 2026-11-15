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
	 *
	 * @return ExampleResidentSignIn
	 */
	private function signIn(string $switch, bool $withRecords = true): ExampleResidentSignIn {
		$config = $this->createMock(originalClassName: IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, mixed $default = ''): string => ($key === 'example_resident_demo_login' ? $switch : (string)$default)
		);
		$residents = $this->createMock(originalClassName: ExampleSetResidents::class);
		$residents->method('available')->willReturn($withRecords);
		$residents->method('residentsOf')->willReturnCallback(
			static fn (string $portal): array => (
				($withRecords === true && $portal === 'zuiddrecht')
					? [
					['set' => 'zuiddrecht', 'key' => 'sanne', 'uid' => 'sanne.devries', 'name' => 'Sanne de Vries', 'card' => []],
					['set' => 'zuiddrecht', 'key' => 'linda', 'uid' => 'linda.bakker', 'name' => 'Linda Bakker', 'card' => ['text' => 'Regelt zaken voor Henk.']],
				]
					: []
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
		$this->assertSame('linda.bakker', $signIn->userFor(id: 'zuiddrecht', portal: ['slug' => 'zuiddrecht'], resident: 'linda'));
		$this->assertSame('', $signIn->userFor(id: 'zuiddrecht', portal: ['slug' => 'zuiddrecht'], resident: 'henk'));
		$this->assertSame(
			[
				['set' => 'zuiddrecht', 'key' => 'sanne', 'name' => 'Sanne de Vries', 'card' => []],
				['set' => 'zuiddrecht', 'key' => 'linda', 'name' => 'Linda Bakker', 'card' => ['text' => 'Regelt zaken voor Henk.']],
			],
			$signIn->offeredResidents(portal: ['slug' => 'zuiddrecht'])
		);
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
			$this->assertSame([], $signIn->offeredResidents(portal: ['slug' => 'zuiddrecht']), $case);
		}

		$this->assertSame('', $this->signIn(switch: 'yes')->offered(portal: []));
	}//end testClosedOrWithoutRecordsNamesNoOne()

}//end class
