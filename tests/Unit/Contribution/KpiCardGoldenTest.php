<?php

/**
 * RecordBlockNormaliser::kpiBlock(): every kpi card kept or dropped, and
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

use OCA\Portaliq\Contribution\RecordBlockNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * The kpi cards, as recorded.
 */
class KpiCardGoldenTest extends TestCase {
	/**
	 * A card needs a field and a label; its unit, details and highlight come
	 * along only when well formed, in that order.
	 *
	 * @return void
	 */
	public function testEveryKpiCardIsAsRecorded(): void {
		$block = (new RecordBlockNormaliser())->kpiBlock(
			block: [
				'collection' => 'c',
				'cards'      => [
					'junk',
					['field' => 'a'],
					['label' => 'L', 'field' => 'hours', 'unit' => ['one' => 'uur', 'other' => 'uren'], 'details' => [['field' => 'x', 'label' => 'X'], 'junk', ['field' => 'y']], 'highlight' => 'true', 'extra' => 1],
					['field' => 'b', 'label' => 'B', 'unit' => 'stuks', 'highlight' => 'yes', 'details' => []],
					['field' => 'c', 'label' => 'C', 'unit' => ['one' => 'x'], 'highlight' => true],
				],
			],
			collectionIds: ['c']
		);

		$this->assertSame(
			[
				['field' => 'hours', 'label' => 'L', 'unit' => ['one' => 'uur', 'other' => 'uren'], 'details' => [['field' => 'x', 'label' => 'X']], 'highlight' => true],
				['field' => 'b', 'label' => 'B', 'unit' => 'stuks'],
				['field' => 'c', 'label' => 'C', 'highlight' => true],
			],
			$block['cards']
		);
	}//end testEveryKpiCardIsAsRecorded()
}//end class
