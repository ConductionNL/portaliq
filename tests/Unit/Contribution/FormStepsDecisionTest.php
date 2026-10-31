<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\FormStepsNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * form-flow-repeating-groups-calculations-and-decisions REQ-FFL-003: a step
 * keeps a decision only when it is whole, and its outcomes only open steps
 * the form has.
 *
 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t05
 */
class FormStepsDecisionTest extends TestCase {

	private const KNOWN = ['woonplaats', 'kenteken', 'soortVergunning', 'naam'];

	private function decision(array $over=[]): array {
		return $over + [
			'rule' => 'parkeren-soort-vergunning',
			'inputs' => ['woonplaats' => 'woonplaats', 'auto' => 'kenteken'],
			'output' => 'soortVergunning',
			'nextStep' => ['bewoner' => 'stap-bewoner', 'bedrijf' => 'stap-onbekend'],
		];
	}

	private function steps(array $decision): array {
		return (new FormStepsNormaliser())->steps(
			steps: [
				['id' => 'route', 'title' => 'Route', 'fields' => ['woonplaats', 'kenteken'], 'decision' => $decision],
				['id' => 'stap-bewoner', 'title' => 'Bewoner', 'fields' => ['soortVergunning', 'naam']],
			],
			known: self::KNOWN
		);
	}

	public function testAWholeDecisionIsKeptAndOnlyKnownTargetsRemain(): void {
		$steps = $this->steps($this->decision());

		$this->assertSame('parkeren-soort-vergunning', $steps[0]['decision']['rule']);
		$this->assertSame(['bewoner' => 'stap-bewoner'], $steps[0]['decision']['nextStep'], 'a step the form lacks is not an outcome');
		$this->assertArrayNotHasKey('decision', $steps[1]);

	}//end testAWholeDecisionIsKeptAndOnlyKnownTargetsRemain()

	public function testAHalfDeclaredDecisionIsDropped(): void {
		foreach ([
			['rule' => 'has space'],
			['rule' => 7],
			['output' => 'nietBestaand'],
			['inputs' => ['a' => 'b c']],
			['inputs' => [0 => 'woonplaats']],
		] as $bad) {
			$steps = $this->steps($this->decision($bad));
			$this->assertArrayNotHasKey('decision', $steps[0], json_encode($bad));
			$this->assertSame('route', $steps[0]['id'], 'the step itself stays');
		}

	}//end testAHalfDeclaredDecisionIsDropped()
}//end class
