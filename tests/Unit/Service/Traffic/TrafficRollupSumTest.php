<?php

/**
 * Unit tests for TrafficRollupSum.
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

/**
 * The sums, the merges by key, the re-derived rates, and the member
 * that had nothing.
 */
class TrafficRollupSumTest extends TestCase {

	/**
	 * A member's day.
	 *
	 * @param string               $portal The member slug.
	 * @param array<string, mixed> $extra  Fields over the defaults.
	 *
	 * @return array<string, mixed> The record.
	 */
	private function record(string $portal, array $extra = []): array {
		return $extra + [
			'portal' => $portal,
			'date' => '2026-09-04',
			'segment' => '',
			'pageViews' => 10,
			'sessions' => 4,
			'visitors' => 3,
			'newVisitors' => null,
			'returningVisitors' => null,
			'accounts' => null,
			'engagedSessions' => 2,
			'avgEngagementSeconds' => 30.0,
			'bounceRate' => 0.5,
			'events' => ['page_view' => 10],
			'pages' => [['path' => '/', 'views' => 6, 'entrances' => 4, 'exits' => 1, 'avgEngagementSeconds' => 20.0], ['path' => '/a', 'views' => 4, 'entrances' => 0, 'exits' => 3, 'avgEngagementSeconds' => 10.0]],
			'transitions' => [['from' => '/', 'to' => '/a', 'count' => 4]],
			'referrers' => [['host' => 'google.nl', 'channel' => 'organic', 'count' => 2]],
			'campaigns' => [],
			'devices' => ['desktop' => 3, 'mobile' => 1],
			'browsers' => [],
			'os' => [],
			'languages' => ['nl' => 4],
			'regions' => [],
			'searches' => [],
			'downloads' => [],
			'outbound' => [],
			'goals' => [['id' => 'contact', 'name' => 'Contact', 'conversions' => 1, 'completions' => 2, 'value' => 10.0]],
			'conversionRate' => 0.25,
			'funnels' => [['id' => 'f', 'name' => 'F', 'steps' => [['name' => 'a', 'sessions' => 4, 'dropOff' => 0.0], ['name' => 'b', 'sessions' => 2, 'dropOff' => 0.5]]]],
			'forms' => [['formId' => 'x', 'starts' => 2, 'submits' => 1, 'abandons' => 1, 'completionRate' => 0.5, 'fields' => [['fieldId' => 'email', 'avgMs' => 1000, 'abandonedHere' => 1]]]],
			'notFound' => [['path' => '/oud', 'hits' => 1]],
			'errors' => [['message' => 'boom', 'source' => 'x/app.js', 'hits' => 1, 'pages' => ['/']]],
			'customDimensions' => ['audience' => ['inwoner' => 2]],
			'emails' => ['opens' => 1, 'clicks' => 0],
			'aggregatedAt' => '2026-09-04T10:00:00Z',
			'lastEventAt' => '2026-09-04T09:00:00Z',
		];
	}//end record()


