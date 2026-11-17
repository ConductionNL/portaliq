<?php

/**
 * TrafficEventParams, TrafficRollupValues, DocumentEntryExtras and
 * StartTileNormaliser: small value helpers, each keeping only what is
 * well formed.
 *
 * @category Tests
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
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Contribution\StartTileNormaliser;
use OCA\Portaliq\Service\DocumentEntryExtras;
use OCA\Portaliq\Service\Traffic\TrafficRollupValues;
use OCA\Portaliq\Service\TrafficEventParams;
use PHPUnit\Framework\TestCase;

/**
 * The small value helpers.
 */
class SmallValueHelpersTest extends TestCase {
	/**
	 * Event params keep safe keys with scalar values, values cut to 256 characters.
	 *
	 * @return void
	 */
	public function testEventParamsKeepSafeScalars(): void {
		$params = (new TrafficEventParams())->bounded(
			value: [
				'form.step'  => 2,
				'ratio'      => 0.5,
				'ok'         => false,
				'label'      => str_repeat('x', 300),
				'bad key!'   => 'x',
				0            => 'numeric key',
				'nested'     => ['a'],
				'nothing'    => null,
				str_repeat('k', 65) => 'too long a key',
			]
		);
		$this->assertSame(expected: ['form.step', 'ratio', 'ok', 'label'], actual: array_keys($params));
		$this->assertSame(expected: [2, 0.5, false], actual: [$params['form.step'], $params['ratio'], $params['ok']]);
		$this->assertSame(expected: 256, actual: mb_strlen($params['label']));
		$this->assertSame(expected: [], actual: (new TrafficEventParams())->bounded(value: 'form=2'));
	}//end testEventParamsKeepSafeScalars()

	/**
	 * At most twenty params are kept.
	 *
	 * @return void
	 */
	public function testEventParamsAreCapped(): void {
		$declared = [];
		foreach (range(1, 30) as $n) {
			$declared['p'.$n] = $n;
		}

		$params = (new TrafficEventParams())->bounded(value: $declared);
		$this->assertCount(expectedCount: 20, haystack: $params);
		$this->assertSame(expected: 'p20', actual: array_key_last($params));
	}//end testEventParamsAreCapped()

	/**
	 * Rollup values read numbers, never below zero, and a ratio over nothing is zero.
	 *
	 * @return void
	 */
	public function testRollupValuesAreNonNegativeNumbers(): void {
		$values = new TrafficRollupValues();
		$this->assertSame(expected: [5, 0, 0, 3], actual: array_map(static fn (mixed $value): int => $values->int(value: $value), ['5', -2, 'x', 3.9]));
		$this->assertSame(expected: [2.5, 0.0, 0.0], actual: array_map(static fn (mixed $raw): float => $values->float(value: $raw), ['2.5', -1, null]));
		$this->assertSame(expected: 0.25, actual: $values->ratio(numerator: 1.0, denominator: 4));
		$this->assertSame(expected: 0.0, actual: $values->ratio(numerator: 3.0, denominator: 0));
		$this->assertSame(expected: 0.0, actual: $values->ratio(numerator: 3.0, denominator: -1));
	}//end testRollupValuesAreNonNegativeNumbers()

	/**
	 * Document extras keep short trimmed texts and a strict new flag.
	 *
	 * @return void
	 */
	public function testDocumentExtrasKeepShortTexts(): void {
		$extras = new DocumentEntryExtras();
		$this->assertSame(
			expected: ['meta' => 'PDF, 2 MB', 'status' => 'Ondertekend', 'isNew' => true],
			actual: $extras->extrasOf(entry: ['meta' => ' PDF, 2 MB ', 'status' => 'Ondertekend', 'statusTone' => '   ', 'isNew' => true, 'other' => 'x'])
		);
		$this->assertSame(expected: [], actual: $extras->extrasOf(entry: ['meta' => str_repeat('m', 201), 'status' => 5, 'isNew' => 'yes']));
		$this->assertSame(expected: ['isNew' => false], actual: $extras->extrasOf(entry: ['isNew' => false]));
	}//end testDocumentExtrasKeepShortTexts()

	/**
	 * Start tiles keep a summary and the served audiences, on create and endpoint actions only.
	 *
	 * @return void
	 */
	public function testStartTilesOnCreateActions(): void {
		$tiles = new StartTileNormaliser();
		$this->assertSame(
			expected: ['type' => 'create', 'summary' => 'Meld een probleem', 'audiences' => ['resident', 'business']],
			actual: $tiles->apply(
				action: ['type' => 'create', 'summary' => ' Meld een probleem ', 'audiences' => ['resident', 'staff', 'business', 'resident', 3]],
				served: ['resident', 'business']
			)
		);
		$this->assertSame(
			expected: ['endpoint' => '/x'],
			actual: $tiles->apply(action: ['endpoint' => '/x', 'summary' => str_repeat('s', 201), 'audiences' => ['resident']], served: null)
		);
		$this->assertSame(
			expected: ['type' => 'update'],
			actual: $tiles->apply(action: ['type' => 'update', 'summary' => 'Wijzig', 'audiences' => ['resident']], served: ['resident'])
		);
	}//end testStartTilesOnCreateActions()
}//end class
