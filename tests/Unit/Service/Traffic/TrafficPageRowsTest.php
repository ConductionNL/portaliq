<?php

/**
 * Unit tests for the per-page figures of the daily record
 * (portal-page-traffic).
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

use OCA\Portaliq\Service\Traffic\TrafficRollup;
use OCA\Portaliq\Service\Traffic\TrafficRollupSum;
use OCA\Portaliq\Service\Traffic\TrafficSessioniser;
use PHPUnit\Framework\TestCase;

/**
 * Each page row counts its own sessions, visitors, engaged sessions,
 * sources and outbound links, keyed by the in-site route, and a roll-up
 * never sums a figure one member lacked.
 */
class TrafficPageRowsTest extends TestCase {

	/**
	 * One event on a site with its own domain.
	 *
	 * @param string               $at      occurredAt.
	 * @param string               $path    The page path.
	 * @param string               $visitor visitorHash.
	 * @param string               $name    The event name.
	 * @param array<string, mixed> $extra   More fields.
	 *
	 * @return array<string, mixed> The event.
	 */
	private function event(string $at, string $path, string $visitor, string $name = 'page_view', array $extra = []): array {
		return $extra + [
			'name' => $name,
			'occurredAt' => $at,
			'sequence' => 0,
			'pagePath' => $path,
			'pageLocation' => 'https://open-tilburg.nl' . $path,
			'visitorHash' => $visitor,
		];
	}//end event()


	/**
	 * Roll events up as one day.
	 *
	 * @param array<int, array<string, mixed>> $events The events.
	 *
	 * @return array<string, mixed> The record.
	 */
	private function rollup(array $events): array {
		$sessions = (new TrafficSessioniser())->sessions(events: $events, timeoutMinutes: 30);

		return (new TrafficRollup())->build(portal: 'open-tilburg', date: '2026-09-04', sessions: $sessions, aggregatedAt: '2026-09-05T00:15:00Z');
	}//end rollup()


	/**
	 * A page row by path.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param string               $path   The path.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function page(array $record, string $path): array {
		foreach ($record['pages'] as $row) {
			if ($row['path'] === $path) {
				return $row;
			}
		}

		$this->fail('no page row for ' . $path . ': ' . json_encode(array_column($record['pages'], 'path')));
	}//end page()


	/**
	 * The spec's example: a brief visit from Google and an engaged visit
	 * with an outbound click, both on `/contact`.
	 *
	 * @return void
	 */
	public function testAPageRowCountsItsOwnSessionsAndSources(): void {
		$record = $this->rollup(
			events: [
				$this->event(at: '2026-09-04T10:00:00.000Z', path: '/contact', visitor: 'h1', extra: ['referrerHost' => 'www.google.com', 'channel' => 'organic']),
				$this->event(at: '2026-09-04T11:00:00.000Z', path: '/contact', visitor: 'h2', extra: ['channel' => 'direct']),
				$this->event(at: '2026-09-04T11:00:05.000Z', path: '/contact', visitor: 'h2'),
				$this->event(at: '2026-09-04T11:00:06.000Z', path: '/contact', visitor: 'h2', name: 'outbound_click', extra: ['linkUrl' => 'https://www.tilburg.nl/']),
			]
		);

		$row = $this->page(record: $record, path: '/contact');
		$this->assertSame(3, $row['views']);
		$this->assertSame(2, $row['sessions']);
		$this->assertSame(2, $row['visitors']);
		$this->assertSame(1, $row['engagedSessions']);
		$this->assertContains(['host' => 'www.google.com', 'channel' => 'organic', 'count' => 1], $row['referrers']);
		$this->assertContains(['host' => '', 'channel' => 'direct', 'count' => 1], $row['referrers']);
		$this->assertSame([['url' => 'https://www.tilburg.nl/', 'count' => 1]], $row['outbound']);
	}//end testAPageRowCountsItsOwnSessionsAndSources()


	/**
	 * The built-in site: every view shares the renderer's path, and the
	 * route parameter tells them apart. The transition follows the routes.
	 *
	 * @return void
	 */
	public function testTheBuiltInSiteCountsEachPageByItsRoute(): void {
		$site = 'https://cloud.example/index.php/apps/portaliq/site?portal=open-tilburg';
		$record = $this->rollup(
			events: [
				$this->event(at: '2026-09-04T10:00:00.000Z', path: '/index.php/apps/portaliq/site', visitor: 'h1', extra: ['pageLocation' => $site]),
				$this->event(at: '2026-09-04T10:00:20.000Z', path: '/index.php/apps/portaliq/site', visitor: 'h1', extra: ['pageLocation' => $site . '&route=%2Fcontact']),
			]
		);

		$this->assertSame(['/', '/contact'], array_values(array_map(static fn (array $r): string => $r['path'], $record['pages'])));
		$this->assertSame([['from' => '/', 'to' => '/contact', 'count' => 1]], $record['transitions']);
	}//end testTheBuiltInSiteCountsEachPageByItsRoute()


	/**
	 * `/contact/` and `/contact` are one row.
	 *
	 * @return void
	 */
	public function testATrailingSlashIsOnePage(): void {
		$record = $this->rollup(
			events: [
				$this->event(at: '2026-09-04T10:00:00.000Z', path: '/contact/', visitor: 'h1'),
				$this->event(at: '2026-09-04T11:00:00.000Z', path: '/contact', visitor: 'h2'),
			]
		);

		$this->assertCount(1, $record['pages']);
		$this->assertSame(2, $this->page(record: $record, path: '/contact')['views']);
	}//end testATrailingSlashIsOnePage()


	/**
	 * A roll-up sums the per-page figures when every member has them, and
	 * leaves them out when one member's row predates them.
	 *
	 * @return void
	 */
	public function testARollupLeavesOutAFigureAMemberLacks(): void {
		$new = [
			'path' => '/contact',
			'views' => 3,
			'entrances' => 2,
			'exits' => 2,
			'avgEngagementSeconds' => 5.0,
			'sessions' => 2,
			'visitors' => 2,
			'engagedSessions' => 1,
			'referrers' => [['host' => 'www.google.com', 'channel' => 'organic', 'count' => 1]],
			'outbound' => [['url' => 'https://www.tilburg.nl/', 'count' => 1]],
		];
		$old = ['path' => '/contact', 'views' => 4, 'entrances' => 1, 'exits' => 1, 'avgEngagementSeconds' => 5.0];
		$sum = new TrafficRollupSum();

		$both = $sum->sum(portal: 'all', date: '2026-09-04', members: ['a', 'b'], records: [['pages' => [$new]], ['pages' => [$new]]], aggregatedAt: '');
		$this->assertSame(4, $both['pages'][0]['sessions']);
		$this->assertSame(2, $both['pages'][0]['engagedSessions']);
		$this->assertSame(2, $both['pages'][0]['referrers'][0]['count']);
		$this->assertSame(2, $both['pages'][0]['outbound'][0]['count']);

		$mixed = $sum->sum(portal: 'all', date: '2026-09-04', members: ['a', 'b'], records: [['pages' => [$new]], ['pages' => [$old]]], aggregatedAt: '');
		$this->assertSame(7, $mixed['pages'][0]['views']);
		$this->assertArrayNotHasKey('sessions', $mixed['pages'][0]);
		$this->assertArrayNotHasKey('referrers', $mixed['pages'][0]);
	}//end testARollupLeavesOutAFigureAMemberLacks()
}//end class