	/**
	 * Two members add up, keyed lists merge, and the rates come from the
	 * summed counts.
	 *
	 * @return void
	 */
	public function testMembersAreSummedAndMergedByKey(): void {
		$summed = (new TrafficRollupSum())->sum(
			portal: 'rollup',
			date: '2026-09-04',
			members: ['a', 'b'],
			records: [
				$this->record('a'),
				$this->record('b', ['sessions' => 6, 'engagedSessions' => 6, 'avgEngagementSeconds' => 10.0, 'conversionRate' => 0.5, 'lastEventAt' => '2026-09-04T12:00:00Z']),
			],
			aggregatedAt: '2026-09-04T13:00:00Z'
		);

		$this->assertSame('rollup', $summed['portal']);
		$this->assertSame('', $summed['segment']);
		$this->assertSame(['a', 'b'], $summed['rollupOf']);
		$this->assertSame(2, $summed['members']);
		$this->assertSame(20, $summed['pageViews']);
		$this->assertSame(10, $summed['sessions']);
		$this->assertSame(6, $summed['visitors']);
		$this->assertNull($summed['newVisitors'], 'null stays null when no member could tell');
		$this->assertSame(8, $summed['engagedSessions']);
		$this->assertSame(0.2, $summed['bounceRate'], 'two bounces in ten sessions, not the mean of 0.5 and 0');
		$this->assertSame(18.0, $summed['avgEngagementSeconds'], '(4*30 + 6*10) / 10, weighted by sessions');
		$this->assertSame(0.4, $summed['conversionRate'], '(1 + 3) / 10');
		$this->assertSame(['page_view' => 20], $summed['events']);
		$this->assertSame(['desktop' => 6, 'mobile' => 2], $summed['devices']);
		$this->assertSame(['path' => '/', 'views' => 12, 'entrances' => 8, 'exits' => 2, 'avgEngagementSeconds' => 20.0], $summed['pages'][0]);
		$this->assertCount(2, $summed['pages']);
		$this->assertSame([['from' => '/', 'to' => '/a', 'count' => 8]], $summed['transitions']);
		$this->assertSame([['host' => 'google.nl', 'channel' => 'organic', 'count' => 4]], $summed['referrers']);
		$this->assertSame([['id' => 'contact', 'name' => 'Contact', 'conversions' => 2, 'completions' => 4, 'value' => 20.0]], $summed['goals']);
		$this->assertSame([['name' => 'a', 'sessions' => 8, 'dropOff' => 0.0], ['name' => 'b', 'sessions' => 4, 'dropOff' => 0.5]], $summed['funnels'][0]['steps']);
		$this->assertSame(4, $summed['forms'][0]['starts']);
		$this->assertSame(0.5, $summed['forms'][0]['completionRate']);
		$this->assertSame([['fieldId' => 'email', 'avgMs' => 1000, 'abandonedHere' => 2]], $summed['forms'][0]['fields']);
		$this->assertSame([['path' => '/oud', 'hits' => 2]], $summed['notFound']);
		$this->assertSame(2, $summed['errors'][0]['hits']);
		$this->assertSame(['audience' => ['inwoner' => 4]], $summed['customDimensions']);
		$this->assertSame(['opens' => 2, 'clicks' => 0], $summed['emails']);
		$this->assertSame('2026-09-04T12:00:00Z', $summed['lastEventAt']);
		$this->assertSame('2026-09-04T13:00:00Z', $summed['aggregatedAt']);
	}//end testMembersAreSummedAndMergedByKey()


	/**
	 * One member's record is the roll-up, not that record twice; and a
	 * member with no record for the day is counted as absent.
	 *
	 * @return void
	 */
	public function testAMemberWithoutDataContributesNothingAndNothingIsCountedTwice(): void {
		$summed = (new TrafficRollupSum())->sum(
			portal: 'rollup',
			date: '2026-09-04',
			members: ['a', 'quiet'],
			records: [$this->record('a', ['newVisitors' => 2, 'returningVisitors' => 1])],
			aggregatedAt: 'now'
		);

		$this->assertSame(1, $summed['members']);
		$this->assertSame(10, $summed['pageViews']);
		$this->assertSame(4, $summed['sessions']);
		$this->assertSame(2, $summed['newVisitors']);
		$this->assertSame(1, $summed['returningVisitors']);
		$this->assertNull($summed['accounts']);
		$this->assertSame(0.5, $summed['bounceRate']);
		$this->assertSame(30.0, $summed['avgEngagementSeconds']);
		$this->assertSame(6, $summed['pages'][0]['views']);
	}//end testAMemberWithoutDataContributesNothingAndNothingIsCountedTwice()


	/**
	 * No records at all is a zero day, with the lists empty and the
	 * nullable counters null.
	 *
	 * @return void
	 */
	public function testNoRecordsIsAnEmptyDay(): void {
		$summed = (new TrafficRollupSum())->sum(portal: 'rollup', date: '2026-09-04', members: ['a'], records: [], aggregatedAt: 'now');

		$this->assertSame(0, $summed['members']);
		$this->assertSame(0, $summed['pageViews']);
		$this->assertSame(0.0, $summed['bounceRate']);
		$this->assertNull($summed['newVisitors']);
		$this->assertSame([], $summed['pages']);
		$this->assertNull($summed['customDimensions']);
	}//end testNoRecordsIsAnEmptyDay()
}//end class
