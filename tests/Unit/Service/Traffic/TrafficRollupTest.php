<?php

/**
 * Unit tests for TrafficRollup.
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
use OCA\Portaliq\Service\Traffic\TrafficSessioniser;
use PHPUnit\Framework\TestCase;

/**
 * The day's numbers, computed from sessions.
 */
class TrafficRollupTest extends TestCase {

	/**
	 * One event.
	 *
	 * @param string               $at      occurredAt.
	 * @param string               $path    pagePath.
	 * @param string               $visitor visitorHash.
	 * @param string               $name    The event name.
	 * @param array<string, mixed> $extra   More fields.
	 *
	 * @return array<string, mixed> The event.
	 */
	private function event(string $at, string $path, string $visitor = 'h1', string $name = 'page_view', array $extra = []): array {
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
	 * Roll a list of events up as one day.
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
	 * The journey `/` then `/begrippen`: one session, two views, the
	 * entrance, the exit and the transition between them are derivable.
	 *
	 * @return void
	 */
	public function testTwoPagesInOrderGiveAnEntranceAnExitAndATransition(): void {
		$record = $this->rollup([
			$this->event('2026-09-04T10:00:00.000Z', '/'),
			$this->event('2026-09-04T10:00:30.000Z', '/begrippen'),
		]);

		$this->assertSame('open-tilburg', $record['portal']);
		$this->assertSame('2026-09-04', $record['date']);
		$this->assertSame(2, $record['pageViews']);
		$this->assertSame(1, $record['sessions']);
		$this->assertSame(1, $record['visitors']);
		$this->assertSame(1, $record['newVisitors'], 'a cookieless visitor is new by construction');
		$this->assertSame(1, $record['engagedSessions'], 'two pages is engaged');
		$this->assertSame(30.0, $record['avgEngagementSeconds']);
		$this->assertSame(0.0, $record['bounceRate']);
		$this->assertSame(['page_view' => 2], $record['events']);

		$pages = [];
		foreach ($record['pages'] as $page) {
			$pages[$page['path']] = $page;
		}

		$this->assertSame(1, $pages['/']['entrances']);
		$this->assertSame(0, $pages['/']['exits']);
		$this->assertSame(30.0, $pages['/']['avgEngagementSeconds'], 'time on / is until the next page view');
		$this->assertSame(0, $pages['/begrippen']['entrances']);
		$this->assertSame(1, $pages['/begrippen']['exits']);
		$this->assertSame([['from' => '/', 'to' => '/begrippen', 'count' => 1]], $record['transitions']);
		$this->assertSame('2026-09-04T10:00:30.000Z', $record['lastEventAt']);
		$this->assertSame('2026-09-05T00:15:00Z', $record['aggregatedAt']);
	}//end testTwoPagesInOrderGiveAnEntranceAnExitAndATransition()


	/**
	 * A single page view with nothing after it is a bounce, and its page has
	 * no measurable time.
	 *
	 * @return void
	 */
	public function testASinglePageViewIsABounce(): void {
		$record = $this->rollup([$this->event('2026-09-04T10:00:00.000Z', '/')]);

		$this->assertSame(1, $record['sessions']);
		$this->assertSame(0, $record['engagedSessions']);
		$this->assertSame(1.0, $record['bounceRate']);
		$this->assertSame(0.0, $record['pages'][0]['avgEngagementSeconds']);
	}//end testASinglePageViewIsABounce()


	/**
	 * A scroll on a single page, or ten seconds on it, is engagement.
	 *
	 * @return void
	 */
	public function testAScrollOrTenSecondsMakesASessionEngaged(): void {
		$scrolled = $this->rollup([
			$this->event('2026-09-04T10:00:00.000Z', '/', 'h1'),
			$this->event('2026-09-04T10:00:02.000Z', '/', 'h1', 'scroll'),
		]);
		$this->assertSame(1, $scrolled['engagedSessions']);

		$lingered = $this->rollup([
			$this->event('2026-09-04T10:00:00.000Z', '/', 'h2'),
			$this->event('2026-09-04T10:00:10.000Z', '/', 'h2', 'form_submit'),
		]);
		$this->assertSame(1, $lingered['engagedSessions']);

		$quick = $this->rollup([
			$this->event('2026-09-04T10:00:00.000Z', '/', 'h3'),
			$this->event('2026-09-04T10:00:03.000Z', '/', 'h3', 'form_submit'),
		]);
		$this->assertSame(0, $quick['engagedSessions']);
	}//end testAScrollOrTenSecondsMakesASessionEngaged()


	/**
	 * Referrers, devices and campaigns are counted per SESSION from its
	 * first event; searches, downloads and outbound clicks per EVENT.
	 *
	 * @return void
	 */
	public function testDimensionsAreCountedPerSessionAndPerEvent(): void {
		$record = $this->rollup([
			$this->event('2026-09-04T10:00:00.000Z', '/', 'h1', 'page_view', [
				'referrerHost' => 'www.google.nl', 'channel' => 'organic search', 'deviceType' => 'mobile',
				'browser' => 'Safari', 'os' => 'iOS', 'language' => 'nl', 'region' => 'NL',
				'campaign' => 'woo', 'source' => 'nieuwsbrief', 'medium' => 'email',
			]),
			$this->event('2026-09-04T10:00:05.000Z', '/zoeken', 'h1', 'search', ['searchTerm' => 'parkeren']),
			$this->event('2026-09-04T10:00:09.000Z', '/zoeken', 'h1', 'search', ['params' => ['search_term' => 'parkeren']]),
			$this->event('2026-09-04T10:00:12.000Z', '/docs', 'h1', 'file_download', ['fileName' => 'besluit.pdf']),
			$this->event('2026-09-04T10:00:15.000Z', '/docs', 'h1', 'outbound_click', ['linkUrl' => 'https://rijksoverheid.nl/']),
			$this->event('2026-09-04T11:00:00.000Z', '/', 'h2', 'page_view', ['deviceType' => 'desktop', 'referrerHost' => '', 'channel' => 'direct']),
		]);

		$this->assertSame(2, $record['sessions']);
		$this->assertSame(['desktop' => 1, 'mobile' => 1], $record['devices'], 'maps are sorted by key');
		$this->assertSame(['Safari' => 1], $record['browsers']);
		$this->assertSame(['iOS' => 1], $record['os']);
		$this->assertSame(['nl' => 1], $record['languages']);
		$this->assertSame(['NL' => 1], $record['regions']);
		$this->assertSame(
			[['host' => 'www.google.nl', 'channel' => 'organic search', 'count' => 1], ['host' => '', 'channel' => 'direct', 'count' => 1]],
			$record['referrers']
		);
		$this->assertSame([['campaign' => 'woo', 'source' => 'nieuwsbrief', 'medium' => 'email', 'sessions' => 1]], $record['campaigns']);
		$this->assertSame([['term' => 'parkeren', 'count' => 2]], $record['searches'], 'the field and the params spelling count together');
		$this->assertSame([['file' => 'besluit.pdf', 'count' => 1]], $record['downloads']);
		$this->assertSame([['url' => 'https://rijksoverheid.nl/', 'count' => 1]], $record['outbound']);
		$this->assertSame(['file_download' => 1, 'outbound_click' => 1, 'page_view' => 2, 'search' => 2], $record['events']);
	}//end testDimensionsAreCountedPerSessionAndPerEvent()


	/**
	 * Mail events from pipelinq land under `emails`, and a persisted-id
	 * visitor is only new when its first session said so.
	 *
	 * @return void
	 */
	public function testMailEventsAndReturningVisitors(): void {
		$returning = $this->event('2026-09-04T10:00:00.000Z', '/', 'h1', 'session_start', ['clientId' => 'cid-1']);
		$firstTimer = $this->event('2026-09-04T10:00:00.000Z', '/', 'h2', 'session_start', ['clientId' => 'cid-2', 'params' => ['first' => true]]);

		$record = $this->rollup([
			$returning,
			$firstTimer,
			$this->event('2026-09-04T09:00:00.000Z', '/', 'contact-a', 'email_open', ['pageLocation' => 'mailto:blast/1']),
			$this->event('2026-09-04T09:01:00.000Z', '/', 'contact-a', 'email_click', ['pageLocation' => 'https://open-tilburg.nl/woo']),
		]);

		$this->assertSame(['opens' => 1, 'clicks' => 1], $record['emails']);
		$this->assertSame(3, $record['visitors']);
		$this->assertSame(2, $record['newVisitors'], 'the contact hash is new by construction, cid-2 said first, cid-1 did not');
		$this->assertSame(0, $record['pageViews']);
	}//end testMailEventsAndReturningVisitors()


	/**
	 * The same sessions give the same record: the property idempotent
	 * aggregation rests on.
	 *
	 * @return void
	 */
	public function testTheSameEventsGiveTheSameRecord(): void {
		$events = [
			$this->event('2026-09-04T10:00:00.000Z', '/'),
			$this->event('2026-09-04T10:00:30.000Z', '/begrippen'),
			$this->event('2026-09-04T12:00:00.000Z', '/', 'h2'),
		];

		$this->assertSame($this->rollup($events), $this->rollup(array_reverse($events)));
	}//end testTheSameEventsGiveTheSameRecord()


	/**
	 * An empty day is all zeros, with no division by nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyDayIsZeros(): void {
		$record = (new TrafficRollup())->build(portal: 'p', date: '2026-09-04', sessions: [], aggregatedAt: 'x');

		$this->assertSame(0, $record['pageViews']);
		$this->assertSame(0, $record['sessions']);
		$this->assertSame(0.0, $record['bounceRate']);
		$this->assertSame(0.0, $record['avgEngagementSeconds']);
		$this->assertSame([], $record['pages']);
		$this->assertSame('', $record['lastEventAt']);
	}//end testAnEmptyDayIsZeros()
}//end class
