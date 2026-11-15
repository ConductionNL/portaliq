<?php

/**
 * Portaliq Js Value Test
 *
 * @category Test
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

use OCA\Portaliq\Service\Intake\JsValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The server reads an answer the way the browser's String() and Number() do,
 * so a hidden field is skipped on both sides alike.
 *
 * @covers \OCA\Portaliq\Service\Intake\JsValue
 *
 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-the-server-skips-a-hidden-field-req-icq-002
 */
class JsValueTest extends TestCase {

	/**
	 * String(value) for each kind of value.
	 *
	 * @return array<string, array{0: bool, 1: mixed, 2: string}>
	 */
	public static function texts(): array {
		return [
			'undefined'        => [false, 'ignored', 'undefined'],
			'null'             => [true, null, 'null'],
			'true'             => [true, true, 'true'],
			'false'            => [true, false, 'false'],
			'whole float'      => [true, 3.0, '3'],
			'negative float'   => [true, -2.0, '-2'],
			'fraction'         => [true, 0.1, '0.1'],
			'NaN'              => [true, NAN, 'NaN'],
			'Infinity'         => [true, INF, 'Infinity'],
			'-Infinity'        => [true, -INF, '-Infinity'],
			'int'              => [true, 42, '42'],
			'string'           => [true, 'ja', 'ja'],
			'object'           => [true, ['a' => 1], '[object Object]'],
			'empty list'       => [true, [], ''],
			'list'             => [true, ['a', 2, null, true, 1.5], 'a,2,,true,1.5'],
			'nested list'      => [true, [[1, 2], 3], '1,2,3'],
		];
	}//end texts()

	#[DataProvider('texts')]
	public function testTextIsJavaScriptsString(bool $found, mixed $value, string $expected): void {
		$this->assertSame($expected, (new JsValue())->text($found, $value));
	}//end testTextIsJavaScriptsString()

	/**
	 * Number(value) for each kind of value that is not NaN.
	 *
	 * @return array<string, array{0: mixed, 1: float}>
	 */
	public static function numbers(): array {
		return [
			'null'               => [null, 0.0],
			'false'              => [false, 0.0],
			'true'               => [true, 1.0],
			'int'                => [7, 7.0],
			'float'              => [2.5, 2.5],
			'empty list'         => [[], 0.0],
			'one-item list'      => [[' 12 '], 12.0],
			'blank text'         => ['   ', 0.0],
			'decimal text'       => [' -3.5 ', -3.5],
			'leading dot'        => ['.5', 0.5],
			'trailing dot'       => ['5.', 5.0],
			'exponent'           => ['1e3', 1000.0],
			'signed exponent'    => ['+2E-1', 0.2],
			'hexadecimal'        => ['0x1F', 31.0],
			'upper hexadecimal'  => ['0XfF', 255.0],
			'Infinity'           => ['Infinity', INF],
			'+Infinity'          => ['+Infinity', INF],
			'-Infinity'          => ['-Infinity', -INF],
		];
	}//end numbers()

	#[DataProvider('numbers')]
	public function testNumberIsJavaScriptsNumber(mixed $value, float $expected): void {
		$this->assertSame($expected, (new JsValue())->number(true, $value));
	}//end testNumberIsJavaScriptsNumber()

	/**
	 * Values Number() turns into NaN.
	 *
	 * @return array<string, array{0: bool, 1: mixed}>
	 */
	public static function notANumber(): array {
		return [
			'undefined'       => [false, 1],
			'word'            => [true, 'tien'],
			'number and word' => [true, '10 euro'],
			'negative hex'    => [true, '-0x10'],
			'two items'       => [true, [1, 2]],
			'object'          => [true, ['a' => 1]],
			'one word item'   => [true, ['x']],
			'lower infinity'  => [true, 'infinity'],
		];
	}//end notANumber()

	#[DataProvider('notANumber')]
	public function testNumberIsNanWhereJavaScriptGivesNan(bool $found, mixed $value): void {
		$this->assertNan((new JsValue())->number($found, $value));
	}//end testNumberIsNanWhereJavaScriptGivesNan()

	public function testReadPathFollowsTheDotsAndTellsUndefinedFromNull(): void {
		$answers = ['person' => ['name' => 'Sanne', 'partner' => null], 'count' => 0, 'list' => ['a', 'b']];
		$value = new JsValue();

		$this->assertSame([true, 'Sanne'], $value->readPath($answers, 'person.name'));
		$this->assertSame([true, 0], $value->readPath($answers, 'count'));
		$this->assertSame([true, 'b'], $value->readPath($answers, 'list.1'));
		$this->assertSame([true, null], $value->readPath($answers, 'person.partner'));
		$this->assertSame([true, null], $value->readPath($answers, 'person.partner.name.first'));
		$this->assertSame([false, null], $value->readPath($answers, 'person.age'));
		$this->assertSame([false, null], $value->readPath($answers, 'missing.deeper.still'));
		$this->assertSame([false, null], $value->readPath($answers, 'count.value'));
		$this->assertSame([true, $answers['person']], $value->readPath($answers, 'person'));
	}//end testReadPathFollowsTheDotsAndTellsUndefinedFromNull()

	public function testIsTruthyIsJavaScriptsTruthiness(): void {
		$value = new JsValue();

		foreach ([[], ['a' => 0], 'x', '0', 1, -1, 0.5, true] as $truthy) {
			$this->assertTrue($value->isTruthy($truthy), var_export($truthy, true));
		}

		foreach ([null, false, '', 0, 0.0] as $falsy) {
			$this->assertFalse($value->isTruthy($falsy), var_export($falsy, true));
		}
	}//end testIsTruthyIsJavaScriptsTruthiness()
}//end class
