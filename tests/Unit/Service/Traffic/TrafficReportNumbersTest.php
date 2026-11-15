<?php

/**
 * TrafficReportNumbers: the totals and top lists of a report period, a
 * metric summed over days, and the change against the period before.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service\Traffic
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

namespace OCA\Portaliq\Tests\Unit\Service\Traffic;

use OCA\Portaliq\Service\Traffic\TrafficReportNumbers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The numbers of a traffic report.
 */
class TrafficReportNumbersTest extends TestCase {
	/**
	 * Changes and the percentage they read as.
	 *
	 * @return array<string, array{0: float, 1: float, 2: float|null}>
	 */
	public static function changes(): array {
		return [
			'doubled'          => [20.0, 10.0, 100.0],
			'halved'           => [5.0, 10.0, -50.0],
			'unchanged'        => [10.0, 10.0, 0.0],
			'rounded to tenth' => [10.0, 3.0, 233.3],
			'fell to nothing'  => [0.0, 4.0, -100.0],
			'no previous'      => [10.0, 0.0, null],
			'negative before'  => [10.0, -1.0, null],
		];
	}//end changes()

	/**
	 * The change is a percentage of the previous value, or null when there was none.
	 *
	 * @param float $current The current value.
	 * @param float $previous The previous value.
	 * @param float|null $expected The change.
	 *
	 * @return void
	 */
	#[DataProvider('changes')]
	public function testTheChangeIsAPercentageOfThePrevious(float $current, float $previous, ?float $expected): void {
		$this->assertSame(expected: $expected, actual: (new TrafficReportNumbers())->change(current: $current, previous: $previous));
	}//end testTheChangeIsAPercentageOfThePrevious()

	/**
	 * A plain metric is summed over the records, a missing one counting as zero.
	 *
	 * @return void
	 */
	public function testAPlainMetricIsSummed(): void {
		$records = [['pageViews' => 10], ['pageViews' => '5'], ['sessions' => 3], ['pageViews' => 2.5]];
		$numbers = new TrafficReportNumbers();
		$this->assertSame(expected: 17.5, actual: $numbers->metric(metric: 'pageViews', records: $records));
		$this->assertSame(expected: 3.0, actual: $numbers->metric(metric: 'sessions', records: $records));
		$this->assertSame(expected: 0.0, actual: $numbers->metric(metric: 'visitors', records: []));
	}//end testAPlainMetricIsSummed()

	/**
	 * A goal metric sums the conversions of that goal only.
	 *
	 * @return void
	 */
	public function testAGoalMetricSumsThatGoalsConversions(): void {
		$records = [
			['goals' => [['id' => 'apply', 'conversions' => 3], ['id' => 'pay', 'conversions' => 7]]],
			['goals' => [['id' => 'apply', 'conversions' => 2], 'junk', ['conversions' => 9]]],
			['goals' => 'apply'],
			[],
		];
		$numbers = new TrafficReportNumbers();
		$this->assertSame(expected: 5.0, actual: $numbers->metric(metric: 'goal:apply', records: $records));
		$this->assertSame(expected: 7.0, actual: $numbers->metric(metric: 'goal:pay', records: $records));
		$this->assertSame(expected: 0.0, actual: $numbers->metric(metric: 'goal:none', records: $records));
	}//end testAGoalMetricSumsThatGoalsConversions()

	/**
	 * The notFound metric sums the hits of every missing page.
	 *
	 * @return void
	 */
	public function testTheNotFoundMetricSumsTheHits(): void {
		$records = [
			['notFound' => [['path' => '/a', 'hits' => 4], ['path' => '/b', 'hits' => 1]]],
			['notFound' => [['path' => '/a']]],
			['notFound' => []],
		];
		$this->assertSame(expected: 5.0, actual: (new TrafficReportNumbers())->metric(metric: 'notFound', records: $records));
	}//end testTheNotFoundMetricSumsTheHits()

	/**
	 * Folding the days gives the totals, the number of days and the empty breakdowns.
	 *
	 * @return void
	 */
	public function testFoldingGivesTheTotalsAndTheDays(): void {
		$folded = (new TrafficReportNumbers())->fold(
			records: [
				['pageViews' => 30, 'sessions' => 10, 'visitors' => 8, 'engagedSessions' => 6],
				['pageViews' => 12, 'sessions' => 4, 'visitors' => 4, 'engagedSessions' => 1],
			]
		);
		$this->assertSame(
			expected: ['days' => 2, 'pageViews' => 42, 'sessions' => 14, 'visitors' => 12, 'engagedSessions' => 7],
			actual: array_intersect_key($folded, array_flip(['days', 'pageViews', 'sessions', 'visitors', 'engagedSessions']))
		);
		$this->assertSame(
			expected: ['days', 'pageViews', 'sessions', 'visitors', 'engagedSessions', 'bounceRate', 'pages', 'sources', 'devices', 'browsers',
				'languages', 'regions', 'goals', 'conversionRate', 'funnels', 'forms', 'notFound', 'errors',
			],
			actual: array_keys($folded)
		);
		$this->assertSame(expected: [], actual: $folded['sources']);
	}//end testFoldingGivesTheTotalsAndTheDays()

	/**
	 * Sources group the referrers by channel, an empty channel being direct, largest first;
	 * a row without host or channel is no referrer at all.
	 *
	 * @return void
	 */
	public function testSourcesGroupTheReferrersByChannel(): void {
		$folded = (new TrafficReportNumbers())->fold(
			records: [
				[
					'referrers' => [
						['host' => 'google.com', 'channel' => 'search', 'count' => 5],
						['host' => 'bing.com', 'channel' => 'search', 'count' => 2],
						['host' => 'mail.example', 'channel' => '', 'count' => 9],
						['host' => '', 'channel' => '', 'count' => 50],
						['host' => 'nu.nl', 'channel' => 'referral', 'count' => 1],
					],
				],
				['referrers' => [['host' => 'google.com', 'channel' => 'search', 'count' => 4]]],
			]
		);
		$this->assertSame(expected: ['search' => 11, 'direct' => 9, 'referral' => 1], actual: $folded['sources']);
	}//end testSourcesGroupTheReferrersByChannel()
}//end class
