<?php

/**
 * Unit tests for TrafficReportDefinitions, TrafficErrorStats and TrafficExport.
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

use OCA\Portaliq\Service\Traffic\TrafficErrorStats;
use OCA\Portaliq\Service\Traffic\TrafficExport;
use OCA\Portaliq\Service\Traffic\TrafficReportDefinitions;
use PHPUnit\Framework\TestCase;

/**
 * What a portal's reports, alerts and roll-up members resolve to; what
 * the error rows and the export file look like.
 */
class TrafficReportDefinitionsTest extends TestCase {

	/**
	 * @return void
	 */
	public function testReportsKeepTheUsableAndDefaultTheSections(): void {
		$reports = (new TrafficReportDefinitions())->reports(value: [
			['id' => 'w', 'name' => ' Weekly ', 'cadence' => 'weekly', 'recipients' => ['admin', 'a@example.org', 'bad address', '', 'admin'], 'sections' => ['pages', 'nope', 'overview']],
			['id' => 'no-recipients', 'cadence' => 'daily', 'recipients' => ['bad;address']],
			['id' => 'bad cadence', 'cadence' => 'hourly', 'recipients' => ['admin']],
			['id' => 'defaults', 'cadence' => 'monthly', 'recipients' => ['admin']],
		]);

		$this->assertSame(['w', 'defaults'], array_column($reports, 'id'));
		$this->assertSame('Weekly', $reports[0]['name']);
		$this->assertSame(['admin', 'a@example.org'], $reports[0]['recipients']);
		$this->assertSame(['overview', 'pages'], $reports[0]['sections'], 'known sections, in the rendering order');
		$this->assertSame(['overview'], $reports[1]['sections']);
		$this->assertSame('defaults', $reports[1]['name']);
	}//end testReportsKeepTheUsableAndDefaultTheSections()


	/**
	 * @return void
	 */
	public function testAlertsNeedAKnownMetricComparisonPeriodAndThreshold(): void {
		$goals = [['id' => 'contact']];
		$alerts = (new TrafficReportDefinitions())->alerts(value: [
			['id' => 'ok', 'metric' => 'pageViews', 'comparison' => 'above', 'threshold' => '100', 'recipients' => ['admin']],
			['id' => 'goal', 'metric' => 'goal:contact', 'comparison' => 'changeAbove', 'threshold' => 50, 'period' => 'week', 'recipients' => ['admin']],
			['id' => 'unknown-goal', 'metric' => 'goal:nope', 'comparison' => 'above', 'threshold' => 1, 'recipients' => ['admin']],
			['id' => 'bad-comparison', 'metric' => 'sessions', 'comparison' => 'equals', 'threshold' => 1, 'recipients' => ['admin']],
			['id' => 'bad-period', 'metric' => 'sessions', 'comparison' => 'above', 'threshold' => 1, 'period' => 'month', 'recipients' => ['admin']],
			['id' => 'no-threshold', 'metric' => 'sessions', 'comparison' => 'above', 'threshold' => 'lots', 'recipients' => ['admin']],
		], goals: $goals);

		$this->assertSame(['ok', 'goal'], array_column($alerts, 'id'));
		$this->assertSame(100.0, $alerts[0]['threshold']);
		$this->assertSame('day', $alerts[0]['period']);
		$this->assertSame('week', $alerts[1]['period']);
	}//end testAlertsNeedAKnownMetricComparisonPeriodAndThreshold()


	/**
	 * @return void
	 */
	public function testRollupOfDropsItselfAndRepeats(): void {
		$members = (new TrafficReportDefinitions())->rollupOf(value: ['open-tilburg', 'rollup', 'open-venray', 'open-tilburg', 'Bad Slug', 7], self: 'rollup');

		$this->assertSame(['open-tilburg', 'open-venray'], $members);
		$this->assertSame([], (new TrafficReportDefinitions())->rollupOf(value: 'open-tilburg', self: 'rollup'));
	}//end testRollupOfDropsItselfAndRepeats()


	/**
	 * Errors are grouped by message and source, ranked by hits, with the
	 * pages they happened on.
	 *
	 * @return void
	 */
	public function testErrorsAreGroupedAndRanked(): void {
		$event = static fn (string $message, string $page, string $source = 'x/app.js'): array => [
			'name' => 'js_error',
			'pagePath' => $page,
			'params' => ['message' => $message, 'source' => $source, 'line' => 1, 'column' => 2, 'stackHash' => 'abc'],
		];
		$rows = (new TrafficErrorStats())->rows(sessions: [
			['visitor' => 'a', 'explicit' => false, 'events' => [$event('boom', '/'), $event('boom', '/a'), ['name' => 'page_view', 'pagePath' => '/']]],
			['visitor' => 'b', 'explicit' => false, 'events' => [$event('boom', '/'), $event('', '/b', 'y/other.js')]],
		]);

		$this->assertSame([
			['message' => 'boom', 'source' => 'x/app.js', 'hits' => 3, 'pages' => ['/', '/a']],
			['message' => 'Script error', 'source' => 'y/other.js', 'hits' => 1, 'pages' => ['/b']],
		], $rows);
	}//end testErrorsAreGroupedAndRanked()


	/**
	 * The CSV has the header, one row per record, and quotes what needs
	 * quoting; the JSON drops the envelope.
	 *
	 * @return void
	 */
	public function testTheExportFormats(): void {
		$export = new TrafficExport();
		$records = [
			['@self' => ['uuid' => 'u1'], 'portal' => 'open-tilburg', 'date' => '2026-09-04', 'segment' => '', 'pageViews' => 12, 'sessions' => 5, 'visitors' => 4, 'newVisitors' => null, 'engagedSessions' => 3, 'avgEngagementSeconds' => 12.5, 'bounceRate' => 0.4, 'conversionRate' => 0.2, 'pages' => []],
			['portal' => 'open-tilburg', 'date' => '2026-09-05', 'segment' => 'desk,top', 'pageViews' => 1],
		];

		$csv = $export->csv(records: $records);
		$lines = explode("\r\n", trim($csv));
		$this->assertSame(implode(',', TrafficExport::COLUMNS), $lines[0]);
		$this->assertSame('open-tilburg,2026-09-04,,12,5,4,,,,3,12.5,0.4,0.2', $lines[1]);
		$this->assertSame('open-tilburg,2026-09-05,"desk,top",1,,,,,,,,,', $lines[2]);
		$this->assertCount(3, $lines);

		$json = json_decode($export->json(records: $records), true);
		$this->assertCount(2, $json);
		$this->assertArrayNotHasKey('@self', $json[0]);
		$this->assertSame([], $json[0]['pages']);
		$this->assertSame('traffic-open-tilburg-2026-09-04-2026-09-05-desk-top.csv', $export->fileName(portal: 'open-tilburg', from: '2026-09-04', to: '2026-09-05', segment: 'desk,top', format: 'csv'));
	}//end testTheExportFormats()
}//end class
