<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\ExampleResident;

use OCA\Portaliq\Service\ExampleResident\ExampleResidentCatalogue;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentRecord;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentSignIn;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * The demo switch and the installed example resident of a portal: the
 * resident `zuiddrecht` is installed as `sanne.devries` on the portal
 * `zuiddrecht`.
 *
 * @spec openspec/changes/example-resident-demo-login/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
 */
class ExampleResidentSignInTest extends TestCase {

	/**
	 * The service over the switch, with or without its records.
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
		$records = $this->createMock(originalClassName: ExampleResidentRecord::class);
		$records->method('read')->willReturnCallback(
			static fn (string $id): array => ['userId' => ($id === 'zuiddrecht' ? 'sanne.devries' : ''), 'signIn' => ['mode' => 'nextcloud', 'label' => true]]
		);
		$catalogue = $this->createMock(originalClassName: ExampleResidentCatalogue::class);
		$catalogue->method('ids')->willReturn(['zuiddrecht']);
		$catalogue->method('find')->willReturnCallback(
			static fn (string $id): ?array => ($id === 'zuiddrecht' ? ['id' => 'zuiddrecht', 'portal' => 'zuiddrecht'] : null)
		);

		return new ExampleResidentSignIn(
			config: $config,
			records: ($withRecords === true ? $records : null),
			catalogue: $catalogue
		);
	}//end signIn()

	/**
	 * Open, the resident's own portal yields its user id; another resident,
	 * another portal or a portal without a slug yields nothing.
	 *
	 * @return void
	 */
	public function testTheUserIsTheInstalledResidentOfTheNamedPortal(): void {
		$signIn = $this->signIn(switch: 'yes');

		$this->assertTrue($signIn->open());
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
}//end class
