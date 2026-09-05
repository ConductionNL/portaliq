<?php

/**
 * Unit tests for TrafficReportService.
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

namespace OCA\Portaliq\Tests\Unit\Service;

use DateTime;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Traffic\TrafficEventStore;
use OCA\Portaliq\Service\Traffic\TrafficReportDelivery;
use OCA\Portaliq\Service\Traffic\TrafficReportNumbers;
use OCA\Portaliq\Service\Traffic\TrafficReportPeriods;
use OCA\Portaliq\Service\TrafficConfigResolver;
use OCA\Portaliq\Service\TrafficReportService;
use OCA\Portaliq\Tests\Unit\Service\Traffic\FakeAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

// The test tree is not autoloaded; the double is a plain include.
require_once __DIR__ . '/Traffic/FakeAppConfig.php';

/**
 * Due-ness: a report is sent once per period key and an alert fires once
 * per period, whatever the job's other runs see; the numbers handed to
 * delivery are the period's, against the one before.
 */
class TrafficReportServiceTest extends TestCase {

	/**
	 * The daily records the fake store holds.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $daily = [];

	/**
	 * What delivery was asked to send: `[kind, portal, definition id, period, current, previous|value]`.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $sent = [];

	/**
	 * The shared app config.
	 *
	 * @var FakeAppConfig
	 */
	private FakeAppConfig $config;

