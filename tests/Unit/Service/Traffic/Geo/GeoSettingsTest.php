<?php

/**
 * Unit tests for GeoSettings.
 *
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
use PHPUnit\Framework\TestCase;

/**
 * The provider choice, its defaults, and the one value that must never
 * come back out.
 */
class GeoSettingsTest extends TestCase {

	/**
	 * Stored values, keyed by config key.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * Which keys were written sensitive.
	 *
	 * @var array<string, bool>
	 */
	private array $sensitive = [];

	/**
	 * Settings over an in-memory app config.
	 *
	 * @return GeoSettings The settings.
	 */
	private function settings(): GeoSettings {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->stored[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false): bool {
				$this->stored[$key] = $value;
				$this->sensitive[$key] = $sensitive;

				return true;
			}
		);
		$config->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->stored[$key]);
			}
		);

		return new GeoSettings($config);
	}//end settings()


	/**
	 * DB-IP is the default, an unknown provider falls back to it, and the
	 * free MaxMind edition is the default edition.
	 *
	 * @return void
	 */
	public function testDefaultsAreDbIpAndTheFreeEdition(): void {
		$settings = $this->settings();
		$this->assertSame('dbip', $settings->provider());
		$this->assertSame('GeoLite2-City', $settings->maxMindEdition());

		$this->stored[GeoSettings::KEY_PROVIDER] = 'google';
		$this->stored[GeoSettings::KEY_EDITION] = 'GeoIP2-Country';
		$this->assertSame('dbip', $settings->provider(), 'an unknown provider is the default, not a crash');
		$this->assertSame('GeoLite2-City', $settings->maxMindEdition());
	}//end testDefaultsAreDbIpAndTheFreeEdition()


	/**
	 * The licence key is written sensitive, read back only as "set", and
	 * removed by an empty string while an absent key leaves it alone.
	 *
	 * @return void
	 */
	public function testTheLicenceKeyIsSensitiveAndNeverEchoed(): void {
		$settings = $this->settings();
		$settings->update(['provider' => 'maxmind', 'maxmindAccountId' => ' 123456 ', 'maxmindLicenseKey' => 'abcdef', 'maxmindEdition' => 'GeoIP2-City']);

		$this->assertTrue($this->sensitive[GeoSettings::KEY_LICENSE_KEY]);
		$this->assertSame('abcdef', $settings->maxMindLicenseKey());
		$view = $settings->toArray();
		$this->assertSame(['provider' => 'maxmind', 'maxmindAccountId' => '123456', 'maxmindEdition' => 'GeoIP2-City', 'maxmindLicenseKeySet' => true], $view);
		$this->assertStringNotContainsString('abcdef', json_encode($view));

		$settings->update(['provider' => 'none']);
		$this->assertSame('abcdef', $settings->maxMindLicenseKey(), 'an absent key in the write leaves the stored one alone');

		$settings->update(['maxmindLicenseKey' => '']);
		$this->assertSame('', $settings->maxMindLicenseKey());
		$this->assertFalse($settings->toArray()['maxmindLicenseKeySet']);
		$this->assertSame('none', $settings->provider());
	}//end testTheLicenceKeyIsSensitiveAndNeverEchoed()
}//end class
