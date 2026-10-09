<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\PortalFormOperands;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Operand resolution for the form calculator's functions.
 */
#[CoversClass(PortalFormOperands::class)]
class PortalFormOperandsTest extends TestCase {
	/**
	 * Operands resolve to literals, answers or grouped values.
	 *
	 * @return void
	 */
	public function testValues(): void {
		$o       = new PortalFormOperands();
		$answers = ['age' => 5, 'blank' => '', 'list' => [1], 'kids' => [['dob' => 'a'], ['dob' => 'b'], ['x' => 1], 'junk']];

		$this->assertSame([3], $o->values(3, $answers));
		$this->assertSame([1.5], $o->values(1.5, $answers));
		$this->assertSame([7], $o->values('7', $answers));
		$this->assertSame([5], $o->values('age', $answers));
		$this->assertSame([], $o->values('blank', $answers));
		$this->assertSame([], $o->values('list', $answers));
		$this->assertSame([], $o->values('missing', $answers));
		$this->assertSame([], $o->values(['x'], $answers));
		$this->assertSame(['a', 'b'], $o->values('kids[].dob', $answers));
		$this->assertSame([], $o->values('nokids[].dob', $answers));
	}//end testValues()

	/**
	 * Count counts lists and refuses scalars.
	 *
	 * @return void
	 */
	public function testCount(): void {
		$o = new PortalFormOperands();

		$this->assertSame(2, $o->count('kids', ['kids' => [1, 2]]));
		$this->assertSame(0, $o->count('kids', []));
		$this->assertNull($o->count('kids', ['kids' => 'x']));
	}//end testCount()

	/**
	 * Dates must be real Y-m-d days.
	 *
	 * @return void
	 */
	public function testDate(): void {
		$o = new PortalFormOperands();

		$this->assertSame('2026-02-03', $o->date('d', ['d' => '2026-02-03'])->format('Y-m-d'));
		$this->assertNull($o->date('d', ['d' => '2026-02-31']));
		$this->assertNull($o->date('d', ['d' => 'soon']));
		$this->assertNull($o->date(5, []));
		$this->assertNull($o->date('d', []));
	}//end testDate()

	/**
	 * Adding and differencing days.
	 *
	 * @return void
	 */
	public function testAddAndDiffDays(): void {
		$o       = new PortalFormOperands();
		$answers = ['from' => '2026-01-30', 'to' => '2026-02-10', 'n' => 3];

		$this->assertSame('2026-02-02', $o->addDays(['from', 'n'], $answers));
		$this->assertSame('2026-01-27', $o->addDays(['from', -3], $answers));
		$this->assertNull($o->addDays(['from', 'nope'], $answers));
		$this->assertNull($o->addDays(['zzz', 1], $answers));
		$this->assertNull($o->addDays(['from', 'from'], $answers));

		$this->assertSame(11, $o->diffDays(['from', 'to'], $answers));
		$this->assertSame(-11, $o->diffDays(['to', 'from'], $answers));
		$this->assertNull($o->diffDays(['from', 'zzz'], $answers));
		$this->assertNull($o->diffDays([], $answers));
	}//end testAddAndDiffDays()
}//end class
