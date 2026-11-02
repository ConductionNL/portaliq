<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\PortalFormDecision;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A stand-in for buildiq's rule engine: answers what the test sets and records what it was asked.
 */
class FakeRuleEngine {

	/**
	 * The answer, or an exception to throw.
	 *
	 * @var mixed
	 */
	public mixed $answer = ['outcome' => 'bedrijf'];

	/**
	 * Every call: rule and inputs.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public array $calls = [];

	/**
	 * The engine's evaluation.
	 *
	 * @param string $rule The rule.
	 * @param array<string, mixed> $inputs The inputs.
	 *
	 * @return mixed
	 */
	public function evaluate(string $rule, array $inputs): mixed {
		$this->calls[] = [$rule, $inputs];
		if ($this->answer instanceof RuntimeException) {
			throw $this->answer;
		}

		return $this->answer;
	}
}

/**
 * form-flow-repeating-groups-calculations-and-decisions REQ-FFL-003: the rule
 * engine is asked on the server with the declared inputs, and being down is a
 * state of its own.
 *
 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t05
 */
class PortalFormDecisionTest extends TestCase {

	private const DECISION = [
		'rule' => 'parkeren-soort-vergunning',
		'inputs' => ['woonplaats' => 'adres.plaats', 'auto' => 'kenteken'],
		'output' => 'soortVergunning',
		'nextStep' => ['bewoner' => 'stap-bewoner', 'bedrijf' => 'stap-bedrijf'],
	];

	private FakeRuleEngine $engine;

	private function service(string $class=FakeRuleEngine::class): PortalFormDecision {
		$this->engine = new FakeRuleEngine();
		$container    = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->engine);

		return new PortalFormDecision($container, $this->createMock(LoggerInterface::class), $class);
	}

	public function testTheEngineIsAskedWithTheDeclaredInputsOnly(): void {
		$service = $this->service();

		$result = $service->decide(decision: self::DECISION, answers: ['adres' => ['plaats' => 'Zuiddrecht', 'straat' => 'x'], 'kenteken' => 'AB-12-CD', 'bsn' => '111222333']);

		$this->assertSame([['parkeren-soort-vergunning', ['woonplaats' => 'Zuiddrecht', 'auto' => 'AB-12-CD']]], $this->engine->calls);
		$this->assertSame(['status' => 'decided', 'outcome' => 'bedrijf', 'output' => 'soortVergunning', 'nextStep' => 'stap-bedrijf'], $result);

	}//end testTheEngineIsAskedWithTheDeclaredInputsOnly()

	public function testAnOutcomeThatOpensNoStepLeavesTheFlowAlone(): void {
		$service = $this->service();
		$this->engine->answer = ['outcome' => 'anders'];

		$result = $service->decide(decision: self::DECISION, answers: []);

		$this->assertSame('decided', $result['status']);
		$this->assertSame('', $result['nextStep']);

	}//end testAnOutcomeThatOpensNoStepLeavesTheFlowAlone()

	public function testAnEngineWithoutAnOutcomeDecidesNothing(): void {
		$service = $this->service();
		foreach ([[], ['outcome' => ''], ['outcome' => ['x']], 'text', null] as $answer) {
			$this->engine->answer = $answer;
			$this->assertSame('no_outcome', $service->decide(decision: self::DECISION, answers: [])['status'], json_encode($answer));
		}

	}//end testAnEngineWithoutAnOutcomeDecidesNothing()

	public function testAnEngineThatIsDownIsUnavailable(): void {
		$service = $this->service();
		$this->engine->answer = new RuntimeException('down');
		$this->assertSame('unavailable', $service->decide(decision: self::DECISION, answers: [])['status']);

		$missing = $this->service('OCA\\Buildiq\\Service\\DoesNotExist');
		$this->assertSame('unavailable', $missing->decide(decision: self::DECISION, answers: [])['status']);
		$this->assertSame([], $this->engine->calls);

	}//end testAnEngineThatIsDownIsUnavailable()
}//end class
