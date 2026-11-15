<?php

/**
 * TrafficRollupOutcomes: goals, experiments, funnels and forms summed over
 * several days, and the rates and drop-offs worked out once summed.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service\Traffic
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Traffic;

use OCA\Portaliq\Service\Traffic\TrafficExperiments;
use OCA\Portaliq\Service\Traffic\TrafficRollupOutcomes;
use PHPUnit\Framework\TestCase;

/**
 * The rollup outcomes.
 */
class TrafficRollupOutcomesTest extends TestCase {
	/**
	 * The outcomes summer.
	 *
	 * @return TrafficRollupOutcomes
	 */
	private function outcomes(): TrafficRollupOutcomes {
		return new TrafficRollupOutcomes(experiments: new TrafficExperiments());
	}//end outcomes()

	/**
	 * Goals sum by id over days; rows without an id are skipped and values are rounded to cents.
	 *
	 * @return void
	 */
	public function testGoalsSumById(): void {
		$outcomes = $this->outcomes();
		$goals    = $outcomes->addGoals(
			into: [],
			rows: [['id' => 'apply', 'name' => 'Aanvraag', 'conversions' => 2, 'value' => 10.005], ['name' => 'no id'], 'junk']
		);
		$goals    = $outcomes->addGoals(into: $goals, rows: [['id' => 'apply', 'conversions' => '3', 'completions' => 1, 'value' => 0.1], ['id' => 'pay']]);
		$this->assertSame(
			expected: [
				'apply' => ['id' => 'apply', 'name' => 'Aanvraag', 'conversions' => 5, 'completions' => 1, 'value' => 10.11],
				'pay'   => ['id' => 'pay', 'name' => 'pay', 'conversions' => 0, 'completions' => 0, 'value' => 0.0],
			],
			actual: $goals
		);
		$this->assertSame(expected: $goals, actual: $outcomes->addGoals(into: $goals, rows: 'not rows'));
	}//end testGoalsSumById()

	/**
	 * Negative or non-numeric counts never lower a sum.
	 *
	 * @return void
	 */
	public function testBadCountsAddNothing(): void {
		$goals = $this->outcomes()->addGoals(into: [], rows: [['id' => 'g', 'conversions' => -4, 'completions' => 'many', 'value' => -1]]);
		$this->assertSame(expected: [0, 0, 0.0], actual: [$goals['g']['conversions'], $goals['g']['completions'], $goals['g']['value']]);
	}//end testBadCountsAddNothing()

	/**
	 * Experiments sum their variants by id, and the latest name and status win.
	 *
	 * @return void
	 */
	public function testExperimentsSumTheirVariants(): void {
		$outcomes    = $this->outcomes();
		$experiments = $outcomes->addExperiments(
			into: [],
			rows: [
				[
					'id'       => 'cta',
					'variants' => [['id' => 'a', 'sessions' => 10, 'conversions' => 1], ['id' => 'b', 'sessions' => 10, 'conversions' => 2]],
				],
			]
		);
		$experiments = $outcomes->addExperiments(
			into: $experiments,
			rows: [
				[
					'id'       => 'cta',
					'name'     => 'Knoptekst',
					'status'   => 'stopped',
					'variants' => [['id' => 'a', 'sessions' => 30, 'conversions' => 3], ['sessions' => 5]],
				],
			]
		);
		$this->assertSame(expected: ['Knoptekst', 'stopped'], actual: [$experiments['cta']['name'], $experiments['cta']['status']]);
		$this->assertSame(
			expected: [
				'a' => ['id' => 'a', 'name' => 'a', 'sessions' => 40, 'conversions' => 4],
				'b' => ['id' => 'b', 'name' => 'b', 'sessions' => 10, 'conversions' => 2],
			],
			actual: $experiments['cta']['variants']
		);
	}//end testExperimentsSumTheirVariants()

