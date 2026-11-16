<?php

/**
 * VisibleWhenComparison: the server's reading of a form field's visibleWhen
 * condition, which must match what the browser shows.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service\Intake
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

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\VisibleWhenComparison;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The visibleWhen comparisons.
 */
class VisibleWhenComparisonTest extends TestCase {
	/**
	 * Answers and whether they count as empty.
	 *
	 * @return array<string, array{0: bool, 1: mixed, 2: bool}>
	 */
	public static function blanks(): array {
		return [
			'not answered'  => [false, 'x', true],
			'null'          => [true, null, true],
			'empty string'  => [true, '', true],
			'empty list'    => [true, [], true],
			'a space'       => [true, ' ', false],
			'zero'          => [true, 0, false],
			'false'         => [true, false, false],
			'string zero'   => [true, '0', false],
			'a list'        => [true, ['a'], false],
		];
	}//end blanks()

	/**
	 * Empty and notEmpty are each other's opposite.
	 *
	 * @param bool $found Whether the field was answered.
	 * @param mixed $actual The answer.
	 * @param bool $blank Whether it counts as empty.
	 *
	 * @return void
	 */
	#[DataProvider('blanks')]
	public function testEmptyAndNotEmpty(bool $found, mixed $actual, bool $blank): void {
		$comparison = new VisibleWhenComparison();
		$this->assertSame(expected: $blank, actual: $comparison->holds(found: $found, actual: $actual, operator: 'empty', expected: null));
		$this->assertSame(expected: !$blank, actual: $comparison->holds(found: $found, actual: $actual, operator: 'notEmpty', expected: null));
	}//end testEmptyAndNotEmpty()

	/**
	 * Answers, expected values and whether they are equal as the browser compares them.
	 *
	 * @return array<string, array{0: bool, 1: mixed, 2: mixed, 3: bool}>
	 */
	public static function equalities(): array {
		return [
			'same string'             => [true, 'ja', 'ja', true],
			'different string'        => [true, 'ja', 'nee', false],
			'number and its text'     => [true, 5, '5', true],
			'float and its text'      => [true, 2.5, '2.5', true],
			'whole float as integer'  => [true, 3.0, '3', true],
			'true and its text'       => [true, true, 'true', true],
			'false is not zero'       => [true, false, '0', false],
			'null and its text'       => [true, null, 'null', true],
			'a list as joined text'   => [true, ['a', 'b'], 'a,b', true],
			'a list with a null'      => [true, ['a', null], 'a,', true],
			'an object'               => [true, ['k' => 'v'], '[object Object]', true],
			'not answered'            => [false, null, 'undefined', true],
			'not answered vs null'    => [false, null, null, false],
			'case matters'            => [true, 'Ja', 'ja', false],
		];
	}//end equalities()

	/**
	 * Eq compares the text forms, and neq is its opposite.
	 *
	 * @param bool $found Whether the field was answered.
	 * @param mixed $actual The answer.
	 * @param mixed $expected The value it is compared to.
	 * @param bool $equal Whether they are equal.
	 *
	 * @return void
	 */
	#[DataProvider('equalities')]
	public function testEqAndNeq(bool $found, mixed $actual, mixed $expected, bool $equal): void {
		$comparison = new VisibleWhenComparison();
		$this->assertSame(expected: $equal, actual: $comparison->holds(found: $found, actual: $actual, operator: 'eq', expected: $expected));
		$this->assertSame(expected: !$equal, actual: $comparison->holds(found: $found, actual: $actual, operator: 'neq', expected: $expected));
	}//end testEqAndNeq()

	/**
	 * Ordered comparisons and their outcome.
	 *
	 * @return array<string, array{0: mixed, 1: string, 2: mixed, 3: bool}>
	 */
	public static function orderings(): array {
		return [
			'numbers gt'               => [10, 'gt', 9, true],
			'equal is not gt'          => [9, 'gt', 9, false],
			'equal is gte'             => [9, 'gte', 9, true],
			'lt'                       => [8, 'lt', 9, true],
			'lte'                      => [9, 'lte', 9, true],
			'texts read as numbers'    => ['10', 'gt', '9', true],
			'spaces are trimmed'       => [' 18 ', 'gte', 18, true],
			'an exponent'              => ['1e3', 'gt', 999, true],
			'hexadecimal'              => ['0x10', 'gt', 15, true],
			'empty text is zero'       => ['', 'lt', 1, true],
			'null is zero'             => [null, 'lte', 0, true],
			'true is one'              => [true, 'gte', 1, true],
			'a one item list'          => [['4'], 'gt', 3, true],
			'a two item list is NaN'   => [['4', '5'], 'gt', 3, false],
			'not a number'             => ['veel', 'gt', 3, false],
			'not a number either way'  => ['veel', 'lte', 3, false],
			'an unknown operator is lte' => [3, 'between', 3, true],
		];
	}//end orderings()

	/**
	 * Ordered operators compare numbers as the browser converts them; NaN never holds.
	 *
	 * @param mixed $actual The answer.
	 * @param string $operator The operator.
	 * @param mixed $expected The value it is compared to.
	 * @param bool $holds Whether the condition holds.
	 *
	 * @return void
	 */
	#[DataProvider('orderings')]
	public function testOrderedComparisons(mixed $actual, string $operator, mixed $expected, bool $holds): void {
		$this->assertSame(
			expected: $holds,
			actual: (new VisibleWhenComparison())->holds(found: true, actual: $actual, operator: $operator, expected: $expected)
		);
	}//end testOrderedComparisons()

	/**
	 * A field that was not answered never satisfies an ordered comparison.
	 *
	 * @return void
	 */
	public function testAnUnansweredFieldIsNeverOrdered(): void {
		$comparison = new VisibleWhenComparison();
		foreach (['gt', 'gte', 'lt', 'lte'] as $operator) {
			$this->assertFalse(condition: $comparison->holds(found: false, actual: 5, operator: $operator, expected: 0), message: $operator);
		}
	}//end testAnUnansweredFieldIsNeverOrdered()
}//end class
