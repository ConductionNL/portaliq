<?php

/**
 * Portaliq Portal Traffic Aggregator Test
 *
 * That a journey is reconstructed from what the client counted, not from what
 * the network happened to deliver first.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-traffic-analytics/tasks.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalTrafficAggregator;
use PHPUnit\Framework\TestCase;

/**
 * The aggregator.
 *
 * @spec openspec/changes/portal-traffic-analytics/tasks.md
 */
class PortalTrafficAggregatorTest extends TestCase {

	private PortalTrafficAggregator $aggregator;


	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->aggregator = new PortalTrafficAggregator();
	}//end setUp()


	/**
	 * One stored event.
	 *
	 * @param string $session  The session id.
	 * @param int    $sequence The client's own counter.
	 * @param string $page     The page path.
	 * @param int    $minute   Minutes after a fixed origin.
	 *
	 * @return array<string, mixed> The event.
	 */
	private function view(string $session, int $sequence, string $page, int $minute = 0): array {
		return [
			'id' => ($session . '-' . $sequence),
			'name' => 'page_view',
			'clientId' => ('c-' . $session),
			'sessionId' => $session,
			'sequence' => $sequence,
			'pageLocation' => $page,
			'receivedAt' => gmdate('c', (1786000000 + ($minute * 60))),
		];
	}//end view()


	/**
	 * A journey is ordered by SEQUENCE, not by the order rows arrived.
	 *
	 * THIS IS THE CASE THAT ONLY APPEARS ON A SLOW CONNECTION. A delayed or
	 * retried beacon arrives after a later one routinely; ordering by receipt
	 * then invents journeys nobody made — an exit that was really the
	 * entrance, a transition that ran backwards — and every number downstream
	 * inherits the error while looking entirely plausible.
	 *
	 * @return void
	 */
	public function testAJourneyIsReconstructedBySequenceNotByArrival(): void {
		// Delivered 2, 0, 1 — the shape a retry produces.
		$events = [
			$this->view(session: 's1', sequence: 2, page: '/contact', minute: 2),
			$this->view(session: 's1', sequence: 0, page: '/', minute: 0),
			$this->view(session: 's1', sequence: 1, page: '/diensten', minute: 1),
		];

		$journeys = $this->aggregator->journeys(events: $events);

		$this->assertCount(1, $journeys);
		$this->assertSame(
			['/', '/diensten', '/contact'],
			array_column($journeys[0], 'pageLocation')
		);
	}//end testAJourneyIsReconstructedBySequenceNotByArrival()


	/**
	 * Out-of-order delivery produces the SAME aggregate as in-order delivery.
	 *
	 * Asserted against the in-order result rather than against a hand-written
	 * expectation, so the two cannot drift apart when the aggregate grows a
	 * field.
	 *
	 * @return void
	 */
	public function testOutOfOrderDeliveryChangesNothing(): void {
		$ordered = [
			$this->view(session: 's1', sequence: 0, page: '/', minute: 0),
			$this->view(session: 's1', sequence: 1, page: '/diensten', minute: 1),
			$this->view(session: 's1', sequence: 2, page: '/contact', minute: 2),
		];
		$shuffled = [$ordered[2], $ordered[0], $ordered[1]];

		$this->assertSame(
			$this->aggregator->aggregate(events: $ordered),
			$this->aggregator->aggregate(events: $shuffled)
		);
	}//end testOutOfOrderDeliveryChangesNothing()


	/**
	 * A gap longer than the idle window splits one session in two.
	 *
	 * The session id is chosen by the CLIENT, so the server cannot take it on
	 * trust: a browser left open across a lunch break, or a client that reused
	 * an id, produces one id spanning a gap no visit spans.
	 *
	 * @return void
	 */
	public function testAnIdleGapSplitsASessionEvenUnderOneSessionId(): void {
		$events = [
			$this->view(session: 's1', sequence: 0, page: '/', minute: 0),
			$this->view(session: 's1', sequence: 1, page: '/diensten', minute: 5),
			// 90 minutes later, same id.
			$this->view(session: 's1', sequence: 2, page: '/contact', minute: 95),
		];

		$journeys = $this->aggregator->journeys(events: $events, timeoutMinutes: 30);

		$this->assertCount(2, $journeys);
		$this->assertCount(2, $journeys[0]);
		$this->assertSame('/contact', $journeys[1][0]['pageLocation']);
	}//end testAnIdleGapSplitsASessionEvenUnderOneSessionId()


	/**
	 * Just inside the window is still one session.
	 *
	 * The companion to the split above: a boundary test that only checks the
	 * far side proves the code splits, not that it splits at the right place.
	 *
	 * @return void
	 */
	public function testAGapInsideTheWindowKeepsOneSession(): void {
		$events = [
			$this->view(session: 's1', sequence: 0, page: '/', minute: 0),
			$this->view(session: 's1', sequence: 1, page: '/diensten', minute: 29),
		];

		$this->assertCount(1, $this->aggregator->journeys(events: $events, timeoutMinutes: 30));
	}//end testAGapInsideTheWindowKeepsOneSession()


	/**
	 * Entrances, exits and transitions are counted from the ordered journey.
	 *
	 * @return void
	 */
	public function testTheJourneyShapeIsCounted(): void {
		$events = [
			$this->view(session: 's1', sequence: 0, page: '/', minute: 0),
			$this->view(session: 's1', sequence: 1, page: '/diensten', minute: 1),
			$this->view(session: 's2', sequence: 0, page: '/', minute: 0),
			$this->view(session: 's2', sequence: 1, page: '/contact', minute: 1),
		];

		$aggregate = $this->aggregator->aggregate(events: $events);

		$this->assertSame(2, $aggregate['sessions']);
		$this->assertSame(4, $aggregate['pageViews']);
		$this->assertSame(['key' => '/', 'count' => 2], $aggregate['entrances'][0]);
		$this->assertSame(2, count($aggregate['exits']));
		$this->assertSame(
			['/ → /contact', '/ → /diensten'],
			array_column($aggregate['transitions'], 'key')
		);
	}//end testTheJourneyShapeIsCounted()


	/**
	 * Running the aggregation twice does not double a count.
	 *
	 * IDEMPOTENCE IS ASSERTED ON THE WHOLE RESULT, not on one total. A job that
	 * doubles one figure and not another is the failure that survives a
	 * narrower check and then quietly reports twice the traffic.
	 *
	 * @return void
	 */
	public function testAggregatingTwiceProducesTheIdenticalResult(): void {
		$events = [
			$this->view(session: 's1', sequence: 0, page: '/', minute: 0),
			$this->view(session: 's1', sequence: 1, page: '/diensten', minute: 1),
			$this->view(session: 's2', sequence: 0, page: '/diensten', minute: 3),
		];

		$first = $this->aggregator->aggregate(events: $events);
		$second = $this->aggregator->aggregate(events: $events);

		$this->assertSame($first, $second);
	}//end testAggregatingTwiceProducesTheIdenticalResult()


	/**
	 * A tie ranks by key, so two runs cannot reshuffle a table.
	 *
	 * @return void
	 */
	public function testTiesRankDeterministically(): void {
		$events = [
			$this->view(session: 's1', sequence: 0, page: '/b', minute: 0),
			$this->view(session: 's2', sequence: 0, page: '/a', minute: 0),
		];

		$reversed = [$events[1], $events[0]];

		$this->assertSame(
			array_column($this->aggregator->aggregate(events: $events)['views'], 'key'),
			array_column($this->aggregator->aggregate(events: $reversed)['views'], 'key')
		);
		$this->assertSame('/a', $this->aggregator->aggregate(events: $events)['views'][0]['key']);
	}//end testTiesRankDeterministically()


	/**
	 * A single-page visit is a session but not an engaged one.
	 *
	 * @return void
	 */
	public function testEngagementCountsOnlyMultiPageJourneys(): void {
		$events = [
			$this->view(session: 's1', sequence: 0, page: '/', minute: 0),
			$this->view(session: 's2', sequence: 0, page: '/', minute: 0),
			$this->view(session: 's2', sequence: 1, page: '/diensten', minute: 1),
		];

		$aggregate = $this->aggregator->aggregate(events: $events);

		$this->assertSame(2, $aggregate['sessions']);
		$this->assertSame(1, $aggregate['engagedSessions']);
	}//end testEngagementCountsOnlyMultiPageJourneys()


	/**
	 * A non-page event happens ON a page and is not a step in the journey.
	 *
	 * Counting a `search` as a page would inflate the view count and invent a
	 * transition the visitor never made.
	 *
	 * @return void
	 */
	public function testANonPageEventIsNotAStepInTheJourney(): void {
		$search = $this->view(session: 's1', sequence: 1, page: '/zoeken', minute: 1);
		$search['name'] = 'search';

		$events = [
			$this->view(session: 's1', sequence: 0, page: '/', minute: 0),
			$search,
			$this->view(session: 's1', sequence: 2, page: '/diensten', minute: 2),
		];

		$aggregate = $this->aggregator->aggregate(events: $events);

		$this->assertSame(2, $aggregate['pageViews']);
		$this->assertSame(['/ → /diensten'], array_column($aggregate['transitions'], 'key'));
	}//end testANonPageEventIsNotAStepInTheJourney()


	/**
	 * Retention expires old rows and keeps recent ones.
	 *
	 * @return void
	 */
	public function testRetentionSelectsOnlyRowsPastTheWindow(): void {
		$now = 1786000000;
		$old = $this->view(session: 's1', sequence: 0, page: '/');
		$old['id'] = 'old';
		$old['receivedAt'] = gmdate('c', ($now - (100 * 86400)));

		$recent = $this->view(session: 's2', sequence: 0, page: '/');
		$recent['id'] = 'recent';
		$recent['receivedAt'] = gmdate('c', ($now - (10 * 86400)));

		$expired = $this->aggregator->expiredIds(events: [$old, $recent], retentionDays: 90, now: $now);

		$this->assertSame(['old'], $expired);
	}//end testRetentionSelectsOnlyRowsPastTheWindow()


	/**
	 * A row whose age cannot be read is KEPT.
	 *
	 * Deleting what you cannot date is how a retention job becomes a data-loss
	 * incident. The safe direction is keeping a row too long, which is
	 * correctable, rather than deleting one that was never expired.
	 *
	 * @return void
	 */
	public function testAnUndatedRowIsNeverDeleted(): void {
		$undated = $this->view(session: 's1', sequence: 0, page: '/');
		$undated['id'] = 'undated';
		$undated['receivedAt'] = '';
		$undated['timestamp'] = '';

		$this->assertSame(
			[],
			$this->aggregator->expiredIds(events: [$undated], retentionDays: 1, now: 1786000000)
		);
	}//end testAnUndatedRowIsNeverDeleted()


	/**
	 * A retention of zero deletes nothing at all.
	 *
	 * "Unset" must not read as "expire everything immediately".
	 *
	 * @return void
	 */
	public function testARetentionOfZeroDeletesNothing(): void {
		$events = [$this->view(session: 's1', sequence: 0, page: '/')];

		$this->assertSame(
			[],
			$this->aggregator->expiredIds(events: $events, retentionDays: 0, now: 9999999999)
		);
	}//end testARetentionOfZeroDeletesNothing()


}//end class