	/**
	 * Finishing an experiment gives each variant its conversion rate, and adds a verdict.
	 *
	 * @return void
	 */
	public function testFinishedExperimentsCarryRates(): void {
		$outcomes = $this->outcomes();
		$summed   = $outcomes->addExperiments(
			into: [],
			rows: [
				[
					'id'       => 'cta',
					'variants' => [['id' => 'a', 'sessions' => 3, 'conversions' => 1], ['id' => 'b', 'sessions' => 0, 'conversions' => 0]],
				],
			]
		);
		$finished = $outcomes->finishExperiments(experiments: $summed);
		$this->assertCount(expectedCount: 1, haystack: $finished);
		$this->assertSame(expected: [0.333, 0.0], actual: array_column($finished[0]['variants'], 'rate'));
		$this->assertSame(expected: ['a', 'b'], actual: array_column($finished[0]['variants'], 'id'));
		$this->assertGreaterThan(expected: 4, actual: count($finished[0]));
	}//end testFinishedExperimentsCarryRates()

	/**
	 * Funnel steps sum by position, and finishing works out the drop-off from the step before.
	 *
	 * @return void
	 */
	public function testFunnelsSumByStepAndDropOff(): void {
		$outcomes = $this->outcomes();
		$funnels  = $outcomes->addFunnels(
			into: [],
			rows: [['id' => 'apply', 'steps' => [['name' => 'Start', 'sessions' => 60], ['name' => 'Gegevens', 'sessions' => 30], 'junk']]]
		);
		$funnels  = $outcomes->addFunnels(
			into: $funnels,
			rows: [['id' => 'apply', 'steps' => [['sessions' => 40], ['sessions' => 10], ['name' => 'Klaar', 'sessions' => 8]]]]
		);
		$this->assertSame(
			expected: [
				[
					'id'    => 'apply',
					'name'  => 'apply',
					'steps' => [
						['name' => 'Start', 'sessions' => 100, 'dropOff' => 0.0],
						['name' => 'Gegevens', 'sessions' => 40, 'dropOff' => 0.6],
						['name' => 'Klaar', 'sessions' => 8, 'dropOff' => 0.8],
					],
				],
			],
			actual: $outcomes->finishFunnels(funnels: $funnels)
		);
	}//end testFunnelsSumByStepAndDropOff()

	/**
	 * A step after an empty step has no drop-off: nothing could drop.
	 *
	 * @return void
	 */
	public function testNoDropOffAfterAnEmptyStep(): void {
		$outcomes = $this->outcomes();
		$funnels  = $outcomes->addFunnels(into: [], rows: [['id' => 'f', 'steps' => [['name' => 'A', 'sessions' => 0], ['name' => 'B', 'sessions' => 0]]]]);
		$this->assertSame(expected: [0.0, 0.0], actual: array_column($outcomes->finishFunnels(funnels: $funnels)[0]['steps'], 'dropOff'));
	}//end testNoDropOffAfterAnEmptyStep()

	/**
	 * Forms sum their counts, average their field times over days, and get a completion rate.
	 *
	 * @return void
	 */
	public function testFormsSumAndAverage(): void {
		$outcomes = $this->outcomes();
		$forms    = $outcomes->addForms(
			into: [],
			rows: [
				[
					'formId'   => 'melding',
					'starts'   => 10,
					'submits'  => 4,
					'abandons' => 6,
					'fields'   => [['fieldId' => 'postcode', 'avgMs' => 3000, 'abandonedHere' => 2]],
				],
			]
		);
		$forms    = $outcomes->addForms(
			into: $forms,
			rows: [
				[
					'formId'  => 'melding',
					'starts'  => 5,
					'submits' => 2,
					'fields'  => [['fieldId' => 'postcode', 'avgMs' => 1000, 'abandonedHere' => 1], ['avgMs' => 9]],
				],
				['starts' => 1],
			]
		);
		$this->assertSame(
			expected: [
				[
					'formId'         => 'melding',
					'starts'         => 15,
					'submits'        => 6,
					'abandons'       => 6,
					'completionRate' => 0.4,
					'fields'         => [['fieldId' => 'postcode', 'avgMs' => 2000, 'abandonedHere' => 3]],
				],
			],
			actual: $outcomes->finishForms(forms: $forms)
		);
	}//end testFormsSumAndAverage()

	/**
	 * A form nobody started has a completion rate of zero.
	 *
	 * @return void
	 */
	public function testAnUnstartedFormHasNoRate(): void {
		$outcomes = $this->outcomes();
		$forms    = $outcomes->addForms(into: [], rows: [['formId' => 'f', 'submits' => 3]]);
		$this->assertSame(expected: 0.0, actual: $outcomes->finishForms(forms: $forms)[0]['completionRate']);
	}//end testAnUnstartedFormHasNoRate()
}//end class
