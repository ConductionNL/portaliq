<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\FormStepDecision;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Normalising a form step's decision block.
 */
#[CoversClass(FormStepDecision::class)]
class FormStepDecisionTest extends TestCase {
	/**
	 * A sound decision is rebuilt with only its valid next steps.
	 *
	 * @return void
	 */
	public function testSoundDecision(): void {
		$out = (new FormStepDecision())->normalise(
			['rule' => 'eligible', 'inputs' => ['age' => 'applicant.age'], 'output' => 'ok', 'nextStep' => ['yes' => 'step2', 'no' => '9bad', 'x' => 5], 'extra' => 1],
			['ok', 'other']
		);

		$this->assertSame(['rule' => 'eligible', 'inputs' => ['age' => 'applicant.age'], 'output' => 'ok', 'nextStep' => ['yes' => 'step2']], $out);
	}//end testSoundDecision()

	/**
	 * Anything malformed is rejected.
	 *
	 * @return void
	 */
	public function testRejections(): void {
		$d     = new FormStepDecision();
		$known = ['ok'];

		$this->assertNull($d->normalise('x', $known));
		$this->assertNull($d->normalise(['rule' => '1bad', 'output' => 'ok'], $known));
		$this->assertNull($d->normalise(['rule' => 'r', 'output' => 'nope'], $known));
		$this->assertNull($d->normalise(['rule' => 'r', 'output' => ['ok']], $known));
		$this->assertNull($d->normalise(['rule' => 'r', 'output' => 'ok', 'inputs' => ['1x' => 'a']], $known));
		$this->assertNull($d->normalise(['rule' => 'r', 'output' => 'ok', 'inputs' => ['a' => 'b c']], $known));
		$this->assertSame([], $d->normalise(['rule' => 'r', 'output' => 'ok'], $known)['inputs']);
	}//end testRejections()
}//end class
