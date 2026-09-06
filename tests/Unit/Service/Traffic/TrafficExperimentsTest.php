<?php

/**
 * Unit tests for TrafficExperiments.
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

use OCA\Portaliq\Service\Traffic\TrafficExperimentDefinitions;
use OCA\Portaliq\Service\Traffic\TrafficExperiments;
use OCA\Portaliq\Service\Traffic\TrafficOutcomeDefinitions;
use PHPUnit\Framework\TestCase;

/**
 * What a portal may declare, how a day of sessions is counted against
 * it, and when a winner may be named.
 */
class TrafficExperimentsTest extends TestCase {

	/**
	 * The class under test.
	 *
	 * @var TrafficExperiments
	 */
	private TrafficExperiments $experiments;


	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->experiments = new TrafficExperiments();
	}//end setUp()


	/**
	 * One stored event, tagged or not.
	 *
	 * @param string               $at     occurredAt.
	 * @param string               $path   pagePath.
	 * @param array<string, mixed> $params The params.
	 *
	 * @return array<string, mixed> The event.
	 */
	private function event(string $at, string $path, array $params = []): array {
		return ['name' => 'page_view', 'occurredAt' => $at, 'pagePath' => $path, 'params' => $params];
	}//end event()


	/**
	 * A configured experiment with two variants.
	 *
	 * @param array<string, mixed> $extra Fields over the defaults.
	 *
	 * @return array<string, mixed> The configured row.
	 */
	private function configured(array $extra = []): array {
		return $extra + [
			'id' => 'hero',
			'name' => 'Hero text',
			'status' => 'running',
			'page' => '/',
			'goal' => 'contact',
			'variants' => [
				['id' => 'a', 'name' => 'Control', 'changes' => []],
				['id' => 'b', 'name' => 'Short', 'weight' => 3, 'changes' => [['selector' => 'h1', 'text' => 'Welkom']]],
			],
		];
	}//end configured()


	/**
	 * The resolved goal the experiments count against.
	 *
	 * @return array<int, array<string, mixed>> One page-reached goal on /contact.
	 */
	private function goals(): array {
		return (new TrafficOutcomeDefinitions())->goals(
			value: [['id' => 'contact', 'name' => 'Contact', 'type' => 'page_reached', 'match' => ['pathEquals' => '/contact']]]
		);
	}//end goals()


	/**
	 * A draft is dropped, an experiment with one variant is dropped, a
	 * variant route equal to the page becomes a text variant, routes are
	 * normalised and the weight defaults to one.
	 *
	 * @return void
	 */
	public function testDefinitionsKeepOnlyWhatCanRun(): void {
		$resolved = (new TrafficExperimentDefinitions())->definitions(value: [
			$this->configured(),
			$this->configured(['id' => 'draft', 'status' => 'draft']),
			$this->configured(['id' => 'lonely', 'variants' => [['id' => 'a']]]),
			$this->configured(['id' => 'routes', 'page' => '/over-ons/', 'variants' => [
				['id' => 'a', 'pageRoute' => '/over-ons'],
				['id' => 'b', 'pageRoute' => '/contact/'],
			]]),
			$this->configured(['id' => 'hero']),
			'not a row',
		]);

		$this->assertSame(['hero', 'routes'], array_column($resolved, 'id'), 'draft, one-variant and duplicate ids are dropped');
		$this->assertSame(1.0, $resolved[0]['variants'][0]['weight']);
		$this->assertSame(3.0, $resolved[0]['variants'][1]['weight']);
		$this->assertSame([['selector' => 'h1', 'text' => 'Welkom']], $resolved[0]['variants'][1]['changes']);
		$this->assertSame('/over-ons', $resolved[1]['page'], 'a trailing slash is dropped');
		$this->assertSame([], $resolved[1]['variants'][0]['changes'], 'a route equal to the page is a control, not a redirect to itself');
		$this->assertArrayNotHasKey('pageRoute', $resolved[1]['variants'][0]);
		$this->assertSame('/contact', $resolved[1]['variants'][1]['pageRoute']);
		$this->assertSame('', $resolved[0]['stoppedAt']);
	}//end testDefinitionsKeepOnlyWhatCanRun()


	/**
	 * A tag is accepted only for a running experiment and one of its
	 * variants.
	 *
	 * @return void
	 */
	public function testATagMustNameARunningExperimentAndItsVariant(): void {
		$config = ['experiments' => (new TrafficExperimentDefinitions())->definitions(value: [
			$this->configured(),
			$this->configured(['id' => 'old', 'status' => 'stopped', 'stoppedAt' => '2026-09-01T00:00:00Z']),
		])];

		$this->assertTrue($this->experiments->acceptsTag(config: $config, experiment: 'hero', variant: 'b'));
		$this->assertFalse($this->experiments->acceptsTag(config: $config, experiment: 'hero', variant: 'c'), 'not a variant');
		$this->assertFalse($this->experiments->acceptsTag(config: $config, experiment: 'old', variant: 'a'), 'stopped');
		$this->assertFalse($this->experiments->acceptsTag(config: $config, experiment: 'nope', variant: 'a'), 'unknown');
	}//end testATagMustNameARunningExperimentAndItsVariant()


	/**
	 * A session is counted for the variant of its first tagged event and
	 * converts when it meets the goal; an untagged session is not
	 * counted at all.
	 *
	 * @return void
	 */
	public function testSessionsAreCountedPerVariantAgainstTheGoal(): void {
		$tagA = ['experiment' => 'hero', 'variant' => 'a'];
		$tagB = ['experiment' => 'hero', 'variant' => 'b'];
		$sessions = [
			['events' => [$this->event('2026-09-05T10:00:00.000Z', '/', $tagA), $this->event('2026-09-05T10:00:10.000Z', '/contact', $tagA)]],
			['events' => [$this->event('2026-09-05T10:01:00.000Z', '/', $tagA)]],
			['events' => [$this->event('2026-09-05T10:02:00.000Z', '/', $tagB), $this->event('2026-09-05T10:02:10.000Z', '/over-ons', $tagB)]],
			['events' => [$this->event('2026-09-05T10:03:00.000Z', '/contact')]],
		];

		$rows = $this->experiments->rows(
			experiments: (new TrafficExperimentDefinitions())->definitions(value: [$this->configured()]),
			sessions: $sessions,
			goals: $this->goals()
		);

		$this->assertCount(1, $rows);
		$this->assertSame('hero', $rows[0]['id']);
		$this->assertSame('running', $rows[0]['status']);
		$this->assertSame(
			[
				['id' => 'a', 'name' => 'Control', 'sessions' => 2, 'conversions' => 1, 'rate' => 0.5],
				['id' => 'b', 'name' => 'Short', 'sessions' => 1, 'conversions' => 0, 'rate' => 0.0],
			],
			$rows[0]['variants']
		);
		$this->assertSame('', $rows[0]['winner'], 'three sessions is not enough data');
	}//end testSessionsAreCountedPerVariantAgainstTheGoal()


	/**
	 * A stopped experiment keeps its rows but counts no session tagged
	 * after the moment it stopped.
	 *
	 * @return void
	 */
	public function testAStoppedExperimentCountsNoFurther(): void {
		$tag = ['experiment' => 'hero', 'variant' => 'a'];
		$rows = $this->experiments->rows(
			experiments: (new TrafficExperimentDefinitions())->definitions(value: [
				$this->configured(['status' => 'stopped', 'stoppedAt' => '2026-09-05T12:00:00Z']),
			]),
			sessions: [
				['events' => [$this->event('2026-09-05T11:59:00.000Z', '/', $tag)]],
				['events' => [$this->event('2026-09-05T12:00:01.000Z', '/', $tag)]],
			],
			goals: []
		);

		$this->assertSame('stopped', $rows[0]['status']);
		$this->assertSame(1, $rows[0]['variants'][0]['sessions'], 'the session after the stop is not counted');
		$this->assertSame(0, $rows[0]['variants'][0]['conversions'], 'no goal, no conversion');
	}//end testAStoppedExperimentCountsNoFurther()


	/**
	 * The two-proportion z-test on a known table: 50 of 1000 against 80
	 * of 1000 is z = 2.72, a two-sided p of 0.0065, so 0.993 confidence.
	 *
	 * @return void
	 */
	public function testTheZTestMatchesAKnownTable(): void {
		$this->assertSame(0.993, $this->experiments->zTest(conversionsA: 50, sessionsA: 1000, conversionsB: 80, sessionsB: 1000));
		$this->assertSame(0.993, $this->experiments->zTest(conversionsA: 80, sessionsA: 1000, conversionsB: 50, sessionsB: 1000), 'symmetric');
		$this->assertSame(0.182, $this->experiments->zTest(conversionsA: 10, sessionsA: 100, conversionsB: 11, sessionsB: 100));
		$this->assertSame(0.0, $this->experiments->zTest(conversionsA: 5, sessionsA: 100, conversionsB: 5, sessionsB: 100), 'identical rates');
		$this->assertSame(0.0, $this->experiments->zTest(conversionsA: 0, sessionsA: 0, conversionsB: 5, sessionsB: 100), 'nothing to compare');
	}//end testTheZTestMatchesAKnownTable()


	/**
	 * A winner needs thirty sessions on every variant AND a confident
	 * difference; either alone names none.
	 *
	 * @return void
	 */
	public function testAWinnerNeedsEnoughSessionsAndASignificantDifference(): void {
		$variant = static fn (string $id, int $sessions, int $conversions): array => ['id' => $id, 'sessions' => $sessions, 'conversions' => $conversions];

		$tooFew = $this->experiments->verdict(variants: [$variant('a', 29, 0), $variant('b', 40, 20)]);
		$this->assertSame('', $tooFew['winner'], 'twenty-nine sessions is not enough, whatever the rates');
		$this->assertGreaterThan(0.95, $tooFew['confidence'], 'the confidence is still reported');

		$tooClose = $this->experiments->verdict(variants: [$variant('a', 100, 10), $variant('b', 100, 11)]);
		$this->assertSame(['winner' => '', 'confidence' => 0.182], $tooClose);

		$clear = $this->experiments->verdict(variants: [$variant('a', 30, 2), $variant('b', 30, 12), $variant('c', 30, 3)]);
		$this->assertSame('b', $clear['winner']);
		// 12 of 30 against the SECOND best, 3 of 30 (z = 2.68), not against
		// the worst, 2 of 30 (z = 3.05, which would read 0.998).
		$this->assertSame(0.993, $clear['confidence'], 'the best is tested against the second best');
	}//end testAWinnerNeedsEnoughSessionsAndASignificantDifference()
}//end class
