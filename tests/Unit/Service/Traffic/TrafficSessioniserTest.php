<?php

/**
 * Unit tests for TrafficSessioniser.
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

use OCA\Portaliq\Service\Traffic\TrafficSessioniser;
use PHPUnit\Framework\TestCase;

/**
 * Which events belong together, and in which order.
 */
class TrafficSessioniserTest extends TestCase {

	/**
	 * One event.
	 *
	 * @param string      $at      occurredAt.
	 * @param int         $seq     sequence.
	 * @param string      $path    pagePath.
	 * @param string      $visitor visitorHash.
	 * @param string|null $session sessionId.
	 * @param string      $name    The event name.
	 *
	 * @return array<string, mixed> The event.
	 */
	private function event(string $at, int $seq, string $path, string $visitor = 'h1', ?string $session = null, string $name = 'page_view'): array {
		return [
			'name' => $name,
			'occurredAt' => $at,
			'sequence' => $seq,
			'pagePath' => $path,
			'visitorHash' => $visitor,
			'sessionId' => $session,
			'receivedAt' => '2026-09-04T23:59:59.000Z',
		];
	}//end event()


	/**
	 * The paths of a session's events, in order.
	 *
	 * @param array<string, mixed> $session The session.
	 *
	 * @return array<int, string> The paths.
	 */
	private function paths(array $session): array {
		return array_map(static fn (array $e): string => (string)$e['pagePath'], $session['events']);
	}//end paths()


	/**
	 * Two page views by one cookieless visitor within the window are one
	 * session, ordered by the client clock, not by the order given.
	 *
	 * @return void
	 */
	public function testACookielessVisitorsEventsFormOneOrderedSession(): void {
		$sessions = (new TrafficSessioniser())->sessions(
			events: [
				$this->event('2026-09-04T10:05:00.000Z', 0, '/begrippen'),
				$this->event('2026-09-04T10:00:00.000Z', 0, '/'),
			],
			timeoutMinutes: 30
		);

		$this->assertCount(1, $sessions);
		$this->assertSame(['/', '/begrippen'], $this->paths($sessions[0]));
		$this->assertSame('h:h1', $sessions[0]['visitor']);
		$this->assertFalse($sessions[0]['explicit']);
	}//end testACookielessVisitorsEventsFormOneOrderedSession()


	/**
	 * A return after the inactivity window is a NEW session, and the first
	 * one closed at its own last event.
	 *
	 * @return void
	 */
	public function testAReturnAfterTheTimeoutStartsANewSession(): void {
		$sessions = (new TrafficSessioniser())->sessions(
			events: [
				$this->event('2026-09-04T10:00:00.000Z', 0, '/'),
				$this->event('2026-09-04T10:10:00.000Z', 0, '/a'),
				$this->event('2026-09-04T10:41:00.000Z', 0, '/b'),
			],
			timeoutMinutes: 30
		);

		$this->assertCount(2, $sessions);
		$this->assertSame(['/', '/a'], $this->paths($sessions[0]));
		$this->assertSame(['/b'], $this->paths($sessions[1]));

		// The window is the portal's, not a constant: a 45-minute window keeps it one session.
		$wide = (new TrafficSessioniser())->sessions(
			events: [
				$this->event('2026-09-04T10:00:00.000Z', 0, '/'),
				$this->event('2026-09-04T10:41:00.000Z', 0, '/b'),
			],
			timeoutMinutes: 45
		);
		$this->assertCount(1, $wide);
	}//end testAReturnAfterTheTimeoutStartsANewSession()


	/**
	 * With an explicit session id the SEQUENCE orders the journey, so a
	 * delayed beacon with the lower sequence still comes first even though
	 * its clock reads later and it arrived last.
	 *
	 * @return void
	 */
	public function testAnExplicitSessionIsOrderedBySequenceNotByReceipt(): void {
		$sessions = (new TrafficSessioniser())->sessions(
			events: [
				$this->event('2026-09-04T10:00:03.000Z', 2, '/c', 'h1', 'sess-1'),
				$this->event('2026-09-04T10:00:02.000Z', 0, '/a', 'h1', 'sess-1'),
				$this->event('2026-09-04T10:00:01.000Z', 1, '/b', 'h1', 'sess-1'),
			],
			timeoutMinutes: 30
		);

		$this->assertCount(1, $sessions);
		$this->assertTrue($sessions[0]['explicit']);
		$this->assertSame(['/a', '/b', '/c'], $this->paths($sessions[0]));
	}//end testAnExplicitSessionIsOrderedBySequenceNotByReceipt()


	/**
	 * A cookieless client restarts its sequence on every page load, so two
	 * page loads both carrying sequence 0 and 1 must NOT interleave: the
	 * clock orders across loads, the sequence within one.
	 *
	 * @return void
	 */
	public function testACookielessSequenceOnlyBreaksTiesWithinOnePageLoad(): void {
		$sessions = (new TrafficSessioniser())->sessions(
			events: [
				$this->event('2026-09-04T10:01:00.000Z', 1, '/b', 'h1', null, 'scroll'),
				$this->event('2026-09-04T10:01:00.000Z', 0, '/b'),
				$this->event('2026-09-04T10:00:00.000Z', 1, '/a', 'h1', null, 'scroll'),
				$this->event('2026-09-04T10:00:00.000Z', 0, '/a'),
			],
			timeoutMinutes: 30
		);

		$names = array_map(static fn (array $e): string => $e['name'] . ' ' . $e['pagePath'], $sessions[0]['events']);
		$this->assertSame(['page_view /a', 'scroll /a', 'page_view /b', 'scroll /b'], $names);
	}//end testACookielessSequenceOnlyBreaksTiesWithinOnePageLoad()


	/**
	 * Two visitors never share a session, and a persisted client id is the
	 * identity when present.
	 *
	 * @return void
	 */
	public function testVisitorsAreKeptApartAndAClientIdWins(): void {
		$withClient = $this->event('2026-09-04T10:00:00.000Z', 0, '/', 'h1');
		$withClient['clientId'] = 'cid-9';

		$sessions = (new TrafficSessioniser())->sessions(
			events: [
				$withClient,
				$this->event('2026-09-04T10:00:00.000Z', 0, '/', 'h2'),
			],
			timeoutMinutes: 30
		);

		$this->assertCount(2, $sessions);
		$visitors = array_map(static fn (array $s): string => $s['visitor'], $sessions);
		sort($visitors);
		$this->assertSame(['c:cid-9', 'h:h2'], $visitors);
	}//end testVisitorsAreKeptApartAndAClientIdWins()


	/**
	 * Milliseconds are honoured: two events in the same second still order.
	 *
	 * @return void
	 */
	public function testMillisecondsOrderEventsWithinOneSecond(): void {
		$sessions = (new TrafficSessioniser())->sessions(
			events: [
				$this->event('2026-09-04T10:00:00.900Z', 0, '/second'),
				$this->event('2026-09-04T10:00:00.100Z', 0, '/first'),
			],
			timeoutMinutes: 30
		);

		$this->assertSame(['/first', '/second'], $this->paths($sessions[0]));
	}//end testMillisecondsOrderEventsWithinOneSecond()
}//end class
