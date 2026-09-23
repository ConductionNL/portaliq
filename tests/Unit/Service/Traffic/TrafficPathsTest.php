<?php

/**
 * Unit tests for TrafficPaths.
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

use OCA\Portaliq\Service\Traffic\TrafficPaths;
use PHPUnit\Framework\TestCase;

/**
 * Every fixture is built here by hand, so every number asserted below can
 * be counted on paper from the lines that build it.
 */
class TrafficPathsTest extends TestCase {


	/**
	 * A session whose page views are the given paths, in order.
	 *
	 * @param string[] $paths The paths.
	 *
	 * @return array<string, mixed> The session.
	 */
	private function visit(array $paths): array {
		$events = [];
		foreach ($paths as $path) {
			$events[] = ['name' => 'page_view', 'pagePath' => $path];
		}

		return ['visitor' => 'h:x', 'explicit' => false, 'events' => $events];
	}//end visit()


	/**
	 * The fold of visits given as [paths, how many].
	 *
	 * @param array<int, array{0: string[], 1: int}> $visits The visits.
	 *
	 * @return array<string, int> The fold.
	 */
	private function folded(array $visits): array {
		$sessions = [];
		foreach ($visits as [$paths, $times]) {
			for ($i = 0; $i < $times; $i++) {
				$sessions[] = $this->visit(paths: $paths);
			}
		}

		return (new TrafficPaths())->fold(folded: [], sessions: $sessions);
	}//end folded()


	/**
	 * A column's nodes as path => [sessions, dropOffs], "+N more" as `+N`.
	 *
	 * @param array<string, mixed> $column The column.
	 *
	 * @return array<string, array{0: int, 1: int}> The nodes.
	 */
	private function nodes(array $column): array {
		$out = [];
		foreach ($column['nodes'] as $node) {
			$key = $node['path'];
			if ($node['more'] > 0) {
				$key = '+' . $node['more'];
			}

			$out[$key] = [$node['sessions'], $node['dropOffs']];
		}

		return $out;
	}//end nodes()


	/**
	 * The links of an explorer as "step:from>to" => sessions, by path.
	 *
	 * @param array<string, mixed> $explorer The explorer.
	 *
	 * @return array<string, int> The links.
	 */
	private function links(array $explorer): array {
		$out = [];
		foreach ($explorer['links'] as $link) {
			$from = $explorer['columns'][$link['step']]['nodes'][$link['source']];
			$to = $explorer['columns'][$link['step'] + 1]['nodes'][$link['target']];
			$fromName = $from['more'] > 0 ? '+' . $from['more'] : $from['path'];
			$toName = $to['more'] > 0 ? '+' . $to['more'] : $to['path'];
			$out[$link['step'] . ':' . $fromName . '>' . $toName] = $link['sessions'];
		}

		ksort($out);

		return $out;
	}//end links()


	/**
	 * Only page views are steps, a reload is one step, and a view without
	 * a stored path falls back to its location, then to "/".
	 *
	 * @return void
	 */
	public function testASequenceIsThePageViewsWithReloadsCollapsed(): void {
		$session = [
			'events' => [
				['name' => 'session_start'],
				['name' => 'page_view', 'pagePath' => '/home'],
				['name' => 'page_view', 'pagePath' => '/home'],
				['name' => 'scroll', 'pagePath' => '/home'],
				['name' => 'page_view', 'pagePath' => '/news'],
				['name' => 'page_view', 'pagePath' => '', 'pageLocation' => 'https://example.test/contact?x=1'],
				['name' => 'page_view'],
				['name' => 'page_view', 'pagePath' => '/home'],
			],
		];

		$this->assertSame(['/home', '/news', '/contact', '/', '/home'], (new TrafficPaths())->sequence(session: $session));
	}//end testASequenceIsThePageViewsWithReloadsCollapsed()


	/**
	 * A visit is cut at MAX_VIEWS page views.
	 *
	 * @return void
	 */
	public function testAVeryLongVisitIsCut(): void {
		$paths = [];
		for ($i = 0; $i < 150; $i++) {
			$paths[] = '/p' . $i;
		}

		$this->assertCount(TrafficPaths::MAX_VIEWS, (new TrafficPaths())->sequence(session: $this->visit(paths: $paths)));
	}//end testAVeryLongVisitIsCut()


