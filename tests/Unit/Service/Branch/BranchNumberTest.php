<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Branch;

use OCA\Portaliq\Service\Branch\BranchNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A branch number is exactly twelve digits as a string; anything else is ''.
 *
 * @covers \OCA\Portaliq\Service\Branch\BranchNumber
 */
class BranchNumberTest extends TestCase {
	/**
	 * Values and what they normalise to.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function values(): array {
		return [
			'twelve digits'        => ['000012345678', '000012345678'],
			'eleven digits'        => ['00001234567', ''],
			'thirteen digits'      => ['0000123456789', ''],
			'trailing newline'     => ["000012345678\n", ''],
			'surrounding space'    => [' 000012345678', ''],
			'letters'              => ['00001234567a', ''],
			'integer'              => [123456789012, ''],
			'null'                 => [null, ''],
			'array'                => [['000012345678'], ''],
			'empty'                => ['', ''],
			'non-ascii digits'     => ['٠٠٠٠١٢٣٤٥٦٧٨', ''],
		];
	}//end values()

	#[DataProvider('values')]
	public function testNormalise(mixed $value, string $expected): void {
		$this->assertSame($expected, (new BranchNumber())->normalise(value: $value));
	}//end testNormalise()
}//end class
