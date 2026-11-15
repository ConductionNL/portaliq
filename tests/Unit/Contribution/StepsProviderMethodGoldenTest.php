<?php

/**
 * StepsProviderMethod::steps(): every provider step kept or dropped, and
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

use OCA\Portaliq\Contribution\StepsProviderMethod;
use PHPUnit\Framework\TestCase;

/**
 * The provider steps, as recorded.
 */
class StepsProviderMethodGoldenTest extends TestCase {
	/**
	 * A step needs a label and a known state; its description and date come
	 * along only as non-empty strings; the order is kept.
	 *
	 * @return void
	 */
	public function testEveryStepIsKeptOrDroppedAsRecorded(): void {
		$this->assertSame(
			[
				['label' => 'Ingediend', 'state' => 'done', 'description' => 'Op 1 mei', 'date' => '2026-05-01'],
				['label' => 'In behandeling', 'state' => 'current'],
				['label' => 'Besluit', 'state' => 'todo', 'date' => ' '],
			],
			(new StepsProviderMethod())->steps(
				[
					'junk',
					['date' => '2026-05-01', 'label' => ' Ingediend ', 'description' => 'Op 1 mei', 'state' => 'done', 'extra' => 1],
					['label' => '  ', 'state' => 'done'],
					['label' => 'Geen staat'],
					['label' => 'Fout', 'state' => 'Done'],
					['label' => 7, 'state' => 'done'],
					['label' => 'In behandeling', 'state' => 'current', 'description' => '', 'date' => 20260501],
					['label' => 'Besluit', 'state' => 'todo', 'date' => ' '],
				]
			)
		);
	}//end testEveryStepIsKeptOrDroppedAsRecorded()
}//end class