	/**
	 * Identical paths fold into one key with a count; a visit without a
	 * page view is left out; a second fold adds to the first.
	 *
	 * @return void
	 */
	public function testTheFoldCountsIdenticalPathsAndSkipsVisitsWithoutAView(): void {
		$paths = new TrafficPaths();
		$folded = $paths->fold(
			folded: [],
			sessions: [
				$this->visit(paths: ['/home', '/news']),
				$this->visit(paths: ['/home', '/news']),
				['events' => [['name' => 'session_start']]],
			]
		);
		$folded = $paths->fold(folded: $folded, sessions: [$this->visit(paths: ['/home', '/news']), $this->visit(paths: ['/about'])]);

		$this->assertSame(["/home\0/news" => 3, '/about' => 1], $folded);
	}//end testTheFoldCountsIdenticalPathsAndSkipsVisitsWithoutAView()


	/**
	 * Scenario: a path is what one visit did, not a chain of pairs. The
	 * pairs /about>/news and /news>/contact both exist, but no visit went
	 * /about, /news, /contact.
	 *
	 * @return void
	 */
	public function testAPathIsWhatOneVisitDidNotAChainOfPairs(): void {
		$folded = $this->folded(visits: [[['/home', '/news', '/contact'], 1], [['/about', '/news'], 1]]);
		$explorer = (new TrafficPaths())->explore(folded: $folded, mode: 'start', anchor: '', steps: 3, trail: ['/about']);

		$this->assertSame(2, $explorer['sessions']);
		$this->assertSame(['/about' => [1, 0], '/home' => [1, 0]], $this->nodes(column: $explorer['columns'][0]));
		$this->assertSame(['/news' => [1, 1]], $this->nodes(column: $explorer['columns'][1]), 'the /about visit ends on /news');
		$this->assertSame([], $this->nodes(column: $explorer['columns'][2]), 'no /about visit reaches /contact');

		$open = (new TrafficPaths())->explore(folded: $folded, mode: 'start', anchor: '', steps: 3, trail: []);
		$this->assertSame(['/contact' => [1, 1]], $this->nodes(column: $open['columns'][2]));
		$this->assertCount(4, $open['columns'], 'step 0 and three steps');
		$this->assertSame(
			['0:/about>/news' => 1, '0:/home>/news' => 1, '1:/news>/contact' => 1],
			$this->links(explorer: $open)
		);
	}//end testAPathIsWhatOneVisitDidNotAChainOfPairs()


	/**
	 * Scenario: a reload is not a step.
	 *
	 * @return void
	 */
	public function testAReloadIsNotAStep(): void {
		$folded = $this->folded(visits: [[['/home', '/home', '/news'], 1]]);
		$explorer = (new TrafficPaths())->explore(folded: $folded, mode: 'start', anchor: '', steps: 3, trail: []);

		$this->assertSame(['/home' => [1, 0]], $this->nodes(column: $explorer['columns'][0]));
		$this->assertSame(['/news' => [1, 1]], $this->nodes(column: $explorer['columns'][1]));
		$this->assertSame([], $this->nodes(column: $explorer['columns'][2]));
	}//end testAReloadIsNotAStep()


	/**
	 * Scenario: the sixth page and beyond become one node, links to them
	 * attach to it, and its drop-offs are summed.
	 *
	 * @return void
	 */
	public function testTheSixthPageAndBeyondBecomeOneNode(): void {
		$folded = $this->folded(
			visits: [
				[['/a', '/x'], 10],
				[['/b', '/x'], 9],
				[['/c'], 8],
				[['/d'], 7],
				[['/e'], 6],
				[['/f', '/x'], 2],
				[['/g'], 1],
			]
		);
		$explorer = (new TrafficPaths())->explore(folded: $folded, mode: 'start', anchor: '', steps: 1, trail: []);

		$this->assertSame(
			['/a' => [10, 0], '/b' => [9, 0], '/c' => [8, 8], '/d' => [7, 7], '/e' => [6, 6], '+2' => [3, 1]],
			$this->nodes(column: $explorer['columns'][0])
		);
		$this->assertSame(['sessions' => 43, 'dropOffs' => 22], array_intersect_key($explorer['columns'][0], ['sessions' => 0, 'dropOffs' => 0]));
		$this->assertSame(['0:+2>/x' => 2, '0:/a>/x' => 10, '0:/b>/x' => 9], $this->links(explorer: $explorer));
	}//end testTheSixthPageAndBeyondBecomeOneNode()


