<?php

/**
 * CountWidgetKeys: the count widget's bounds, unit and price label, and when
 * a field's config sheds them.
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

use OCA\Portaliq\Contribution\CountWidgetKeys;
use OCA\Portaliq\Contribution\FieldWidgetNormaliser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The count widget keys.
 */
class CountWidgetKeysTest extends TestCase {
	/**
	 * Apply the keys to an entry that already carries a key of its own.
	 *
	 * @param array<string, mixed> $source The declared field.
	 * @param int $maxCount The highest count allowed.
	 *
	 * @return array<string, mixed>
	 */
	private function apply(array $source, int $maxCount=10): array {
		return (new CountWidgetKeys())->apply(entry: ['key' => 'tickets'], source: $source, maxCount: $maxCount);
	}//end apply()

	/**
	 * A well-formed count widget keeps every key, the texts trimmed.
	 *
	 * @return void
	 */
	public function testAWellFormedWidgetKeepsEveryKey(): void {
		$this->assertSame(
			expected: [
				'key'        => 'tickets',
				'widget'     => FieldWidgetNormaliser::COUNT,
				'min'        => 1,
				'max'        => 8,
				'unit'       => ['one' => 'kaart', 'other' => 'kaarten'],
				'priceLabel' => 'EUR 5 per kaart',
			],
			actual: $this->apply(
				source: [
					'min'        => 1,
					'max'        => 8,
					'unit'       => ['one' => ' kaart ', 'other' => 'kaarten '],
					'priceLabel' => '  EUR 5 per kaart',
				]
			)
		);
	}//end testAWellFormedWidgetKeepsEveryKey()

	/**
	 * Without any keys the entry only names the widget.
	 *
	 * @return void
	 */
	public function testAnEmptySourceOnlyNamesTheWidget(): void {
		$this->assertSame(expected: ['key' => 'tickets', 'widget' => 'count'], actual: $this->apply(source: []));
	}//end testAnEmptySourceOnlyNamesTheWidget()

	/**
	 * Bounds that are not whole numbers within 0..maxCount.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function badBounds(): array {
		return [
			'negative'       => [-1],
			'above maxCount' => [11],
			'float'          => [2.0],
			'numeric string' => ['3'],
			'bool'           => [true],
			'null'           => [null],
		];
	}//end badBounds()

	/**
	 * A bound that is not a whole number in range is dropped.
	 *
	 * @param mixed $bound The declared bound.
	 *
	 * @return void
	 */
	#[DataProvider('badBounds')]
	public function testABadBoundIsDropped(mixed $bound): void {
		$entry = $this->apply(source: ['min' => $bound, 'max' => $bound]);
		$this->assertArrayNotHasKey(key: 'min', array: $entry);
		$this->assertArrayNotHasKey(key: 'max', array: $entry);
	}//end testABadBoundIsDropped()

	/**
	 * Zero and maxCount are both valid bounds.
	 *
	 * @return void
	 */
	public function testTheBoundsAreInclusive(): void {
		$entry = $this->apply(source: ['min' => 0, 'max' => 10]);
		$this->assertSame(expected: 0, actual: $entry['min']);
		$this->assertSame(expected: 10, actual: $entry['max']);
	}//end testTheBoundsAreInclusive()

	/**
	 * A max below min is dropped; min stays.
	 *
	 * @return void
	 */
	public function testAMaxBelowMinIsDropped(): void {
		$entry = $this->apply(source: ['min' => 5, 'max' => 4]);
		$this->assertSame(expected: 5, actual: $entry['min']);
		$this->assertArrayNotHasKey(key: 'max', array: $entry);
		$this->assertSame(expected: 5, actual: $this->apply(source: ['min' => 5, 'max' => 5])['max']);
	}//end testAMaxBelowMinIsDropped()

