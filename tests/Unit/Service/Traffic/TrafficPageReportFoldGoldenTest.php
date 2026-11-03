<?php

/**
 * TrafficPageReportFoldGoldenTest
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

use OCA\Portaliq\Service\Traffic\TrafficPageReport;
use PHPUnit\Framework\TestCase;

/**
 * One page's figures folded from a portal's daily records, pinned per
 * record set: counts, detail days, transitions, referrers and outbound links.
 *
 * @covers \OCA\Portaliq\Service\Traffic\TrafficPageReport
 * @uses   \OCA\Portaliq\Service\Traffic\TrafficPagePath
 */
class TrafficPageReportFoldGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../../Fixtures/Golden/traffic-page-report-fold.json';

	/**
	 * Every record set's fold matches the recorded one.
	 *
	 * @return void
	 */
	public function testEveryFoldMatchesTheGolden(): void {
		$report = new TrafficPageReport();
		$actual = [];
		foreach ($this->recordSets() as $name => $records) {
			foreach (['/diensten', '/'] as $route) {
				$actual[$name . ' | ' . $route] = $report->fold(records: $records, route: $route);
			}
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $name => $case) {
			$this->assertSame($case, $actual[$name], $name);
		}
	}//end testEveryFoldMatchesTheGolden()

	/**
	 * A detailed page row.
	 *
	 * @param string               $path  The row's path.
	 * @param array<string, mixed> $extra More fields.
	 *
	 * @return array<string, mixed>
	 */
	private function row(string $path, array $extra = []): array {
		return array_merge(
			[
				'path' => $path, 'views' => 5, 'entrances' => 2, 'exits' => 1, 'sessions' => 4, 'visitors' => 3, 'engagedSessions' => 2,
				'referrers' => [['host' => 'example.org', 'channel' => 'referral', 'count' => 2], ['host' => 'search.example', 'channel' => 'search', 'count' => 1]],
				'outbound' => [['url' => 'https://elders.example/a', 'count' => 1]],
			],
			$extra
		);
	}//end row()

	/**
	 * Record sets by name.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function recordSets(): array {
		$transitions = [
			['from' => '/', 'to' => '/diensten', 'count' => 3],
			['from' => '/diensten/', 'to' => 'contact', 'count' => 2],
			['from' => '/diensten', 'to' => '/diensten', 'count' => 9],
			['from' => '/nieuws', 'to' => 'diensten', 'count' => '4'],
			['from' => '/diensten', 'to' => '/', 'count' => -2],
			'not a row',
		];

		return [
			'no records'          => [],
			'one detailed day'    => [['pages' => [$this->row('/diensten'), $this->row('/')], 'transitions' => $transitions]],
			'trailing slash rows' => [['pages' => [$this->row('/diensten/'), $this->row('diensten', ['views' => '7', 'sessions' => 'x'])]]],
			'older day no detail' => [['pages' => [['path' => '/diensten', 'views' => 3, 'entrances' => 1, 'exits' => 1]]]],
			'not viewed that day' => [['pages' => [$this->row('/elders')]], ['pages' => []], ['transitions' => 'none']],
			'not viewed, old day' => [['pages' => [['path' => '/elders', 'views' => 1]]]],
			'mixed days'          => [
				['pages' => [$this->row('/diensten'), $this->row('/')], 'transitions' => $transitions],
				['pages' => [['path' => '/diensten', 'views' => 2, 'entrances' => 0, 'exits' => 2], 'junk']],
				['pages' => [$this->row('/diensten', ['referrers' => 'none', 'outbound' => [['url' => 'https://elders.example/a', 'count' => 4], 'junk', ['count' => 1]]])]],
			],
			'many referrers'      => [['pages' => [$this->row('/diensten', ['referrers' => array_map(static fn (int $i): array => ['host' => 'h' . $i . '.example', 'channel' => 'referral', 'count' => $i], range(1, 12))])]]],
			'negative counts'     => [['pages' => [$this->row('/diensten', ['views' => -4, 'entrances' => null, 'exits' => 1.9])]]],
		];
	}//end recordSets()
}//end class
