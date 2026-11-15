<?php

/**
 * TrafficExperimentDefinitions: which declared A/B experiments survive, and
 * how their variants, changes, weights and instants are cleaned.
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

use OCA\Portaliq\Service\Traffic\TrafficExperimentDefinitions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The experiment definitions.
 */
class TrafficExperimentDefinitionsTest extends TestCase {
	/**
	 * A running experiment on the home page with two variants.
	 *
	 * @param array<string, mixed> $overrides Keys to replace.
	 *
	 * @return array<string, mixed>
	 */
	private function experiment(array $overrides=[]): array {
		return $overrides + [
			'id'       => 'cta-text',
			'status'   => 'running',
			'page'     => '/',
			'variants' => [['id' => 'a'], ['id' => 'b']],
		];
	}//end experiment()

	/**
	 * The definitions of a list of declared experiments.
	 *
	 * @param mixed $declared The declared experiments.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function definitions(mixed $declared): array {
		return (new TrafficExperimentDefinitions())->definitions(value: $declared);
	}//end definitions()

	/**
	 * A minimal experiment is filled out with its defaults.
	 *
	 * @return void
	 */
	public function testAMinimalExperimentGetsItsDefaults(): void {
		$this->assertSame(
			expected: [
				[
					'id'        => 'cta-text',
					'name'      => 'cta-text',
					'status'    => 'running',
					'page'      => '/',
					'variants'  => [
						['id' => 'a', 'name' => 'a', 'weight' => 1.0, 'changes' => []],
						['id' => 'b', 'name' => 'b', 'weight' => 1.0, 'changes' => []],
					],
					'goal'      => '',
					'startedAt' => '',
					'stoppedAt' => '',
				],
			],
			actual: $this->definitions(declared: [$this->experiment()])
		);
	}//end testAMinimalExperimentGetsItsDefaults()

	/**
	 * Anything that is not a list yields no experiments.
	 *
	 * @return void
	 */
	public function testANonListYieldsNothing(): void {
		$this->assertSame(expected: [], actual: $this->definitions(declared: null));
		$this->assertSame(expected: [], actual: $this->definitions(declared: 'cta-text'));
		$this->assertSame(expected: [], actual: $this->definitions(declared: ['junk', 3, null]));
	}//end testANonListYieldsNothing()

	/**
	 * Experiments that cannot run.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function droppedExperiments(): array {
		return [
			'a draft'              => [['status' => 'draft']],
			'no status is a draft' => [['status' => null]],
			'an unknown status'    => [['status' => 'paused']],
			'an unsafe id'         => [['id' => 'cta text']],
			'an id too long'       => [['id' => str_repeat('a', 65)]],
			'no page'              => [['page' => null]],
			'a relative page'      => [['page' => 'home']],
			'a page with a query'  => [['page' => '/?x=1']],
			'a page with a hash'   => [['page' => '/#top']],
			'one variant'          => [['variants' => [['id' => 'a']]]],
			'duplicate variants'   => [['variants' => [['id' => 'a'], ['id' => 'a']]]],
			'variants not a list'  => [['variants' => 'a,b']],
			'unsafe variant ids'   => [['variants' => [['id' => 'a b'], ['id' => 'b'], 'c']]],
		];
	}//end droppedExperiments()

	/**
	 * A draft, an unknown status, a bad id or page, or fewer than two variants drop the experiment.
	 *
	 * @param array<string, mixed> $overrides What makes it unrunnable.
	 *
	 * @return void
	 */
	#[DataProvider('droppedExperiments')]
	public function testAnUnrunnableExperimentIsDropped(array $overrides): void {
		$experiment = $this->experiment(overrides: $overrides);
		if (array_key_exists('status', $overrides) === true && $overrides['status'] === null) {
			unset($experiment['status']);
		}

		$this->assertSame(expected: [], actual: $this->definitions(declared: [$experiment]));
	}//end testAnUnrunnableExperimentIsDropped()

	/**
	 * A stopped experiment is kept, so its results can still be read.
	 *
	 * @return void
	 */
	public function testAStoppedExperimentIsKept(): void {
		$this->assertSame(expected: 'stopped', actual: $this->definitions(declared: [$this->experiment(overrides: ['status' => 'stopped'])])[0]['status']);
	}//end testAStoppedExperimentIsKept()

	/**
	 * The first experiment with an id wins; later ones with that id are dropped.
	 *
	 * @return void
	 */
	public function testTheFirstExperimentWithAnIdWins(): void {
		$kept = $this->definitions(
			declared: [
				$this->experiment(overrides: ['name' => 'First']),
				$this->experiment(overrides: ['name' => 'Second']),
				$this->experiment(overrides: ['id' => 'other']),
			]
		);
		$this->assertSame(expected: ['First', 'other'], actual: array_column($kept, 'name'));
	}//end testTheFirstExperimentWithAnIdWins()

	/**
	 * Only the first twenty declared experiments are looked at.
	 *
	 * @return void
	 */
	public function testOnlyTwentyExperimentsAreRead(): void {
		$declared = [];
		foreach (range(1, 25) as $n) {
			$declared[] = $this->experiment(overrides: ['id' => 'e'.$n]);
		}

		$kept = $this->definitions(declared: $declared);
		$this->assertCount(expectedCount: TrafficExperimentDefinitions::MAX_EXPERIMENTS, haystack: $kept);
		$this->assertSame(expected: 'e20', actual: $kept[19]['id']);
	}//end testOnlyTwentyExperimentsAreRead()