	/**
	 * Equal counts rank by path, so the order never depends on the fold's.
	 *
	 * @return void
	 */
	public function testEqualCountsRankByPath(): void {
		$folded = $this->folded(visits: [[['/z'], 1], [['/m'], 1], [['/a'], 1]]);
		$explorer = (new TrafficPaths())->explore(folded: $folded, mode: 'start', anchor: '', steps: 1, trail: []);

		$this->assertSame(['/a', '/m', '/z'], array_column($explorer['columns'][0]['nodes'], 'path'));
	}//end testEqualCountsRankByPath()


	/**
	 * Scenario: drop-offs are counted per node and per step.
	 *
	 * @return void
	 */
	public function testDropOffsAreCountedPerNodeAndPerStep(): void {
		$folded = $this->folded(visits: [[['/home', '/news'], 3], [['/home'], 2]]);
		$explorer = (new TrafficPaths())->explore(folded: $folded, mode: 'start', anchor: '', steps: 2, trail: []);

		$this->assertSame(['/home' => [5, 2]], $this->nodes(column: $explorer['columns'][0]));
		$this->assertSame(5, $explorer['columns'][0]['sessions']);
		$this->assertSame(2, $explorer['columns'][0]['dropOffs']);
		$this->assertSame(['/news' => [3, 3]], $this->nodes(column: $explorer['columns'][1]));
		$this->assertSame(['sessions' => 0, 'dropOffs' => 0, 'nodes' => []], array_diff_key($explorer['columns'][2], ['step' => 0]));
	}//end testDropOffsAreCountedPerNodeAndPerStep()


	/**
	 * A visit that goes on past the last step shown is not a drop-off on it.
	 *
	 * @return void
	 */
	public function testAVisitCutAtTheLastStepIsNotADropOff(): void {
		$folded = $this->folded(visits: [[['/a', '/b', '/c'], 4], [['/a', '/b'], 1]]);
		$explorer = (new TrafficPaths())->explore(folded: $folded, mode: 'start', anchor: '', steps: 1, trail: []);

		$this->assertSame(['/b' => [5, 1]], $this->nodes(column: $explorer['columns'][1]));
	}//end testAVisitCutAtTheLastStepIsNotADropOff()


	/**
	 * Scenario: start from a page. A visit counts from its FIRST view of
	 * the page; a visit without it does not count.
	 *
	 * @return void
	 */
	public function testStartFromAPage(): void {
		$folded = $this->folded(
			visits: [
				[['/home', '/news', '/contact'], 1],
				[['/news', '/about', '/news', '/faq'], 1],
				[['/home', '/about'], 1],
			]
		);
		$explorer = (new TrafficPaths())->explore(folded: $folded, mode: 'start', anchor: '/news', steps: 3, trail: []);

		$this->assertSame(2, $explorer['sessions']);
		$this->assertSame(['/news' => [2, 0]], $this->nodes(column: $explorer['columns'][0]));
		$this->assertSame(['/about' => [1, 0], '/contact' => [1, 1]], $this->nodes(column: $explorer['columns'][1]));
		$this->assertSame(['/news' => [1, 0]], $this->nodes(column: $explorer['columns'][2]));
		$this->assertSame(['/faq' => [1, 1]], $this->nodes(column: $explorer['columns'][3]));
	}//end testStartFromAPage()


	/**
	 * Scenario: end at a page. The steps run backward from the LAST view
	 * of the page, and "drop-off" is where the visit began.
	 *
	 * @return void
	 */
	public function testEndAtAPage(): void {
		$folded = $this->folded(
			visits: [
				[['/home', '/news', '/contact'], 1],
				[['/about', '/contact'], 1],
				[['/contact', '/faq', '/contact', '/bye'], 1],
				[['/home'], 1],
			]
		);
		$explorer = (new TrafficPaths())->explore(folded: $folded, mode: 'end', anchor: '/contact', steps: 2, trail: []);

		$this->assertSame(3, $explorer['sessions']);
		$this->assertSame(['/contact' => [3, 0]], $this->nodes(column: $explorer['columns'][0]));
		$this->assertSame(['/about' => [1, 1], '/faq' => [1, 0], '/news' => [1, 0]], $this->nodes(column: $explorer['columns'][1]));
		$this->assertSame(['/contact' => [1, 1], '/home' => [1, 1]], $this->nodes(column: $explorer['columns'][2]));
	}//end testEndAtAPage()


