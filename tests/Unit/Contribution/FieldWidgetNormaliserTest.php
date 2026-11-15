<?php

/**
 * FieldWidgetNormaliser: the choices, date-choices and count widgets of an
 * action field, and how they are reconciled with the field's provider.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Contribution
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

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\FieldWidgetNormaliser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The field widgets.
 */
class FieldWidgetNormaliserTest extends TestCase {
	/**
	 * Apply a declared field to an entry that already carries its key.
	 *
	 * @param array<string, mixed> $source The declared field.
	 *
	 * @return array<string, mixed>
	 */
	private function apply(array $source): array {
		return (new FieldWidgetNormaliser())->apply(entry: ['key' => 'colour'], source: $source);
	}//end apply()

	/**
	 * A field without a known widget keeps its entry as it was.
	 *
	 * @return void
	 */
	public function testAnUnknownWidgetLeavesTheEntry(): void {
		foreach ([[], ['widget' => 'slider'], ['widget' => 'Choices'], ['choiceOptions' => ['a']]] as $source) {
			$this->assertSame(expected: ['key' => 'colour'], actual: $this->apply(source: $source));
		}
	}//end testAnUnknownWidgetLeavesTheEntry()

	/**
	 * The choices widget keeps a cleaned subset of options and an other label.
	 *
	 * @return void
	 */
	public function testTheChoicesWidgetKeepsACleanedSubset(): void {
		$this->assertSame(
			expected: ['key' => 'colour', 'widget' => 'choices', 'choiceOptions' => ['red', '7', 'blue'], 'otherLabel' => 'Something else'],
			actual: $this->apply(
				source: [
					'widget'        => 'choices',
					'choiceOptions' => ['red', 7, '', 'red', 1.5, null, ['x'], str_repeat('x', 101), 'blue'],
					'otherLabel'    => 'Something else',
				]
			)
		);
	}//end testTheChoicesWidgetKeepsACleanedSubset()

	/**
	 * Without a subset the choices widget shows every option and takes no other label.
	 *
	 * @return void
	 */
	public function testWithoutASubsetThereIsNoOtherLabel(): void {
		$this->assertSame(
			expected: ['key' => 'colour', 'widget' => 'choices'],
			actual: $this->apply(source: ['widget' => 'choices', 'choiceOptions' => ['', null], 'otherLabel' => 'Other'])
		);
		$this->assertSame(
			expected: ['key' => 'colour', 'widget' => 'choices'],
			actual: $this->apply(source: ['widget' => 'choices', 'choiceOptions' => 'red,blue'])
		);
	}//end testWithoutASubsetThereIsNoOtherLabel()

	/**
	 * At most twenty options are kept, the first twenty distinct ones.
	 *
	 * @return void
	 */
	public function testTheSubsetIsCappedAtTwenty(): void {
		$options = array_merge(['o1', 'o1'], array_map(static fn (int $n): string => 'o'.$n, range(2, 30)));
		$kept    = $this->apply(source: ['widget' => 'choices', 'choiceOptions' => $options])['choiceOptions'];
		$this->assertCount(expectedCount: 20, haystack: $kept);
		$this->assertSame(expected: ['o1', 'o2'], actual: array_slice($kept, 0, 2));
		$this->assertSame(expected: 'o20', actual: $kept[19]);
	}//end testTheSubsetIsCappedAtTwenty()

	/**
	 * An other label must be a string with text, of at most 200 characters.
	 *
	 * @return void
	 */
	public function testTheOtherLabelIsBounded(): void {
		$source = ['widget' => 'choices', 'choiceOptions' => ['red']];
		$this->assertArrayNotHasKey(key: 'otherLabel', array: $this->apply(source: $source + ['otherLabel' => '   ']));
		$this->assertArrayNotHasKey(key: 'otherLabel', array: $this->apply(source: $source + ['otherLabel' => str_repeat('x', 201)]));
		$this->assertArrayNotHasKey(key: 'otherLabel', array: $this->apply(source: $source + ['otherLabel' => 5]));
		$this->assertSame(expected: str_repeat('x', 200), actual: $this->apply(source: $source + ['otherLabel' => str_repeat('x', 200)])['otherLabel']);
	}//end testTheOtherLabelIsBounded()

	/**
	 * Declared day counts and the count kept.
	 *
	 * @return array<string, array{0: mixed, 1: int}>
	 */
	public static function dayCounts(): array {
		return [
			'one'          => [1, 1],
			'five'         => [5, 5],
			'three'        => [3, 3],
			'zero'         => [0, 2],
			'six'          => [6, 2],
			'string'       => ['3', 2],
			'missing'      => [null, 2],
		];
	}//end dayCounts()

	/**
	 * The date-choices widget offers 1 to 5 days, 2 when it names no valid count.
	 *
	 * @param mixed $declared The declared day count.
	 * @param int $expected The kept day count.
	 *
	 * @return void
	 */
	#[DataProvider('dayCounts')]
	public function testDateChoicesOffersOneToFiveDays(mixed $declared, int $expected): void {
		$this->assertSame(
			expected: ['key' => 'colour', 'widget' => 'dateChoices', 'dateChoices' => $expected],
			actual: $this->apply(source: ['widget' => 'dateChoices', 'dateChoices' => $declared])
		);
	}//end testDateChoicesOffersOneToFiveDays()

