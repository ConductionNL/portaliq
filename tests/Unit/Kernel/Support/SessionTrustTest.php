<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Kernel\Support;

use OCA\Portaliq\Kernel\Support\SessionTrust;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The assurance levels low < substantial < high: anything unknown in a
 * session reads as low, and an unknown minimum is met by no one.
 *
 * @covers \OCA\Portaliq\Kernel\Support\SessionTrust
 */
class SessionTrustTest extends TestCase {
	/**
	 * Trust values and what they normalise to.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function trusts(): array {
		return [
			'low'          => ['low', 'low'],
			'substantial'  => ['substantial', 'substantial'],
			'high'         => ['high', 'high'],
			'upper case'   => ['High', 'low'],
			'unknown'      => ['very-high', 'low'],
			'null'         => [null, 'low'],
			'number'       => [3, 'low'],
			'array'        => [['high'], 'low'],
		];
	}//end trusts()

	#[DataProvider('trusts')]
	public function testNormaliseTrust(mixed $trust, string $expected): void {
		$this->assertSame($expected, (new SessionTrust())->normaliseTrust(trust: $trust));
	}//end testNormaliseTrust()

	/**
	 * Subject trust, minimum, and whether it is met.
	 *
	 * @return array<string, array{0: mixed, 1: mixed, 2: bool}>
	 */
	public static function minimums(): array {
		return [
			'high meets substantial'      => ['high', 'substantial', true],
			'substantial meets itself'    => ['substantial', 'substantial', true],
			'low misses substantial'      => ['low', 'substantial', false],
			'substantial misses high'     => ['substantial', 'high', false],
			'unknown subject reads low'   => ['eidas-high', 'substantial', false],
			'no minimum means low'        => [null, null, true],
			'empty minimum means low'     => ['low', '', true],
			'unknown minimum unmet'       => ['high', 'very-high', false],
			'non-string minimum unmet'    => ['high', 1, false],
			'array minimum unmet'         => ['high', ['low'], false],
		];
	}//end minimums()

	#[DataProvider('minimums')]
	public function testTrustSatisfies(mixed $subject, mixed $minimum, bool $expected): void {
		$this->assertSame($expected, (new SessionTrust())->trustSatisfies(subjectTrust: $subject, minTrust: $minimum));
	}//end testTrustSatisfies()
}//end class
