<?php

/**
 * Unit tests for TrafficReportMail, TrafficReportNumbers and TrafficReportPeriods.
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

use DateTimeImmutable;
use OCA\Portaliq\Service\Traffic\TrafficReportMail;
use OCA\Portaliq\Service\Traffic\TrafficReportNumbers;
use OCA\Portaliq\Service\Traffic\TrafficReportPeriods;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The words carry the numbers, the numbers come from the records, and
 * the periods are the calendar's.
 */
class TrafficReportMailTest extends TestCase {

	/**
	 * A pass-through translator.
	 *
	 * @return IL10N The double.
	 */
	private function l10n(): IL10N {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));

		return $l;
	}//end l10n()


	/**
	 * The mail's overview line carries this period's figure, the change
	 * and the previous figure; the sections follow the report's list.
	 *
	 * @return void
	 */
	public function testTheMailCarriesTheNumbersAgainstThePreviousPeriod(): void {
		$numbers = new TrafficReportNumbers();
		$current = $numbers->fold(records: [
			['pageViews' => 100, 'sessions' => 40, 'visitors' => 30, 'engagedSessions' => 20, 'pages' => [['path' => '/', 'views' => 60]], 'referrers' => [['host' => 'google.nl', 'channel' => 'organic', 'count' => 5]]],
			['pageViews' => 24, 'sessions' => 10, 'visitors' => 8, 'engagedSessions' => 5, 'pages' => [['path' => '/', 'views' => 10], ['path' => '/a', 'views' => 14]]],
		]);
		$previous = $numbers->fold(records: [['pageViews' => 62, 'sessions' => 50, 'visitors' => 0, 'engagedSessions' => 25]]);
		$report = ['id' => 'w', 'name' => 'Weekly', 'sections' => ['overview', 'pages', 'sources', 'goals']];
		$period = ['key' => '2026-W36', 'from' => '2026-08-31', 'to' => '2026-09-06', 'previousFrom' => '2026-08-24', 'previousTo' => '2026-08-30'];
		$mail = new TrafficReportMail();

		$subject = $mail->subject(l: $this->l10n(), portal: ['title' => 'Open Tilburg'], report: $report, period: $period);
		$sections = $mail->sections(l: $this->l10n(), report: $report, period: $period, current: $current, previous: $previous);
		$plain = $mail->plain(sections: $sections, link: 'https://x/traffic', linkText: 'Open the Traffic page');

		$this->assertSame('Open Tilburg: Weekly (2026-08-31 to 2026-09-06)', $subject);
		$this->assertSame(['Overview', 'Pages', 'Sources', 'Goals', 'Period'], array_column($sections, 'heading'));
		$this->assertSame('Page views: 124 (+100% on the previous period, 62)', $sections[0]['lines'][0]);
		$this->assertSame('Sessions: 50 (+0% on the previous period, 50)', $sections[0]['lines'][1]);
		$this->assertSame('Visitors: 38 (no figure for the previous period)', $sections[0]['lines'][2]);
		$this->assertSame('Engaged sessions: 25 (+0% on the previous period, 25)', $sections[0]['lines'][3]);
		$this->assertSame('Bounce rate: 50%', $sections[0]['lines'][4]);
		$this->assertSame(['/: 70', '/a: 14'], $sections[1]['lines'], 'pages merge across the days and rank by views');
		$this->assertSame(['organic: 5'], $sections[2]['lines']);
		$this->assertSame(['No goals are defined for this portal.'], $sections[3]['lines']);
		$this->assertStringContainsString("Overview\n--------\nPage views: 124", $plain);
		$this->assertStringContainsString('Open the Traffic page: https://x/traffic', $plain);
	}//end testTheMailCarriesTheNumbersAgainstThePreviousPeriod()


	/**
	 * The metric arithmetic an alert reads.
	 *
	 * @return void
	 */
	public function testMetricsAndChange(): void {
		$numbers = new TrafficReportNumbers();
		$records = [
			['pageViews' => 3, 'goals' => [['id' => 'a', 'conversions' => 2]], 'notFound' => [['path' => '/x', 'hits' => 4]]],
			['pageViews' => 4, 'goals' => [['id' => 'a', 'conversions' => 1], ['id' => 'b', 'conversions' => 9]]],
		];

		$this->assertSame(7.0, $numbers->metric(metric: 'pageViews', records: $records));
		$this->assertSame(3.0, $numbers->metric(metric: 'goal:a', records: $records));
		$this->assertSame(4.0, $numbers->metric(metric: 'notFound', records: $records));
		$this->assertSame(0.0, $numbers->metric(metric: 'sessions', records: $records));
		$this->assertSame(50.0, $numbers->change(current: 15, previous: 10));
		$this->assertSame(-25.0, $numbers->change(current: 7.5, previous: 10));
		$this->assertNull($numbers->change(current: 5, previous: 0));
	}//end testMetricsAndChange()


	/**
	 * The calendar: yesterday, the ISO week that ended, the previous month
	 * (with February's length respected), and the current periods an
	 * `above` alert watches.
	 *
	 * @return void
	 */
	public function testPeriodsFollowTheCalendar(): void {
		$periods = new TrafficReportPeriods();
		$wednesday = new DateTimeImmutable('2026-09-09 10:30:00', new \DateTimeZone('UTC'));

		$this->assertSame(
			['key' => '2026-09-08', 'from' => '2026-09-08', 'to' => '2026-09-08', 'previousFrom' => '2026-09-07', 'previousTo' => '2026-09-07'],
			$periods->reportPeriod(cadence: 'daily', now: $wednesday)
		);
		$this->assertSame(
			['key' => '2026-W36', 'from' => '2026-08-31', 'to' => '2026-09-06', 'previousFrom' => '2026-08-24', 'previousTo' => '2026-08-30'],
			$periods->reportPeriod(cadence: 'weekly', now: $wednesday)
		);
		$this->assertSame(
			['key' => '2026-08', 'from' => '2026-08-01', 'to' => '2026-08-31', 'previousFrom' => '2026-07-01', 'previousTo' => '2026-07-31'],
			$periods->reportPeriod(cadence: 'monthly', now: $wednesday)
		);
		$march = new DateTimeImmutable('2026-03-15 00:00:00', new \DateTimeZone('UTC'));
		$this->assertSame('2026-02-28', $periods->reportPeriod(cadence: 'monthly', now: $march)['to']);
		$monday = new DateTimeImmutable('2026-09-07 00:10:00', new \DateTimeZone('UTC'));
		$this->assertSame('2026-09-06', $periods->reportPeriod(cadence: 'weekly', now: $monday)['to'], 'on Monday the week that just ended is due');

		$this->assertSame(
			['key' => '2026-09-09', 'from' => '2026-09-09', 'to' => '2026-09-09', 'previousFrom' => '2026-09-08', 'previousTo' => '2026-09-08'],
			$periods->alertPeriod(period: 'day', comparison: 'above', now: $wednesday)
		);
		$this->assertSame(
			['key' => '2026-W37', 'from' => '2026-09-07', 'to' => '2026-09-09', 'previousFrom' => '2026-09-04', 'previousTo' => '2026-09-06'],
			$periods->alertPeriod(period: 'week', comparison: 'above', now: $wednesday)
		);
		$this->assertSame('2026-09-08', $periods->alertPeriod(period: 'day', comparison: 'below', now: $wednesday)['key']);
		$this->assertSame('2026-W36', $periods->alertPeriod(period: 'week', comparison: 'changeAbove', now: $wednesday)['key']);
	}//end testPeriodsFollowTheCalendar()
}//end class