	/**
	 * A page route is trimmed and loses a trailing slash, except the root itself.
	 *
	 * @return void
	 */
	public function testThePageIsNormalised(): void {
		$kept = $this->definitions(declared: [$this->experiment(overrides: ['page' => ' /diensten/afval/ '])]);
		$this->assertSame(expected: '/diensten/afval', actual: $kept[0]['page']);
		$this->assertSame(expected: '/', actual: $this->definitions(declared: [$this->experiment(overrides: ['page' => '/'])])[0]['page']);
		$this->assertSame(expected: [], actual: $this->definitions(declared: [$this->experiment(overrides: ['page' => '//'])]));
	}//end testThePageIsNormalised()

	/**
	 * Variant weights must be positive numbers; anything else weighs 1.
	 *
	 * @return void
	 */
	public function testVariantWeightsArePositive(): void {
		$variants = [
			['id' => 'a', 'weight' => 3],
			['id' => 'b', 'weight' => '0.5'],
			['id' => 'c', 'weight' => 0],
			['id' => 'd', 'weight' => -2],
			['id' => 'e', 'weight' => 'x'],
		];
		$kept     = $this->definitions(declared: [$this->experiment(overrides: ['variants' => $variants])])[0]['variants'];
		$this->assertSame(expected: [3.0, 0.5, 1.0, 1.0, 1.0], actual: array_column($kept, 'weight'));
	}//end testVariantWeightsArePositive()

	/**
	 * Changes need a selector; their text is trimmed and may be empty.
	 *
	 * @return void
	 */
	public function testChangesNeedASelector(): void {
		$changes  = [
			['selector' => ' .cta ', 'text' => ' Vraag aan '],
			['selector' => 'h1'],
			['text' => 'no selector'],
			'junk',
			['selector' => '  ', 'text' => 'x'],
		];
		$variants = [['id' => 'a', 'changes' => $changes], ['id' => 'b']];
		$variant  = $this->definitions(declared: [$this->experiment(overrides: ['variants' => $variants])])[0]['variants'][0];
		$this->assertSame(expected: [['selector' => '.cta', 'text' => 'Vraag aan'], ['selector' => 'h1', 'text' => '']], actual: $variant['changes']);
	}//end testChangesNeedASelector()

	/**
	 * A variant on another page is a redirect: it carries the route and no changes.
	 *
	 * @return void
	 */
	public function testAVariantOnAnotherPageCarriesTheRouteInstead(): void {
		$variants = [
			['id' => 'a', 'pageRoute' => '/', 'changes' => [['selector' => 'h1', 'text' => 'Hi']]],
			['id' => 'b', 'pageRoute' => '/nieuw/', 'changes' => [['selector' => 'h1', 'text' => 'Hi']]],
			['id' => 'c', 'pageRoute' => 'nieuw'],
		];
		$kept     = $this->definitions(declared: [$this->experiment(overrides: ['variants' => $variants])])[0]['variants'];
		$this->assertSame(expected: [['selector' => 'h1', 'text' => 'Hi']], actual: $kept[0]['changes']);
		$this->assertArrayNotHasKey(key: 'pageRoute', array: $kept[0]);
		$this->assertSame(expected: ['id' => 'b', 'name' => 'b', 'weight' => 1.0, 'pageRoute' => '/nieuw'], actual: $kept[1]);
		$this->assertSame(expected: [], actual: $kept[2]['changes']);
		$this->assertArrayNotHasKey(key: 'pageRoute', array: $kept[2]);
	}//end testAVariantOnAnotherPageCarriesTheRouteInstead()

	/**
	 * Names are trimmed and cut to 128 characters; a blank name falls back to the id.
	 *
	 * @return void
	 */
	public function testNamesAreTrimmedAndCut(): void {
		$kept = $this->definitions(
			declared: [
				$this->experiment(
					overrides: ['name' => '  '.str_repeat('n', 200), 'variants' => [['id' => 'a', 'name' => ' Control '], ['id' => 'b', 'name' => ' ']]]
				),
			]
		)[0];
		$this->assertSame(expected: str_repeat('n', 128), actual: $kept['name']);
		$this->assertSame(expected: ['Control', 'b'], actual: array_column($kept['variants'], 'name'));
	}//end testNamesAreTrimmedAndCut()

	/**
	 * Instants are written as UTC with milliseconds; unreadable ones are empty.
	 *
	 * @return void
	 */
	public function testInstantsAreWrittenAsUtc(): void {
		$kept = $this->definitions(
			declared: [$this->experiment(overrides: ['startedAt' => '2026-11-01T10:00:00+01:00', 'stoppedAt' => 'not a date', 'goal' => 'apply'])]
		)[0];
		$this->assertSame(expected: '2026-11-01T09:00:00.000Z', actual: $kept['startedAt']);
		$this->assertSame(expected: '', actual: $kept['stoppedAt']);
		$this->assertSame(expected: 'apply', actual: $kept['goal']);
		$this->assertSame(expected: '', actual: $this->definitions(declared: [$this->experiment(overrides: ['goal' => 'a goal'])])[0]['goal']);
	}//end testInstantsAreWrittenAsUtc()
}//end class
