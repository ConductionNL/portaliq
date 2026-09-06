<?php

/**
 * An in-memory IAppConfig double for the traffic tests.
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

namespace OCA\Portaliq\Tests\Unit\Service\Traffic;

use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Builds an IAppConfig mock over one array, so a test can read back what a
 * counter or a salt wrote without a database.
 */
final class FakeAppConfig {

	/**
	 * The stored values, keyed `app/key`.
	 *
	 * @var array<string, string|int>
	 */
	public array $values = [];


	/**
	 * The mock, wired to this instance's array.
	 *
	 * @param TestCase $test The owning test, for createMock.
	 *
	 * @return IAppConfig The double.
	 */
	public function mock(TestCase $test): IAppConfig {
		$config = $test->getMockBuilder(IAppConfig::class)->getMock();
		$config->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0): int => (int)($this->values[$app . '/' . $key] ?? $default)
		);
		$config->method('setValueInt')->willReturnCallback(
			function (string $app, string $key, int $value): bool {
				$this->values[$app . '/' . $key] = $value;
				return true;
			}
		);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => (string)($this->values[$app . '/' . $key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->values[$app . '/' . $key] = $value;
				return true;
			}
		);
		$config->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->values[$app . '/' . $key]);
			}
		);
		$config->method('getKeys')->willReturnCallback(
			function (string $app): array {
				$keys = [];
				foreach (array_keys($this->values) as $stored) {
					if (str_starts_with($stored, $app . '/') === true) {
						$keys[] = substr($stored, strlen($app) + 1);
					}
				}

				return $keys;
			}
		);

		return $config;
	}//end mock()


}//end class