	/**
	 * The count widget allows counts up to 999.
	 *
	 * @return void
	 */
	public function testTheCountWidgetAllowsUpTo999(): void {
		$this->assertSame(expected: 999, actual: $this->apply(source: ['widget' => 'count', 'max' => 999])['max']);
		$this->assertArrayNotHasKey(key: 'max', array: $this->apply(source: ['widget' => 'count', 'max' => 1000]));
	}//end testTheCountWidgetAllowsUpTo999()

	/**
	 * An action without field configs is returned as it was.
	 *
	 * @return void
	 */
	public function testReconcileLeavesAnActionWithoutConfigs(): void {
		$normaliser = new FieldWidgetNormaliser();
		$this->assertSame(expected: ['id' => 'a'], actual: $normaliser->reconcile(action: ['id' => 'a']));
		$this->assertSame(expected: ['fieldConfigs' => 'x'], actual: $normaliser->reconcile(action: ['fieldConfigs' => 'x']));
	}//end testReconcileLeavesAnActionWithoutConfigs()

	/**
	 * A choices widget needs a provider to choose from; without one it is dropped.
	 *
	 * @return void
	 */
	public function testAChoicesWidgetWithoutAProviderIsDropped(): void {
		$action = [
			'fieldConfigs' => [
				'colour' => ['widget' => 'choices', 'choiceOptions' => ['red'], 'otherLabel' => 'Other', 'label' => 'Colour'],
				'plain'  => ['label' => 'Plain'],
			],
		];
		$this->assertSame(
			expected: ['colour' => ['label' => 'Colour'], 'plain' => ['label' => 'Plain']],
			actual: (new FieldWidgetNormaliser())->reconcile(action: $action)['fieldConfigs']
		);
	}//end testAChoicesWidgetWithoutAProviderIsDropped()

	/**
	 * Against a static provider the subset keeps only values the provider offers.
	 *
	 * @return void
	 */
	public function testAStaticProviderNarrowsTheSubset(): void {
		$provider = ['type' => 'static', 'options' => [['value' => 'red'], ['value' => 'blue'], ['label' => 'no value'], 'junk', ['value' => 3]]];
		$action   = [
			'optionsProviders' => ['colour' => $provider, 'size' => $provider],
			'fieldConfigs'     => [
				'colour' => ['widget' => 'choices', 'choiceOptions' => ['red', 'green', '3'], 'otherLabel' => 'Other'],
				'size'   => ['widget' => 'choices', 'choiceOptions' => ['green'], 'otherLabel' => 'Other'],
			],
		];
		$configs  = (new FieldWidgetNormaliser())->reconcile(action: $action)['fieldConfigs'];
		$this->assertSame(expected: ['widget' => 'choices', 'choiceOptions' => ['red', '3'], 'otherLabel' => 'Other'], actual: $configs['colour']);
		$this->assertSame(expected: ['widget' => 'choices'], actual: $configs['size']);
	}//end testAStaticProviderNarrowsTheSubset()

	/**
	 * A provider that is not static keeps the declared subset: its values are only known at run time.
	 *
	 * @return void
	 */
	public function testANonStaticProviderKeepsTheSubset(): void {
		$config = ['widget' => 'choices', 'choiceOptions' => ['red'], 'otherLabel' => 'Other'];
		$action = ['optionsProviders' => ['colour' => ['type' => 'collection']], 'fieldConfigs' => ['colour' => $config]];
		$this->assertSame(expected: $config, actual: (new FieldWidgetNormaliser())->reconcile(action: $action)['fieldConfigs']['colour']);

		$bare = ['widget' => 'choices'];
		$action = ['optionsProviders' => ['colour' => ['type' => 'static', 'options' => []]], 'fieldConfigs' => ['colour' => $bare]];
		$this->assertSame(expected: $bare, actual: (new FieldWidgetNormaliser())->reconcile(action: $action)['fieldConfigs']['colour']);
	}//end testANonStaticProviderKeepsTheSubset()

	/**
	 * Date choices survive only on a date input.
	 *
	 * @return void
	 */
	public function testDateChoicesNeedADateInput(): void {
		$action  = [
			'fieldConfigs' => [
				'day'   => ['widget' => 'dateChoices', 'dateChoices' => 3, 'input' => 'date'],
				'stamp' => ['widget' => 'dateChoices', 'dateChoices' => 3, 'input' => 'datetime'],
				'text'  => ['widget' => 'dateChoices', 'dateChoices' => 3],
			],
		];
		$configs = (new FieldWidgetNormaliser())->reconcile(action: $action)['fieldConfigs'];
		$this->assertSame(expected: ['widget' => 'dateChoices', 'dateChoices' => 3, 'input' => 'date'], actual: $configs['day']);
		$this->assertSame(expected: ['input' => 'datetime'], actual: $configs['stamp']);
		$this->assertSame(expected: [], actual: $configs['text']);
	}//end testDateChoicesNeedADateInput()

	/**
	 * A count widget is dropped from a field whose value a provider supplies.
	 *
	 * @return void
	 */
	public function testACountWidgetYieldsToAProvider(): void {
		$action  = [
			'optionsProviders' => ['tickets' => ['type' => 'static', 'options' => []]],
			'fieldConfigs'     => [
				'tickets' => ['widget' => 'count', 'max' => 4, 'label' => 'Tickets'],
				'guests'  => ['widget' => 'count', 'max' => 4],
			],
		];
		$configs = (new FieldWidgetNormaliser())->reconcile(action: $action)['fieldConfigs'];
		$this->assertSame(expected: ['label' => 'Tickets'], actual: $configs['tickets']);
		$this->assertSame(expected: ['widget' => 'count', 'max' => 4], actual: $configs['guests']);
	}//end testACountWidgetYieldsToAProvider()
}//end class
