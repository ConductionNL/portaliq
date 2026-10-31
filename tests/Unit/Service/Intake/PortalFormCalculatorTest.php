<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\PortalFormCalculator;
use PHPUnit\Framework\TestCase;

/**
 * form-flow-repeating-groups-calculations-and-decisions REQ-FFL-002: the six
 * operations, and a value the browser sent never survives.
 *
 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t03
 */
class PortalFormCalculatorTest extends TestCase {

	/**
	 * One calculation over some answers.
	 *
	 * @param string $op The operation.
	 * @param array<int, mixed> $args The arguments.
	 * @param array<string, mixed> $answers The answers.
	 *
	 * @return int|float|string|null
	 */
	private function calc(string $op, array $args, array $answers): int|float|string|null {
		return (new PortalFormCalculator())->evaluate(calculate: ['op' => $op, 'args' => $args], answers: $answers);
	}

	public function testSumMultiplyAndSubtract(): void {
		$answers = ['a' => '2', 'b' => 3.5, 'c' => 4];

		$this->assertEquals(9.5, $this->calc('sum', ['a', 'b', 'c'], $answers));
		$this->assertEquals(28, $this->calc('multiply', ['a', 'c', 'b'], $answers));
		$this->assertEquals(-5.5, $this->calc('subtract', ['a', 'b', 'c'], $answers));
		$this->assertEquals(7, $this->calc('subtract', ['c', 3, -6], $answers));
		$this->assertEquals(3, $this->calc('sum', ['a', 1], $answers), 'a number is an argument too');

	}//end testSumMultiplyAndSubtract()

	public function testSumOverAGroupSubfield(): void {
		$answers = ['bewoners' => [['aantal' => '2'], ['aantal' => 3]]];

		$this->assertEquals(5, $this->calc('sum', ['bewoners[].aantal'], $answers));
		$this->assertEquals(0, $this->calc('sum', ['bewoners[].aantal'], ['bewoners' => []]), 'an empty group sums to nothing');

	}//end testSumOverAGroupSubfield()

	public function testAddDaysAndDiffDays(): void {
		$this->assertSame('2027-11-01', $this->calc('addDays', ['startdatum', 365], ['startdatum' => '2026-11-01']));
		$this->assertSame('2026-10-25', $this->calc('addDays', ['startdatum', -7], ['startdatum' => '2026-11-01']));
		$this->assertSame(30, $this->calc('diffDays', ['van', 'tot'], ['van' => '2026-11-01', 'tot' => '2026-12-01']));
		$this->assertSame(-30, $this->calc('diffDays', ['tot', 'van'], ['van' => '2026-11-01', 'tot' => '2026-12-01']));

	}//end testAddDaysAndDiffDays()

	public function testCountItemsInAGroup(): void {
		$this->assertSame(3, $this->calc('count', ['bewoners'], ['bewoners' => [['a' => 1], ['a' => 2], ['a' => 3]]]));
		$this->assertNull($this->calc('count', ['naam'], ['naam' => 'Ans']), 'a plain answer has no items');

	}//end testCountItemsInAGroup()

	public function testWhatCannotBeWorkedOutIsNull(): void {
		$this->assertNull($this->calc('sum', ['a', 'missing'], ['a' => 1]), 'an argument with no answer');
		$this->assertNull($this->calc('sum', ['a'], ['a' => 'abc']), 'not a number');
		$this->assertNull($this->calc('addDays', ['d', 1], ['d' => '2026-02-30']), 'not a real date');
		$this->assertNull($this->calc('addDays', ['d', 'x'], ['d' => '2026-02-01']));
		$this->assertNull($this->calc('power', ['a', 2], ['a' => 2]), 'an unknown operation');
		$this->assertNull($this->calc('sum', [], []));

	}//end testWhatCannotBeWorkedOutIsNull()

	public function testATamperedValueIsReplacedByTheServersOwnResult(): void {
		$fields = [
			['name' => 'startdatum', 'type' => 'date'],
			['name' => 'einddatum', 'type' => 'date', 'calculate' => ['op' => 'addDays', 'args' => ['startdatum', 365]]],
		];

		$result = (new PortalFormCalculator())->apply(fields: $fields, answers: ['startdatum' => '2026-11-01', 'einddatum' => '2030-01-01']);

		$this->assertSame('2027-11-01', $result['answers']['einddatum']);
		$this->assertSame(['einddatum'], $result['computed']);

		$none = (new PortalFormCalculator())->apply(fields: $fields, answers: ['einddatum' => '2030-01-01']);
		$this->assertArrayNotHasKey('einddatum', $none['answers'], 'a value that cannot be worked out is not taken from the browser');
		$this->assertSame([], $none['computed']);

	}//end testATamperedValueIsReplacedByTheServersOwnResult()

	public function testALaterFieldReadsAnEarlierCalculatedOne(): void {
		$fields = [
			['name' => 'total', 'calculate' => ['op' => 'sum', 'args' => ['a', 'b']]],
			['name' => 'double', 'calculate' => ['op' => 'multiply', 'args' => ['total', 2]]],
		];

		$this->assertEquals(10, (new PortalFormCalculator())->apply(fields: $fields, answers: ['a' => 2, 'b' => 3])['answers']['double']);

	}//end testALaterFieldReadsAnEarlierCalculatedOne()

	public function testAFormWithAnUnknownOperationIsNotKnown(): void {
		$calculator = new PortalFormCalculator();
		$good       = [['name' => 'x', 'calculate' => ['op' => 'sum', 'args' => ['a']]]];

		$this->assertTrue($calculator->knowsEveryOperation(fields: $good));
		$this->assertSame(['power'], $calculator->unknownOperations(fields: [['name' => 'x', 'calculate' => ['op' => 'power', 'args' => ['a']]]]));
		$this->assertFalse($calculator->knowsEveryOperation(fields: [['name' => 'x', 'calculate' => 'sum']]), 'a malformed declaration');
		$this->assertFalse($calculator->knowsEveryOperation(fields: [['name' => 'g', 'type' => 'group', 'fields' => [['name' => 'y', 'calculate' => ['op' => 'nope', 'args' => ['a']]]]]]), 'inside a group too');

	}//end testAFormWithAnUnknownOperationIsNotKnown()

	/**
	 * The site shows the same value the server stores: both run the fixtures in
	 * tests/fixtures/form-calculations.json (tests/form-calculations.spec.mjs is the other half).
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t04
	 */
	public function testTheServerAgreesWithTheSharedFixtures(): void {
		$cases = json_decode((string)file_get_contents(__DIR__.'/../../../fixtures/form-calculations.json'), true);
		$this->assertNotEmpty($cases);
		foreach ($cases as $case) {
			$got = (new PortalFormCalculator())->evaluate(calculate: $case['calculate'], answers: $case['answers']);
			if ($case['expected'] === null) {
				$this->assertNull($got, $case['name']);
				continue;
			}

			$this->assertEquals($case['expected'], $got, $case['name']);
		}

	}//end testTheServerAgreesWithTheSharedFixtures()
}//end class