	/**
	 * The end of a visit as the end point: step 0 lists the exit pages.
	 *
	 * @return void
	 */
	public function testEndAtTheEndOfAVisit(): void {
		$folded = $this->folded(visits: [[['/home', '/news'], 2], [['/news', '/home'], 1]]);
		$explorer = (new TrafficPaths())->explore(folded: $folded, mode: 'end', anchor: '', steps: 1, trail: []);

		$this->assertSame(['/news' => [2, 0], '/home' => [1, 0]], $this->nodes(column: $explorer['columns'][0]));
		$this->assertSame(['/home' => [2, 2], '/news' => [1, 1]], $this->nodes(column: $explorer['columns'][1]));
	}//end testEndAtTheEndOfAVisit()


	/**
	 * Scenario: a chosen node narrows the next steps, not its own.
	 *
	 * @return void
	 */
	public function testAChosenNodeNarrowsTheNextSteps(): void {
		$folded = $this->folded(visits: [[['/home', '/news', '/contact'], 1], [['/home', '/about', '/faq'], 1]]);
		$explorer = (new TrafficPaths())->explore(folded: $folded, mode: 'start', anchor: '', steps: 3, trail: [null, '/news']);

		$this->assertSame(['/about' => [1, 0], '/news' => [1, 0]], $this->nodes(column: $explorer['columns'][1]));
		$this->assertSame([true, false], [$explorer['columns'][1]['nodes'][1]['selected'], $explorer['columns'][1]['nodes'][0]['selected']]);
		$this->assertSame(['/contact' => [1, 1]], $this->nodes(column: $explorer['columns'][2]));
		$this->assertSame(
			['0:/home>/about' => 1, '0:/home>/news' => 1, '1:/news>/contact' => 1],
			$this->links(explorer: $explorer),
			'links leave the chosen node only'
		);
	}//end testAChosenNodeNarrowsTheNextSteps()


	/**
	 * A choice outside the top five is still shown, beside the five.
	 *
	 * @return void
	 */
	public function testAChosenPageOutsideTheTopFiveIsStillShown(): void {
		$folded = $this->folded(
			visits: [[['/a'], 6], [['/b'], 5], [['/c'], 4], [['/d'], 3], [['/e'], 2], [['/f', '/x'], 1], [['/g'], 1]]
		);
		$explorer = (new TrafficPaths())->explore(folded: $folded, mode: 'start', anchor: '', steps: 1, trail: ['/f']);

		$this->assertSame(
			['/a' => [6, 6], '/b' => [5, 5], '/c' => [4, 4], '/d' => [3, 3], '/e' => [2, 2], '/f' => [1, 0], '+1' => [1, 1]],
			$this->nodes(column: $explorer['columns'][0])
		);
		$this->assertSame(['/x' => [1, 1]], $this->nodes(column: $explorer['columns'][1]));
	}//end testAChosenPageOutsideTheTopFiveIsStillShown()


	/**
	 * Steps are held between one and MAX_STEPS, and a trail longer than
	 * the steps is cut.
	 *
	 * @return void
	 */
	public function testStepsAreBounded(): void {
		$folded = $this->folded(visits: [[['/a', '/b'], 1]]);
		$paths = new TrafficPaths();

		$this->assertCount(2, $paths->explore(folded: $folded, mode: 'start', anchor: '', steps: 0, trail: [])['columns']);
		$this->assertCount(TrafficPaths::MAX_STEPS + 1, $paths->explore(folded: $folded, mode: 'start', anchor: '', steps: 99, trail: array_fill(0, 20, ''))['columns']);
	}//end testStepsAreBounded()


	/**
	 * An empty fold is an explorer with empty steps, not an error.
	 *
	 * @return void
	 */
	public function testNothingFoldedIsEmptySteps(): void {
		$explorer = (new TrafficPaths())->explore(folded: [], mode: 'start', anchor: '/news', steps: 2, trail: []);

		$this->assertSame(0, $explorer['sessions']);
		$this->assertSame([], $explorer['links']);
		$this->assertSame([[], [], []], array_column($explorer['columns'], 'nodes'));
	}//end testNothingFoldedIsEmptySteps()
}//end class
