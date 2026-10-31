<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Traffic;

use OCA\Portaliq\Service\Traffic\TrafficDimensionCounts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Per-session and per-event dimension counting for the traffic rollup.
 */
#[CoversClass(TrafficDimensionCounts::class)]
class TrafficDimensionCountsTest extends TestCase {
	/**
	 * Sessions are grouped on the first event's values, busiest first.
	 *
	 * @return void
	 */
	public function testPerSession(): void {
		$sessions = [
			['events' => [['a' => 'x', 'b' => '1']]],
			['events' => [['a' => 'y', 'b' => '2']]],
			['events' => [['a' => 'y', 'b' => '2']]],
			['events' => [['a' => '', 'b' => '']]],
			['events' => []],
		];

		$rows = (new TrafficDimensionCounts())->perSession($sessions, ['a', 'b'], ['first', 'second'], 'n');

		$this->assertSame(
			[
				['first' => 'y', 'second' => '2', 'n' => 2],
				['first' => 'x', 'second' => '1', 'n' => 1],
			],
			$rows
		);
	}//end testPerSession()

	/**
	 * The first non-empty value per session is counted once, sorted by key.
	 *
	 * @return void
	 */
	public function testPerSessionMap(): void {
		$sessions = [
			['events' => [['k' => ''], ['k' => 'nl']]],
			['events' => [['k' => 'de'], ['k' => 'nl']]],
			['events' => [['k' => 'nl']]],
			['events' => [['k' => ' ']]],
			[],
		];

		$this->assertSame(['de' => 1, 'nl' => 2], (new TrafficDimensionCounts())->perSessionMap($sessions, 'k'));
	}//end testPerSessionMap()

	/**
	 * Only the named event counts, by the first usable key, ties by label.
	 *
	 * @return void
	 */
	public function testPerEvent(): void {
		$sessions = [
			[
				'events' => [
					['name' => 'click', 'target' => 'b'],
					['name' => 'click', 'params' => ['to' => 'a']],
					['name' => 'click', 'target' => 'b'],
					['name' => 'other', 'target' => 'z'],
					['name' => 'click'],
				],
			],
			['events' => [['name' => 'click', 'params' => ['to' => 'a']]]],
		];

		$rows = (new TrafficDimensionCounts())->perEvent($sessions, 'click', ['target', 'params.to'], 'dest');

		$this->assertSame([['dest' => 'a', 'count' => 2], ['dest' => 'b', 'count' => 2]], $rows);
	}//end testPerEvent()

	/**
	 * Zero-result searches are counted; unknown counts are reported apart.
	 *
	 * @return void
	 */
	public function testZeroResults(): void {
		$sessions = [
			[
				'events' => [
					['name' => 'search', 'searchTerm' => 'bike', 'results' => 0],
					['name' => 'search', 'params' => ['search_term' => 'bike', 'results' => 0.0]],
					['name' => 'search', 'searchTerm' => 'car', 'results' => 0],
					['name' => 'search', 'searchTerm' => 'boat', 'results' => 3],
					['name' => 'search', 'searchTerm' => 'plane'],
					['name' => 'search', 'searchTerm' => 'ship', 'results' => -1],
					['name' => 'search', 'searchTerm' => 'half', 'results' => 1.5],
					['name' => 'search'],
					['name' => 'view', 'searchTerm' => 'x'],
				],
			],
		];

		$result = (new TrafficDimensionCounts())->zeroResults($sessions);

		$this->assertSame([['term' => 'bike', 'count' => 2], ['term' => 'car', 'count' => 1]], $result['rows']);
		$this->assertSame(3, $result['withoutCount']);
	}//end testZeroResults()

	/**
	 * Custom dimensions count per event, or once per session when so scoped.
	 *
	 * @return void
	 */
	public function testCustom(): void {
		$sessions = [
			['events' => [['params' => ['cd_plan' => 'b']], ['params' => ['cd_plan' => 'b']], ['params' => ['cd_plan' => 'a']]]],
			['events' => [['params' => ['cd_plan' => 'b']]]],
			[],
		];
		$counts = new TrafficDimensionCounts();

		$perEvent = $counts->custom($sessions, [['id' => 'plan'], ['id' => 'none']]);
		$this->assertSame(['plan' => ['a' => 1, 'b' => 3]], $perEvent);

		$perSession = $counts->custom($sessions, [['id' => 'plan', 'scope' => 'session']]);
		$this->assertSame(['plan' => ['b' => 2]], $perSession);
	}//end testCustom()
}//end class
