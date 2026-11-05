<?php

/**
 * Example data for DqZaakGerelateerd: the Lindelaan Woo case carries a yearly
 * planned series and the three cases that series opened before.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/example-set-lindelaan-related/spec.md#requirement-the-lindelaan-woo-case-carries-a-planned-yearly-series-with-its-earlier-cases
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The series flow, its earlier cases and the changes that name the series on them.
 *
 * @spec openspec/specs/example-set-lindelaan-related/spec.md#requirement-the-lindelaan-woo-case-carries-a-planned-yearly-series-with-its-earlier-cases
 */
class ZuiddrechtLindelaanPlannedSeriesTest extends TestCase {

	/**
	 * The key of the series flow.
	 *
	 * @var string
	 */
	private const FLOW = 'reeks-kwartaaloverzicht-woo';

	/**
	 * The keys of the earlier cases, most recent first.
	 *
	 * @var array<int, string>
	 */
	private const EARLIER = [
		'kwartaaloverzicht-woo-eerder-1',
		'kwartaaloverzicht-woo-eerder-2',
		'kwartaaloverzicht-woo-eerder-3',
	];

	/**
	 * The planned-series part.
	 *
	 * @return array<string, mixed> The part, decoded.
	 */
	private function part(): array {
		$part = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.d/40-planned-series.json'), true);
		$this->assertIsArray(actual: $part);

		return $part;
	}//end part()

	/**
	 * The series flow is the graph dossiq writes for a yearly series on the Lindelaan case, switched on.
	 *
	 * @return void
	 */
	public function testTheSeriesFlowIsAYearlyPlannedFollowUpOnTheLindelaanCase(): void {
		$flows = array_column($this->part()['flows'], 'data', 'key');
		$flow  = $flows[self::FLOW];

		$this->assertSame(expected: 'dossiq-planned-follow-up', actual: $flow['applicationSlug']);
		$this->assertSame(expected: true, actual: $flow['enabled']);
		$this->assertArrayNotHasKey(key: 'publish', array: $flow);
		$this->assertSame(expected: 'schedule', actual: $flow['trigger']);
		$this->assertSame(expected: '0 6 1 1 *', actual: $flow['cron']);

		$nodes = array_column($flow['nodes'], null, 'id');
		$this->assertSame(
			expected: ['cron' => '0 6 1 1 *', 'runAs' => '{{cast:pieter.uid}}'],
			actual: $nodes['when']['config']
		);
		$this->assertSame(expected: 'openregister.trigger-schedule', actual: $nodes['when']['type']);
		$this->assertSame(expected: 'dossiq.createSubCase', actual: $nodes['create']['type']);
		$this->assertSame(expected: ['{{object:woo-fietspad.id}}'], actual: $nodes['create']['config']['relatedCases']);
		$this->assertSame(expected: 'Kwartaaloverzicht Woo-verzoeken', actual: $nodes['create']['config']['title']);
		$this->assertSame(
			expected: ['recurrence' => 'yearly', 'anchor' => '2024-01-01', 'until' => '', 'count' => 0],
			actual: $nodes['create']['config']['series']
		);
	}//end testTheSeriesFlowIsAYearlyPlannedFollowUpOnTheLindelaanCase()

	/**
	 * The three earlier cases opened on the series' days a year apart, closed within the
	 * advice term of 14 days, and are not Pieter's.
	 *
	 * @return void
	 */
	public function testTheEarlierCasesAreClosedAYearApart(): void {
		$cases = array_column($this->part()['objects'], null, 'key');
		$this->assertSame(expected: self::EARLIER, actual: array_keys($cases));

		$starts = [];
		foreach ($cases as $key => $case) {
			$data = $case['data'];
			$this->assertSame(expected: 'Kwartaaloverzicht Woo-verzoeken', actual: $data['title'], message: $key);
			$this->assertSame(expected: '{{cast:hannabos.uid}}', actual: $data['assignee'], message: $key);
			$this->assertSame(expected: 'Afgehandeld', actual: $case['lookups']['status']['where']['name'], message: $key);
			$this->assertArrayNotHasKey(key: 'parentCase', array: $data, message: $key);

			$start = $this->days(placeholder: $data['startDate']);
			$end   = $this->days(placeholder: $data['endDate']);
			$this->assertGreaterThan(expected: 0, actual: $end - $start, message: $key);
			$this->assertLessThanOrEqual(expected: 14, actual: $end - $start, message: $key);
			$starts[] = $start;
		}

		$this->assertSame(expected: [-279, -644, -1010], actual: $starts);
	}//end testTheEarlierCasesAreClosedAYearApart()

	/**
	 * Each earlier case names the series flow by a change, written after the flow.
	 *
	 * @return void
	 */
	public function testEachEarlierCaseNamesTheSeries(): void {
		$named = [];
		foreach ($this->part()['changes'] as $change) {
			$named[$change['object']] = $change['set'];
		}

		$expected = array_fill_keys(self::EARLIER, ['handoffSource' => 'planned-series:{{flow:'.self::FLOW.'.uuid}}']);
		$this->assertSame(expected: $expected, actual: $named);
	}//end testEachEarlierCaseNamesTheSeries()

	/**
	 * The day offset a `{{date:n}}` placeholder names.
	 *
	 * @param string $placeholder The placeholder.
	 *
	 * @return integer The offset in days.
	 */
	private function days(string $placeholder): int {
		$this->assertSame(expected: 1, actual: preg_match('/^\{\{date:([+-]?\d+)\}\}$/', $placeholder, $parts));

		return (int)$parts[1];
	}//end days()
}//end class