	/**
	 * Without a min, a max must be at least 1: a widget that allows at most 0 is no widget.
	 *
	 * @return void
	 */
	public function testWithoutAMinTheMaxMustBeAtLeastOne(): void {
		$this->assertArrayNotHasKey(key: 'max', array: $this->apply(source: ['max' => 0]));
		$this->assertSame(expected: 1, actual: $this->apply(source: ['max' => 1])['max']);
		$this->assertSame(expected: 0, actual: $this->apply(source: ['min' => 0, 'max' => 0])['max']);
	}//end testWithoutAMinTheMaxMustBeAtLeastOne()

	/**
	 * The unit needs both a singular and a plural.
	 *
	 * @return void
	 */
	public function testTheUnitNeedsBothForms(): void {
		foreach ([['one' => 'kaart'], ['other' => 'kaarten'], ['one' => ' ', 'other' => 'x'], 'kaarten', ['one' => 1, 'other' => 2]] as $unit) {
			$this->assertArrayNotHasKey(key: 'unit', array: $this->apply(source: ['unit' => $unit]));
		}
	}//end testTheUnitNeedsBothForms()

	/**
	 * Texts longer than 60 characters, or blank, are dropped.
	 *
	 * @return void
	 */
	public function testLongOrBlankTextsAreDropped(): void {
		$this->assertArrayNotHasKey(key: 'priceLabel', array: $this->apply(source: ['priceLabel' => str_repeat('x', 61)]));
		$this->assertArrayNotHasKey(key: 'priceLabel', array: $this->apply(source: ['priceLabel' => '   ']));
		$this->assertArrayNotHasKey(key: 'priceLabel', array: $this->apply(source: ['priceLabel' => 5]));
		$this->assertSame(expected: str_repeat('ü', 60), actual: $this->apply(source: ['priceLabel' => str_repeat('ü', 60)])['priceLabel']);
		$this->assertArrayNotHasKey(key: 'unit', array: $this->apply(source: ['unit' => ['one' => 'x', 'other' => str_repeat('y', 61)]]));
	}//end testLongOrBlankTextsAreDropped()

	/**
	 * The ceiling is the caller's maxCount, not a fixed number.
	 *
	 * @return void
	 */
	public function testTheCeilingIsTheCallersMaxCount(): void {
		$this->assertSame(expected: 999, actual: $this->apply(source: ['max' => 999], maxCount: 999)['max']);
		$this->assertArrayNotHasKey(key: 'max', array: $this->apply(source: ['max' => 999], maxCount: 998));
	}//end testTheCeilingIsTheCallersMaxCount()

	/**
	 * A plain field keeps its count keys.
	 *
	 * @return void
	 */
	public function testFitLeavesAPlainFieldAlone(): void {
		$config = ['widget' => 'count', 'min' => 1, 'max' => 3, 'unit' => ['one' => 'a', 'other' => 'b'], 'priceLabel' => 'p', 'type' => 'string'];
		$this->assertSame(expected: $config, actual: (new CountWidgetKeys())->fit(config: $config, provider: null));
	}//end testFitLeavesAPlainFieldAlone()

	/**
	 * Configs whose value comes from elsewhere: a provider, a date input, a file.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: mixed}>
	 */
	public static function sheddingConfigs(): array {
		return [
			'provider'       => [['type' => 'string'], 'catalogue'],
			'empty provider' => [['type' => 'string'], ''],
			'date input'     => [['input' => 'date'], null],
			'datetime input' => [['input' => 'datetime'], null],
			'file field'     => [['type' => 'file'], null],
		];
	}//end sheddingConfigs()

	/**
	 * A field with a provider, a date input or a file sheds every count key and nothing else.
	 *
	 * @param array<string, mixed> $base The rest of the config.
	 * @param mixed $provider The field's provider.
	 *
	 * @return void
	 */
	#[DataProvider('sheddingConfigs')]
	public function testFitShedsTheCountKeys(array $base, mixed $provider): void {
		$config = $base + ['widget' => 'count', 'min' => 1, 'max' => 3, 'unit' => ['one' => 'a', 'other' => 'b'], 'priceLabel' => 'p', 'label' => 'L'];
		$this->assertSame(expected: $base + ['label' => 'L'], actual: (new CountWidgetKeys())->fit(config: $config, provider: $provider));
	}//end testFitShedsTheCountKeys()
}//end class
