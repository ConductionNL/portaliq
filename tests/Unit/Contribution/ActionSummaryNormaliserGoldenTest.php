<?php

/**
 * ActionSummaryNormaliser::summary(): every summary kept or dropped, and
 * what is kept of it.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Contribution
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\ActionSummaryNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * The summary sentence, as recorded.
 */
class ActionSummaryNormaliserGoldenTest extends TestCase {
	/**
	 * A summary over the fields `size` and `when`.
	 *
	 * @param mixed $summary The declared summary.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function summary(mixed $summary): ?array {
		return (new ActionSummaryNormaliser())->summary(summary: $summary, whitelist: ['size', 'when']);
	}//end summary()

	/**
	 * Every way a summary is dropped.
	 *
	 * @return void
	 */
	public function testEveryDroppedSummaryIsAsRecorded(): void {
		$this->assertNull(self::summary('{size}'));
		$this->assertNull(self::summary([]));
		$this->assertNull(self::summary(['template' => 7]));
		$this->assertNull(self::summary(['template' => '   ']));
		$this->assertNull(self::summary(['template' => '{size}' . str_repeat('x', 295)]));
		$this->assertNull(self::summary(['template' => 'No field at all']));
		$this->assertNull(self::summary(['template' => '{size} and {colour}']));
		$this->assertNull(self::summary(['template' => '{1size}']));
	}//end testEveryDroppedSummaryIsAsRecorded()

	/**
	 * A kept summary: the trimmed template, a kept or dropped label, and the
	 * phrases of the named fields only.
	 *
	 * @return void
	 */
	public function testAKeptSummaryIsAsRecorded(): void {
		$this->assertSame(['template' => '{size}' . str_repeat('x', 294)], self::summary(['template' => '{size}' . str_repeat('x', 294)]));
		$this->assertSame(
			[
				'template' => '{size}, {size} op {when}',
				'label'    => 'Samengevat',
				'phrases'  => ['size' => ['small' => 'klein', '3' => 'drie']],
			],
			self::summary(
				[
					'template' => '  {size}, {size} op {when}  ',
					'label'    => '  Samengevat ',
					'phrases'  => [
						'size'  => ['small' => ' klein ', 3 => 'drie', 'big' => str_repeat('g', 121), 'none' => ''],
						'when'  => 'not a map',
						'other' => ['x' => 'y'],
					],
				]
			)
		);
		$this->assertSame(
			['template' => '{when}'],
			self::summary(['template' => '{when}', 'label' => str_repeat('l', 121), 'phrases' => 'none'])
		);
		$this->assertSame(['template' => '{when}', 'label' => str_repeat('l', 120)], self::summary(['template' => '{when}', 'label' => str_repeat('l', 120), 'phrases' => ['when' => []]]));
	}//end testAKeptSummaryIsAsRecorded()
}//end class
