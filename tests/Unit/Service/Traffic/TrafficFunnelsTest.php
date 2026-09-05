<?php

/**
 * Unit tests for TrafficFunnels.
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

use OCA\Portaliq\Service\Traffic\TrafficFunnels;
use OCA\Portaliq\Service\Traffic\TrafficOutcomeDefinitions;
use PHPUnit\Framework\TestCase;

/**
 * In order, out of order, partial.
 */
class TrafficFunnelsTest extends TestCase {

	/**
	 * A page view.
	 *
	 * @param string $path The page path.
	 *
	 * @return array<string, mixed> The event.
	 */
	private function view(string $path): array {
		return ['name' => 'page_view', 'pagePath' => $path, 'params' => []];
	}//end view()


	/**
	 * The three-step funnel every test walks: campaign page, form page,
	 * form submitted.
	 *
	 * @param array<int, array<int, array<string, mixed>>> $sessions Events per session.
	 *
	 * @return array<string, mixed> The one funnel row.
	 */
	private function walk(array $sessions): array {
		$definitions = (new TrafficOutcomeDefinitions())->funnels(value: [[
			'id' => 'signup',
			'name' => 'Aanmelden',
			'steps' => [
				['name' => 'Campagne', 'match' => ['pathPrefix' => '/campagne']],
				['name' => 'Formulier', 'match' => ['pathEquals' => '/campagne/aanmelden']],
				['name' => 'Verzonden', 'match' => ['formId' => 'aanmelden']],
			],
		]]);
		$wrapped = array_map(static fn (array $events): array => ['visitor' => 'h', 'explicit' => false, 'events' => $events], $sessions);

		return (new TrafficFunnels())->rows(funnels: $definitions, sessions: $wrapped)[0];
	}//end walk()


	/**
	 * A session that walks every step in order counts at every step.
	 *
	 * @return void
	 */
	public function testAnInOrderSessionReachesEveryStep(): void {
		$row = $this->walk(sessions: [[
			$this->view('/campagne'),
			$this->view('/campagne/aanmelden'),
			['name' => 'form_submit', 'pagePath' => '/campagne/aanmelden', 'params' => ['formId' => 'aanmelden']],
		]]);

		$this->assertSame('signup', $row['id']);
		$this->assertSame('Aanmelden', $row['name']);
		$this->assertSame([1, 1, 1], array_column($row['steps'], 'sessions'));
		$this->assertSame([0.0, 0.0, 0.0], array_column($row['steps'], 'dropOff'));
		$this->assertSame(['Campagne', 'Formulier', 'Verzonden'], array_column($row['steps'], 'name'));
	}//end testAnInOrderSessionReachesEveryStep()


	/**
	 * A session that matched step 2 BEFORE step 1 counts for step 1 only:
	 * a step is looked for after the event that satisfied the previous one.
	 *
	 * @return void
	 */
	public function testAnOutOfOrderStepDoesNotCount(): void {
		$row = $this->walk(sessions: [[
			$this->view('/campagne/aanmelden'),
			$this->view('/campagne'),
		]]);

		// /campagne/aanmelden satisfies step 1 too (the prefix), and then
		// /campagne does not satisfy step 2; so step 1 only.
		$this->assertSame([1, 0, 0], array_column($row['steps'], 'sessions'));
		$this->assertSame([0.0, 1.0, 0.0], array_column($row['steps'], 'dropOff'));
	}//end testAnOutOfOrderStepDoesNotCount()


	/**
	 * Two sessions, one partial: sessions [2, 1, 0] and the drop-off of
	 * each step is a share of the step before it.
	 *
	 * @return void
	 */
	public function testAPartialWalkDropsOffWhereItStopped(): void {
		$row = $this->walk(sessions: [
			[$this->view('/campagne'), $this->view('/campagne/aanmelden')],
			[$this->view('/campagne'), $this->view('/over-ons')],
			[$this->view('/over-ons')],
		]);

		$this->assertSame([2, 1, 0], array_column($row['steps'], 'sessions'));
		$this->assertSame([0.0, 0.5, 1.0], array_column($row['steps'], 'dropOff'));
	}//end testAPartialWalkDropsOffWhereItStopped()


	/**
	 * One event cannot satisfy two steps: the step after it is looked for
	 * in the events that follow.
	 *
	 * @return void
	 */
	public function testOneEventSatisfiesOneStepOnly(): void {
		$row = $this->walk(sessions: [[$this->view('/campagne/aanmelden')]]);

		$this->assertSame([1, 0, 0], array_column($row['steps'], 'sessions'));
	}//end testOneEventSatisfiesOneStepOnly()


	/**
	 * A funnel without a usable step, or without an id, is dropped; a step
	 * without a name is numbered.
	 *
	 * @return void
	 */
	public function testUnusableFunnelsAreDropped(): void {
		$definitions = (new TrafficOutcomeDefinitions())->funnels(value: [
			['id' => 'empty', 'steps' => [['name' => 'x', 'match' => []]]],
			['steps' => [['match' => ['pathEquals' => '/']]]],
			['id' => 'ok', 'steps' => [['match' => ['pathEquals' => '/']], 'junk', ['match' => ['eventName' => 'scroll']]]],
		]);

		$this->assertCount(1, $definitions);
		$this->assertSame('ok', $definitions[0]['id']);
		$this->assertSame(['Step 1', 'Step 2'], array_column($definitions[0]['steps'], 'name'), 'numbered after the junk row is dropped');
	}//end testUnusableFunnelsAreDropped()
}