	/**
	 * The frozen clock: Wednesday 2026-09-09 10:30:00 UTC.
	 */
	private const NOW = 1788949800;


	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->daily = [];
		$this->sent = [];
		$this->config = new FakeAppConfig();
	}//end setUp()


	/**
	 * One day's record for the seeded portal.
	 *
	 * @param string $date      The day.
	 * @param int    $pageViews The page views.
	 *
	 * @return array<string, mixed> The record.
	 */
	private function day(string $date, int $pageViews): array {
		return [
			'portal' => 'open-tilburg',
			'date' => $date,
			'segment' => '',
			'pageViews' => $pageViews,
			'sessions' => (int)ceil($pageViews / 2),
			'visitors' => 1,
			'engagedSessions' => 1,
			'goals' => [['id' => 'contact', 'name' => 'Contact', 'conversions' => 1, 'completions' => 1, 'value' => 0.0]],
			'notFound' => [['path' => '/oud', 'hits' => 2]],
		];
	}//end day()


	/**
	 * The service over one portal with the given traffic block.
	 *
	 * @param array<string, mixed> $traffic The portal's traffic block.
	 * @param int                  $now     The clock.
	 *
	 * @return TrafficReportService The service.
	 */
	private function service(array $traffic, int $now = self::NOW): TrafficReportService {
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('allPublishedPortals')->willReturn([
			['slug' => 'open-tilburg', 'title' => 'Open Tilburg', 'status' => 'published', 'traffic' => ['enabled' => true] + $traffic],
		]);

		$store = $this->createMock(TrafficEventStore::class);
		$store->method('dailyBetween')->willReturnCallback(
			fn (string $portal, string $from, string $to, string $segment = ''): array => array_values(array_filter(
				$this->daily,
				static fn (array $r): bool => $r['portal'] === $portal && $r['date'] >= $from && $r['date'] <= $to
			))
		);

		$delivery = $this->createMock(TrafficReportDelivery::class);
		$delivery->method('sendReport')->willReturnCallback(
			function (array $portal, array $report, array $period, array $current, array $previous): int {
				$this->sent[] = ['report', $portal['slug'], $report['id'], $period, $current, $previous];

				return 1;
			}
		);
		$delivery->method('sendAlert')->willReturnCallback(
			function (array $portal, array $alert, array $period, float $value, ?float $change): int {
				$this->sent[] = ['alert', $portal['slug'], $alert['id'], $period, $value, $change];

				return 1;
			}
		);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturn($now);
		$clock->method('getDateTime')->willReturn(new DateTime('@' . $now));

		return new TrafficReportService(
			$portals,
			new TrafficConfigResolver(),
			$store,
			new TrafficReportPeriods(),
			new TrafficReportNumbers(),
			$delivery,
			$this->config->mock($this),
			$clock,
			$this->createMock(LoggerInterface::class)
		);
	}//end service()


	/**
	 * A daily report goes out once for yesterday with yesterday's numbers
	 * against the day before, and not again the same day.
	 *
	 * @return void
	 */
	public function testADailyReportIsSentOncePerDayWithThePeriodsNumbers(): void {
		$this->daily = [$this->day('2026-09-07', 4), $this->day('2026-09-08', 10), $this->day('2026-09-09', 100)];
		$service = $this->service(['reports' => [['id' => 'd', 'name' => 'Daily', 'cadence' => 'daily', 'recipients' => ['admin']]]]);

		$first = $service->run();
		$second = $service->run();

		$this->assertSame(['reports' => 1, 'alerts' => 0], $first);
		$this->assertSame(['reports' => 0, 'alerts' => 0], $second, 'the same period is not sent twice');
		$this->assertCount(1, $this->sent);
		[, $portal, $id, $period, $current, $previous] = $this->sent[0];
		$this->assertSame('open-tilburg', $portal);
		$this->assertSame('d', $id);
		$this->assertSame('2026-09-08', $period['key']);
		$this->assertSame('2026-09-08', $period['from']);
		$this->assertSame('2026-09-07', $period['previousTo']);
		$this->assertSame(10, $current['pageViews'], 'yesterday, not today');
		$this->assertSame(4, $previous['pageViews']);
		$this->assertSame('2026-09-08', $this->config->values['portaliq/traffic_report_open-tilburg_d']);
	}//end testADailyReportIsSentOncePerDayWithThePeriodsNumbers()


	/**
	 * The next day the daily report is due again; a weekly report covers
	 * the ISO week that ended on Sunday and is due once for that week.
	 *
	 * @return void
	 */
	public function testTheNextPeriodIsDueAgainAndAWeeklyReportCoversLastWeek(): void {
		$reports = ['reports' => [
			['id' => 'd', 'name' => 'Daily', 'cadence' => 'daily', 'recipients' => ['admin']],
			['id' => 'w', 'name' => 'Weekly', 'cadence' => 'weekly', 'recipients' => ['a@example.org'], 'sections' => ['overview', 'pages']],
		]];
		$this->service($reports)->run();
		$this->assertCount(2, $this->sent);
		$weekly = $this->sent[1];
		$this->assertSame('w', $weekly[2]);
		$this->assertSame('2026-W36', $weekly[3]['key']);
		$this->assertSame('2026-08-31', $weekly[3]['from']);
		$this->assertSame('2026-09-06', $weekly[3]['to']);
		$this->assertSame('2026-08-24', $weekly[3]['previousFrom']);

		// A day later: the daily is due once more, the weekly is not.
		$this->service($reports, now: self::NOW + 86400)->run();
		$this->assertCount(3, $this->sent);
		$this->assertSame('d', $this->sent[2][2]);
		$this->assertSame('2026-09-09', $this->sent[2][3]['key']);
	}//end testTheNextPeriodIsDueAgainAndAWeeklyReportCoversLastWeek()


	/**
	 * An `above` alert watches today, fires the first run its threshold is
	 * crossed, and stays quiet for the rest of the day even as the number
	 * keeps growing.
	 *
	 * @return void
	 */
	public function testAnAboveAlertFiresOncePerDay(): void {
		$this->daily = [$this->day('2026-09-09', 5)];
		$alerts = ['alerts' => [['id' => 'busy', 'name' => 'Busy', 'metric' => 'pageViews', 'comparison' => 'above', 'threshold' => 50, 'period' => 'day', 'recipients' => ['admin']]]];

		$this->assertSame(0, $this->service($alerts)->run()['alerts'], 'below the threshold: quiet');

		$this->daily = [$this->day('2026-09-09', 60)];
		$this->assertSame(1, $this->service($alerts)->run()['alerts'], 'crossed: fires');
		$this->assertSame(60.0, $this->sent[0][4]);
		$this->assertSame('2026-09-09', $this->sent[0][3]['key']);

		$this->daily = [$this->day('2026-09-09', 600)];
		$this->assertSame(0, $this->service($alerts)->run()['alerts'], 'still above, same day: not again');
		$this->daily[] = $this->day('2026-09-10', 70);
		$this->assertSame(1, $this->service($alerts, now: self::NOW + 86400)->run()['alerts'], 'a new day, a new period');
		$this->assertCount(2, $this->sent);
	}//end testAnAboveAlertFiresOncePerDay()


	/**
	 * `below` judges the last complete period; `changeAbove` compares it
	 * with the one before and stays quiet when there is nothing to compare
	 * with. A goal metric reads that goal's conversions.
	 *
	 * @return void
	 */
	public function testBelowAndChangeAboveJudgeTheLastCompletePeriod(): void {
		$this->daily = [$this->day('2026-09-07', 4), $this->day('2026-09-08', 10), $this->day('2026-09-09', 0)];
		$alerts = ['alerts' => [
			['id' => 'quiet', 'name' => 'Quiet', 'metric' => 'sessions', 'comparison' => 'below', 'threshold' => 100, 'period' => 'day', 'recipients' => ['admin']],
			['id' => 'jump', 'name' => 'Jump', 'metric' => 'pageViews', 'comparison' => 'changeAbove', 'threshold' => 100, 'period' => 'day', 'recipients' => ['admin']],
			['id' => 'goal', 'name' => 'Goal', 'metric' => 'goal:contact', 'comparison' => 'above', 'threshold' => 0, 'period' => 'week', 'recipients' => ['admin']],
			['id' => 'lost', 'name' => 'Lost', 'metric' => 'notFound', 'comparison' => 'above', 'threshold' => 1, 'period' => 'day', 'recipients' => ['admin']],
			['id' => 'nothing-before', 'name' => 'No base', 'metric' => 'visitors', 'comparison' => 'changeAbove', 'threshold' => 1, 'period' => 'week', 'recipients' => ['admin']],
		]];
		$this->daily[0]['goals'] = [];

		$result = $this->service(['goals' => [['id' => 'contact', 'type' => 'page_reached', 'match' => ['pathEquals' => '/contact']]]] + $alerts)->run();

		$this->assertSame(4, $result['alerts']);
		$ids = array_column($this->sent, 2);
		$this->assertSame(['quiet', 'jump', 'goal', 'lost'], $ids);
		$this->assertSame('2026-09-08', $this->sent[0][3]['key'], 'below: yesterday, the last complete day');
		$this->assertSame(5.0, $this->sent[0][4]);
		$this->assertSame(150.0, $this->sent[1][5], 'from 4 to 10 is +150%');
		$this->assertSame('2026-W37', $this->sent[2][3]['key'], 'a week alert on `above` watches the current ISO week');
		$this->assertSame(2.0, $this->sent[2][4], 'the goal converted on the two days of this week that have records');
		$this->assertSame(2.0, $this->sent[3][4], 'yesterday had no missing pages; today has two');
	}//end testBelowAndChangeAboveJudgeTheLastCompletePeriod()


	/**
	 * A report or alert that cannot be acted on is not in the resolved
	 * block, so nothing is sent for it and nothing is recorded.
	 *
	 * @return void
	 */
	public function testUnusableDefinitionsAreIgnored(): void {
		$result = $this->service([
			'reports' => [['id' => 'no-recipients', 'cadence' => 'daily', 'recipients' => []], ['id' => 'bad-cadence', 'cadence' => 'hourly', 'recipients' => ['admin']]],
			'alerts' => [['id' => 'bad-metric', 'metric' => 'shoeSize', 'comparison' => 'above', 'threshold' => 1, 'recipients' => ['admin']]],
		])->run();

		$this->assertSame(['reports' => 0, 'alerts' => 0], $result);
		$this->assertSame([], $this->sent);
		$this->assertSame([], $this->config->values);
	}//end testUnusableDefinitionsAreIgnored()
}//end class
