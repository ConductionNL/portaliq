<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\JsValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The server side of "visible when" reads a form answer the way the browser's
 * JavaScript does: String(), Number(), truthiness and a dotted path.
 */
#[CoversClass(JsValue::class)]
class JsValueTest extends TestCase {

	/**
	 * Values and their JavaScript String().
	 *
	 * @return array<string, array{bool, mixed, string}>
	 */
	public static function texts(): array {
		return [
			'missing' => [false, 'x', 'undefined'],
			'null' => [true, null, 'null'],
			'true' => [true, true, 'true'],
			'false' => [true, false, 'false'],
			'int' => [true, 42, '42'],
			'whole float' => [true, 3.0, '3'],
			'fraction' => [true, 0.5, '0.5'],
			'big whole float' => [true, 1e21, '1.0e+21'],
			'NaN' => [true, NAN, 'NaN'],
			'Infinity' => [true, INF, 'Infinity'],
			'-Infinity' => [true, -INF, '-Infinity'],
			'string' => [true, 'ja', 'ja'],
			'list' => [true, ['a', 1, true], 'a,1,true'],
			'list with null' => [true, ['a', null, 'b'], 'a,,b'],
			'nested list' => [true, [['a', 'b'], 'c'], 'a,b,c'],
			'empty list' => [true, [], ''],
			'object' => [true, ['k' => 'v'], '[object Object]'],
		];
	}//end texts()

	#[DataProvider('texts')]
	public function testTextIsJavascriptString(bool $found, mixed $value, string $text): void {
		$this->assertSame($text, (new JsValue())->text($found, $value));
	}//end testTextIsJavascriptString()

	/**
	 * Values and their JavaScript Number().
	 *
	 * @return array<string, array{bool, mixed, float}>
	 */
	public static function numbers(): array {
		return [
			'null' => [true, null, 0.0],
			'false' => [true, false, 0.0],
			'true' => [true, true, 1.0],
			'int' => [true, 7, 7.0],
			'float' => [true, 2.5, 2.5],
			'empty text' => [true, '  ', 0.0],
			'decimal text' => [true, ' 12.5 ', 12.5],
			'leading dot' => [true, '.5', 0.5],
			'signed exponent' => [true, '-1e3', -1000.0],
			'hex' => [true, '0x1F', 31.0],
			'Infinity' => [true, 'Infinity', INF],
			'+Infinity' => [true, '+Infinity', INF],
			'-Infinity' => [true, '-Infinity', -INF],
			'empty list' => [true, [], 0.0],
			'one-item list' => [true, ['4'], 4.0],
			'one-item nested list' => [true, [[' 3 ']], 3.0],
		];
	}//end numbers()

	#[DataProvider('numbers')]
	public function testNumberIsJavascriptNumber(bool $found, mixed $value, float $number): void {
		$this->assertSame($number, (new JsValue())->number($found, $value));
	}//end testNumberIsJavascriptNumber()

	/**
	 * Values whose JavaScript Number() is NaN.
	 *
	 * @return array<string, array{bool, mixed}>
	 */
	public static function notANumber(): array {
		return [
			'missing' => [false, 5],
			'words' => [true, 'vijf'],
			'comma decimal' => [true, '1,5'],
			'bad hex' => [true, '0xZZ'],
			'two-item list' => [true, [1, 2]],
			'object' => [true, ['k' => 1]],
			'one-item object' => [true, ['k' => '1']],
		];
	}//end notANumber()

	#[DataProvider('notANumber')]
	public function testUnreadableNumbersAreNan(bool $found, mixed $value): void {
		$this->assertNan((new JsValue())->number($found, $value));
	}//end testUnreadableNumbersAreNan()

	public function testADottedPathIsFollowedIntoNestedAnswers(): void {
		$answers = ['address' => ['city' => 'Zwolle', 'extra' => null], 'n' => 0];
		$js = new JsValue();

		$this->assertSame([true, 'Zwolle'], $js->readPath($answers, 'address.city'));
		$this->assertSame([true, null], $js->readPath($answers, 'address.extra'));
		$this->assertSame([true, 0], $js->readPath($answers, 'n'));
		$this->assertSame([true, ['city' => 'Zwolle', 'extra' => null]], $js->readPath($answers, 'address'));
	}//end testADottedPathIsFollowedIntoNestedAnswers()

	public function testAMissingStepMakesThePathUndefined(): void {
		$answers = ['address' => ['city' => 'Zwolle', 'extra' => null], 'n' => 0];
		$js = new JsValue();

		$this->assertSame([false, null], $js->readPath($answers, 'address.street'));
		$this->assertSame([false, null], $js->readPath($answers, 'phone.mobile'));
		$this->assertSame([false, null], $js->readPath($answers, 'n.digits'));
	}//end testAMissingStepMakesThePathUndefined()

	public function testANullStepStaysFoundAsNull(): void {
		$this->assertSame([true, null], (new JsValue())->readPath(['a' => null], 'a.b.c'));
	}//end testANullStepStaysFoundAsNull()

	public function testTruthinessFollowsJavascript(): void {
		$js = new JsValue();

		foreach ([null, false, '', 0, 0.0] as $falsy) {
			$this->assertFalse($js->isTruthy($falsy), var_export($falsy, true));
		}
		foreach ([[], ['a'], '0', ' ', 1, -1, 0.1, true] as $truthy) {
			$this->assertTrue($js->isTruthy($truthy), var_export($truthy, true));
		}
	}//end testTruthinessFollowsJavascript()
}//end class
