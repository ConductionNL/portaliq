<?php

/**
 * TrafficRollupSumGoldenTest
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

use OCA\Portaliq\Service\Traffic\TrafficRollupSum;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The goals, experiments, funnels and forms a roll-up sums, per set of
 * member records: rows without an id, rows that are not arrays, the same id
 * twice, and nested variants and fields in every shape a record may hold.
 *
 * @covers \OCA\Portaliq\Service\Traffic\TrafficRollupSum
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficExperiments
 * @uses \OCA\Portaliq\Service\Traffic\TrafficRollupOutcomes
 * @uses \OCA\Portaliq\Service\Traffic\TrafficRollupValues
 */
class TrafficRollupSumGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../../Fixtures/Golden/traffic-rollup-sum.json';

	/**
	 * Every summed record matches the recorded one.
	 *
	 * @return void
	 */
	public function testEverySumMatchesTheGolden(): void {
		$sum    = new TrafficRollupSum();
		$actual = [];
		foreach ($this->worlds() as $name => $records) {
			$day = $sum->sum(portal: 'regio', date: '2026-11-01', members: ['a', 'b'], records: $records, aggregatedAt: '2026-11-02T00:00:00Z');

			$actual[$name] = [
				'goals' => $day['goals'] ?? null,
				'experiments' => $day['experiments'] ?? null,
				'funnels' => $day['funnels'] ?? null,
				'forms' => $day['forms'] ?? null,
			];
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($expected as $case => $answer) {
			$this->assertSame(expected: $answer, actual: json_decode((string)json_encode($actual[$case] ?? null), true), message: $case);
		}

		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
	}//end testEverySumMatchesTheGolden()

	/**
	 * Member records by name.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function worlds(): array {
		$object = new stdClass();
		$object->first = ['id' => 'v1', 'sessions' => 3, 'conversions' => 1];

		return [
			'no records' => [],
			'not lists' => [['goals' => 'x', 'experiments' => 5, 'funnels' => null, 'forms' => true]],
			'bad rows' => [[
				'goals' => ['x', $object, ['name' => 'no id'], ['id' => ''], ['id' => 7, 'conversions' => 2]],
				'experiments' => [['id' => ''], 3, ['id' => 'e1', 'variants' => 'x']],
				'funnels' => [['id' => null], 'f', ['id' => 'f1', 'steps' => 'x']],
				'forms' => [['id' => 'not a form id'], ['formId' => ''], ['formId' => 'k1', 'fields' => 9]],
			]],
			'two members' => [
				[
					'goals' => [
						['id' => 'g1', 'name' => 'Aanvraag', 'conversions' => 2, 'completions' => 1, 'value' => 1.25],
						['id' => 'g2', 'conversions' => 1],
					],
					'experiments' => [[
						'id' => 'e1',
						'name' => 'Knop',
						'status' => 'running',
						'variants' => [
							['id' => 'v1', 'name' => 'A', 'sessions' => 10, 'conversions' => 2],
							['id' => '', 'sessions' => 5],
							'x',
							['id' => 'v2', 'sessions' => 8, 'conversions' => 4],
						],
					]],
					'funnels' => [
						['id' => 'f1', 'name' => 'Formulier', 'steps' => [['name' => 'Start', 'sessions' => 10], 'x', ['name' => 'Klaar', 'sessions' => 4]]],
					],
					'forms' => [[
						'formId' => 'k1',
						'starts' => 5,
						'submits' => 3,
						'abandons' => 2,
						'fields' => [['fieldId' => 'naam', 'avgMs' => 1200, 'abandonedHere' => 1], ['fieldId' => ''], 'x'],
					]],
				],
				[
					'goals' => [
						['id' => 'g1', 'name' => 'Ander', 'conversions' => '3', 'completions' => 2, 'value' => '0.5'],
						['id' => 'g1', 'conversions' => 1],
					],
					'experiments' => [
						['id' => 'e1', 'status' => 'stopped', 'variants' => $object],
						['id' => 'e2', 'variants' => [['id' => 'v1', 'sessions' => 4, 'conversions' => 1]]],
						['id' => 'e1', 'variants' => [['id' => 'v1', 'sessions' => 2, 'conversions' => 2]]],
					],
					'funnels' => [
						[
							'id' => 'f1',
							'name' => 'Later',
							'steps' => [['sessions' => 6], ['name' => 'Midden', 'sessions' => 5], ['name' => 'Klaar', 'sessions' => 2]],
						],
						['id' => 'f2', 'steps' => []],
					],
					'forms' => [
						[
							'formId' => 'k1',
							'starts' => 1,
							'fields' => [['fieldId' => 'naam', 'avgMs' => 800], ['fieldId' => 'mail', 'avgMs' => 300, 'abandonedHere' => 2]],
						],
						['formId' => 'k2', 'submits' => 1, 'fields' => 'x'],
						['formId' => 'k1', 'abandons' => 4],
					],
				],
			],
		];
	}//end worlds()
}//end class
