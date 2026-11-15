<?php

/**
 * AvailabilityReportGoldenTest
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

namespace OCA\Portaliq\Tests\Unit\Service\Availability;

use DateTimeImmutable;
use OCA\Portaliq\Service\Availability\AvailabilityReport;
use OCA\Portaliq\Service\Availability\AvailabilityStore;
use PHPUnit\Framework\TestCase;

/**
 * The monthly report and the store reads behind it, per period, clamp and stored day.
 *
 * @covers \OCA\Portaliq\Service\Availability\AvailabilityReport
 */
class AvailabilityReportGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../../Fixtures/Golden/availability-report.json';

	/**
	 * Every scenario's report matches the recorded one.
	 *
	 * @return void
	 */
	public function testEveryScenarioMatchesTheGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => $scenario) {
			$actual[$name] = $this->trace(scenario: $scenario);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
		foreach ($expected as $name => $case) {
			$this->assertSame(expected: $case, actual: json_decode((string)json_encode($actual[$name], JSON_PRESERVE_ZERO_FRACTION), true), message: $name);
		}
	}//end testEveryScenarioMatchesTheGolden()

	/**
	 * Build one report over a scripted store.
	 *
	 * @param array<string, mixed> $scenario The moment, the period and the stored rows.
	 *
	 * @return array<string, mixed>
	 */
	private function trace(array $scenario): array {
		$log   = [];
		$store = $this->createMock(originalClassName: AvailabilityStore::class);
		$store->method('dailyBetween')->willReturnCallback(static function (string $portal, string $from, string $until) use (&$log, $scenario): array {
			$log[] = ['daily', $portal, $from, $until];
			return ($scenario['daily'] ?? []);
		});
		$store->method('outagesBetween')->willReturnCallback(static function (string $portal, string $from, string $until) use (&$log, $scenario): array {
			$log[] = ['outages', $portal, $from, $until];
			return ($scenario['outages'] ?? []);
		});

		$now = new DateTimeImmutable(($scenario['now'] ?? '2026-11-03T10:00:00+01:00'));
		$report = new AvailabilityReport(store: $store);
		if (array_key_exists('months', $scenario) === true) {
			$result = $report->forPortal(portal: 'zuid', now: $now, months: $scenario['months']);
		} else {
			$result = $report->forPortal(portal: 'zuid', now: $now);
		}

		return ['report' => $result, 'log' => $log];
	}//end trace()

	/**
	 * The scenarios by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$days = [
			'2026-10-01' => ['intervals' => 288, 'available' => 280, 'degraded' => 5, 'down' => 3, 'noCheck' => 0],
			'2026-10-02' => ['intervals' => '288', 'available' => 288],
			'2026-09-30' => ['intervals' => 100, 'available' => 33, 'degraded' => 1, 'down' => 66, 'noCheck' => 7],
			'2025-10-31' => ['intervals' => 10, 'available' => 10],
			'2026-11-01' => ['intervals' => 10, 'available' => 0],
			'odd'        => ['intervals' => 5],
		];
		$outages = [
			['startedAt' => '2026-10-01T02:00:00+00:00', 'endedAt' => '2026-10-01T02:15:00+00:00', 'durationMinutes' => 15, 'cause' => 'down'],
			['startedAt' => '2026-09-30T08:00:00+00:00', 'durationMinutes' => '90'],
			['durationMinutes' => 90, 'cause' => 'tie'],
			[],
		];

		return [
			'default year, nothing stored' => [],
			'one month'                    => ['months' => 1, 'daily' => $days, 'outages' => $outages],
			'three months'                 => ['months' => 3, 'daily' => $days, 'outages' => $outages],
			'zero months clamps to one'    => ['months' => 0, 'daily' => $days],
			'negative months'              => ['months' => -4],
			'thirteen months clamps'       => ['months' => 13, 'daily' => $days, 'outages' => $outages],
			'twelve months'                => ['months' => 12, 'daily' => $days, 'outages' => $outages],
			'zone shifts the month'        => ['now' => '2026-11-01T00:30:00+02:00', 'months' => 2, 'daily' => $days],
			'end of march'                 => [
				'now'    => '2026-03-31T23:59:59+00:00',
				'months' => 2,
				'daily'  => ['2026-02-28' => ['intervals' => 3, 'available' => 1]],
			],
			'leap year february'           => [
				'now'    => '2024-03-15T12:00:00Z',
				'months' => 1,
				'daily'  => ['2024-02-29' => ['intervals' => 7, 'available' => 7]],
			],
			'single interval'              => ['months' => 1, 'daily' => ['2026-10-05' => ['intervals' => 1, 'available' => 1]]],
			'only outages'                 => ['months' => 2, 'outages' => [['durationMinutes' => 0], ['durationMinutes' => 5, 'startedAt' => 'x']]],
		];
	}//end scenarios()
}//end class
