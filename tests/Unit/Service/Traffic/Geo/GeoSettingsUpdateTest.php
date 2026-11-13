<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Traffic\Geo;

use OCA\Portaliq\Service\Traffic\Geo\GeoSettings;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What `update()` lets through and what it drops: only the known provider and
 * edition values are written, a non-string is never written, the account id is
 * trimmed, and the licence key is written trimmed and sensitive.
 */
#[CoversClass(GeoSettings::class)]
class GeoSettingsUpdateTest extends TestCase {
	/**
	 * Every write made, key => value, in order.
	 *
	 * @var array<int, array{key: string, value: string, sensitive: bool}>
	 */
	private array $writes = [];

	/**
	 * Keys deleted, in order.
	 *
	 * @var array<int, string>
	 */
	private array $deletes = [];

	/**
	 * Stored values, keyed by config key.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	protected function setUp(): void {
		$this->writes = [];
		$this->deletes = [];
		$this->stored = [];

	}//end setUp()

	/**
	 * Settings over an app config that records every write and delete.
	 *
	 * @return GeoSettings
	 */
	private function settings(): GeoSettings {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->stored[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool {
				$this->stored[$key] = $value;
				$this->writes[] = ['key' => $key, 'value' => $value, 'sensitive' => $sensitive];

				return true;
			}
		);
		$config->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->stored[$key]);
				$this->deletes[] = $key;
			}
		);

		return new GeoSettings($config);
	}//end settings()

	/**
	 * The keys written, in order.
	 *
	 * @return array<int, string>
	 */
	private function writtenKeys(): array {
		return array_column($this->writes, 'key');
	}//end writtenKeys()

	/**
	 * An unknown provider or edition is not written — the stored value stays
	 * whatever it was.
	 *
	 * @return void
	 */
	public function testAnUnknownProviderOrEditionIsNotWritten(): void {
		$settings = $this->settings();

		$settings->update(['provider' => 'google', 'maxmindEdition' => 'GeoIP2-Country']);

		$this->assertNotContains(GeoSettings::KEY_PROVIDER, $this->writtenKeys());
		$this->assertNotContains(GeoSettings::KEY_EDITION, $this->writtenKeys());
	}//end testAnUnknownProviderOrEditionIsNotWritten()

	/**
	 * A non-string value for any field is left entirely alone — nothing is
	 * written and nothing is deleted.
	 *
	 * @return void
	 */
	public function testNonStringValuesAreIgnored(): void {
		$settings = $this->settings();

		$settings->update(
			[
				'provider' => ['maxmind'],
				'maxmindEdition' => 3,
				'maxmindAccountId' => 123456,
				'maxmindLicenseKey' => false,
			]
		);

		$this->assertSame([], $this->writes);
		$this->assertSame([], $this->deletes);
	}//end testNonStringValuesAreIgnored()

	/**
	 * A missing key field (not present at all) neither writes nor deletes the
	 * licence key — only an explicit empty string clears it.
	 *
	 * @return void
	 */
	public function testAnAbsentLicenceKeyFieldNeitherWritesNorDeletes(): void {
		$settings = $this->settings();

		$settings->update(['provider' => 'dbip']);

		$this->assertNotContains(GeoSettings::KEY_LICENSE_KEY, $this->writtenKeys());
		$this->assertSame([], $this->deletes);
	}//end testAnAbsentLicenceKeyFieldNeitherWritesNorDeletes()

	/**
	 * The account id is written trimmed, and the licence key is written trimmed
	 * and sensitive.
	 *
	 * @return void
	 */
	public function testAccountIdAndLicenceKeyAreTrimmedOnWrite(): void {
		$settings = $this->settings();

		$settings->update(['maxmindAccountId' => "  42\t", 'maxmindLicenseKey' => "  secret \n"]);

		$this->assertSame('42', $this->stored[GeoSettings::KEY_ACCOUNT_ID]);
		$this->assertSame('secret', $this->stored[GeoSettings::KEY_LICENSE_KEY]);
		foreach ($this->writes as $write) {
			if ($write['key'] === GeoSettings::KEY_LICENSE_KEY) {
				$this->assertTrue($write['sensitive']);
			}
		}
	}//end testAccountIdAndLicenceKeyAreTrimmedOnWrite()

	/**
	 * A licence key that is only whitespace deletes the stored key rather than
	 * storing the blanks.
	 *
	 * @return void
	 */
	public function testAWhitespaceLicenceKeyDeletesIt(): void {
		$settings = $this->settings();
		$this->stored[GeoSettings::KEY_LICENSE_KEY] = 'old';

		$settings->update(['maxmindLicenseKey' => '   ']);

		$this->assertSame([GeoSettings::KEY_LICENSE_KEY], $this->deletes);
		$this->assertNotContains(GeoSettings::KEY_LICENSE_KEY, $this->writtenKeys());
		$this->assertSame('', $settings->maxMindLicenseKey());
	}//end testAWhitespaceLicenceKeyDeletesIt()
}//end class
