<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\FormStatements;
use OCA\Portaliq\Service\Intake\PortalEmailVerification;
use OCA\Portaliq\Service\Intake\PortalFamilyMembers;
use OCA\Portaliq\Service\Intake\PortalFormCalculator;
use OCA\Portaliq\Service\Intake\PortalFormDecision;
use OCA\Portaliq\Service\Intake\PortalFormFetch;
use OCA\Portaliq\Service\Intake\PortalFormValidator;
use OCA\Portaliq\Service\Intake\PortalSubmissionChecks;
use OCA\Portaliq\Service\Intake\SubmissionDecisions;
use OCA\Portaliq\Tests\Unit\Service\Intake\Fakes\ServiceFetchEventFake;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pre-submission checks: validation, family refs, e-mail proofs, decisions, statements.
 */
#[CoversClass(PortalSubmissionChecks::class)]
#[UsesClass(SubmissionDecisions::class)]
#[UsesClass(PortalFormFetch::class)]
class PortalSubmissionChecksTest extends TestCase {
	/**
	 * A validator that accepts what it is given.
	 *
	 * @param bool $valid Whether validation passes.
	 *
	 * @return PortalFormValidator
	 */
	private function validator(bool $valid=true): PortalFormValidator {
		$validator = $this->createMock(PortalFormValidator::class);
		$validator->method('validate')->willReturnCallback(
			static fn (array $fields, array $answers): array => ['valid' => $valid, 'errors' => ['a' => 'bad'], 'answers' => $answers]
		);

		return $validator;
	}//end validator()

	/**
	 * Invalid answers are a 400 with the field errors.
	 *
	 * @return void
	 */
	public function testInvalidAnswers(): void {
		$result = (new PortalSubmissionChecks($this->validator(false)))->prepare([], [], '/r', ['a' => 1], null, [], []);

		$this->assertInstanceOf(JSONResponse::class, $result);
		$this->assertSame(400, $result->getStatus());
		$this->assertSame(['errors' => ['a' => 'bad']], $result->getData());
	}//end testInvalidAnswers()

	/**
	 * Without optional collaborators a plain form passes through unchanged.
	 *
	 * @return void
	 */
	public function testPlainFormPasses(): void {
		$result = (new PortalSubmissionChecks($this->validator()))->prepare(['fields' => []], [], '/r', ['a' => 1], null, [], []);

		$this->assertSame(['answers' => ['a' => 1], 'computed' => [], 'decisions' => [], 'statements' => [], 'verified' => []], $result);
	}//end testPlainFormPasses()

	/**
	 * Forged family refs are refused; real ones pass; no family service trusts nothing.
	 *
	 * @return void
	 */
	public function testFamilyRefs(): void {
		$render = ['fields' => [['name' => 'kids', 'type' => 'familyMembers'], ['name' => 'x', 'type' => 'string']]];
		$family = $this->createMock(PortalFamilyMembers::class);
		$family->method('forged')->willReturnCallback(static fn (string $s, array $refs): array => array_diff($refs, ['good']));

		$forged = (new PortalSubmissionChecks($this->validator(), $family))->prepare($render, [], '/r', ['kids' => ['good', 'evil']], ['subjectRef' => 's'], [], []);
		$this->assertInstanceOf(JSONResponse::class, $forged);
		$this->assertArrayHasKey('kids', $forged->getData()['errors']);

		$ok = (new PortalSubmissionChecks($this->validator(), $family))->prepare($render, [], '/r', ['kids' => ['good']], ['subjectRef' => 's'], [], []);
		$this->assertIsArray($ok);

		$noService = (new PortalSubmissionChecks($this->validator()))->prepare($render, [], '/r', ['kids' => ['good']], null, [], []);
		$this->assertInstanceOf(JSONResponse::class, $noService);
	}//end testFamilyRefs()

	/**
	 * Unverified e-mail answers are a 400; the verified record is returned.
	 *
	 * @return void
	 */
	public function testEmailVerification(): void {
		$verification = $this->createMock(PortalEmailVerification::class);
		$verification->method('unverified')->willReturnOnConsecutiveCalls(['mail' => 'verify it'], []);
		$verification->method('record')->willReturn(['mail' => 'at']);
		$checks = new PortalSubmissionChecks($this->validator(), null, null, null, null, $verification);

		$blocked = $checks->prepare([], ['slug' => 'z'], '/r', [], null, [], []);
		$this->assertSame(400, $blocked->getStatus());

		$passed = $checks->prepare([], ['slug' => 'z'], '/r', [], null, [], []);
		$this->assertSame(['mail' => 'at'], $passed['verified']);
	}//end testEmailVerification()

	/**
	 * Step decisions write their outcome into the answers and re-run the calculator.
	 *
	 * @return void
	 */
	public function testDecisionsAreWorkedOut(): void {
		$render = ['steps' => [['id' => 's1', 'decision' => ['rule' => 'r']], ['id' => 's2']]];
		$decision = $this->createMock(PortalFormDecision::class);
		$decision->method('decide')->willReturn(['status' => PortalFormDecision::DECIDED, 'outcome' => 'yes', 'output' => 'eligible', 'nextStep' => []]);
		$calc = $this->createMock(PortalFormCalculator::class);
		$calc->method('apply')->willReturnCallback(static fn (array $f, array $a): array => ['answers' => $a + ['calc' => 1], 'computed' => ['calc']]);
		$checks = new PortalSubmissionChecks($this->validator(), null, null, $calc, $decision);

		$result = $checks->prepare($render, [], '/r', ['a' => 1], null, [], []);

		$this->assertSame(['s1' => 'yes'], $result['decisions']);
		$this->assertSame('yes', $result['answers']['eligible']);
		$this->assertSame(1, $result['answers']['calc']);
		$this->assertSame(['calc', 'eligible'], $result['computed']);
	}//end testDecisionsAreWorkedOut()

	/**
	 * A missing or unavailable decision engine is a 503.
	 *
	 * @return void
	 */
	public function testDecisionUnavailable(): void {
		$render = ['steps' => [['id' => 's1', 'decision' => ['rule' => 'r']]]];

		$missing = (new PortalSubmissionChecks($this->validator()))->prepare($render, [], '/r', [], null, [], []);
		$this->assertSame(503, $missing->getStatus());

		$decision = $this->createMock(PortalFormDecision::class);
		$decision->method('decide')->willReturn(['status' => PortalFormDecision::UNAVAILABLE]);
		$down = (new PortalSubmissionChecks($this->validator(), null, null, null, $decision))->prepare($render, [], '/r', [], null, [], []);
		$this->assertSame(503, $down->getStatus());
		$this->assertSame(['error' => 'decision_unavailable'], $down->getData());
	}//end testDecisionUnavailable()

	/**
	 * decideStep: unknown step 404, unavailable 503, otherwise the outcome.
	 *
	 * @return void
	 */
	public function testDecideStep(): void {
		$render = ['fields' => [['name' => 'age']], 'steps' => [['id' => 's1', 'decision' => ['rule' => 'r']], ['id' => 's2']]];
		$decision = $this->createMock(PortalFormDecision::class);
		$decision->method('decide')->willReturnOnConsecutiveCalls(
			['status' => PortalFormDecision::UNAVAILABLE],
			['status' => PortalFormDecision::DECIDED, 'outcome' => 'yes', 'output' => 'o', 'nextStep' => ['yes' => 's2']]
		);
		$calc = $this->createMock(PortalFormCalculator::class);
		$calc->method('apply')->willReturnCallback(static fn (array $f, array $a): array => ['answers' => $a, 'computed' => []]);
		$checks = new PortalSubmissionChecks($this->validator(), null, null, $calc, $decision);

		$this->assertSame(404, $checks->decideStep($render, 's2', [])->getStatus());
		$this->assertSame(404, $checks->decideStep($render, 'nope', [])->getStatus());
		$this->assertSame(404, (new PortalSubmissionChecks($this->validator()))->decideStep($render, 's1', [])->getStatus());
		$this->assertSame(503, $checks->decideStep($render, 's1', ['age' => 3])->getStatus());
		$this->assertSame(['outcome' => 'yes', 'output' => 'o', 'nextStep' => ['yes' => 's2']], $checks->decideStep($render, 's1', ['age' => 3, 'junk' => 1])->getData());
	}//end testDecideStep()

	/**
	 * Declared statements: unavailable service 503, errors keyed, record kept.
	 *
	 * @return void
	 */
	public function testStatements(): void {
		$render = ['settings' => ['statementsDeclared' => ['truth' => true, 'other' => true]]];

		$noService = (new PortalSubmissionChecks($this->validator()))->prepare($render, [], '/r', [], null, [], []);
		$this->assertSame(503, $noService->getStatus());
		$this->assertSame([['key' => 'truth', 'required' => true, 'text' => '', 'version' => '']], (new PortalSubmissionChecks($this->validator()))->askedStatements($render, []));
		$this->assertSame([], (new PortalSubmissionChecks($this->validator()))->askedStatements([], []));

		$statements = $this->createMock(FormStatements::class);
		$statements->method('asked')->willReturn([['key' => 'truth']]);
		$statements->method('check')->willReturnOnConsecutiveCalls(['errors' => ['truth' => 'Tick it'], 'record' => []], ['errors' => [], 'record' => [['key' => 'truth']]]);
		$checks = new PortalSubmissionChecks($this->validator(), null, $statements);

		$refused = $checks->prepare($render, [], '/r', [], null, [], []);
		$this->assertSame(['errors' => ['statement-truth' => 'Tick it']], $refused->getData());

		$accepted = $checks->prepare($render, [], '/r', [], null, ['truth'], []);
		$this->assertSame([['key' => 'truth']], $accepted['statements']);
	}//end testStatements()

	/**
	 * The browser sees `decides`, never the decision itself.
	 *
	 * @return void
	 */
	public function testStepsForTheBrowser(): void {
		$out = (new PortalSubmissionChecks($this->validator()))->stepsForTheBrowser([['id' => 'a', 'decision' => ['rule' => 'x']], ['id' => 'b'], 'junk']);

		$this->assertSame([['id' => 'a', 'decides' => true], ['id' => 'b'], 'junk'], $out);
	}//end testStepsForTheBrowser()
	/**
	 * A step without an outcome records nothing and the calculator runs once;
	 * a decision without a calculator computes only its output.
	 *
	 * @return void
	 */
	public function testNoOutcomeAndNoCalculator(): void {
		$render = ['steps' => [['id' => 's1', 'decision' => ['rule' => 'r']], 'junk', ['id' => 's2', 'decision' => ['rule' => 'q']]]];
		$decision = $this->createMock(PortalFormDecision::class);
		$decision->method('decide')->willReturnOnConsecutiveCalls(
			['status' => PortalFormDecision::NO_OUTCOME],
			['status' => PortalFormDecision::DECIDED, 'outcome' => 'no', 'output' => 'eligible', 'nextStep' => []]
		);
		$calc = $this->createMock(PortalFormCalculator::class);
		$calc->expects($this->once())->method('apply')->willReturnCallback(static fn (array $f, array $a): array => ['answers' => $a + ['calc' => 1], 'computed' => ['calc', 'calc']]);
		$noOutcome = $this->createMock(PortalFormDecision::class);
		$noOutcome->method('decide')->willReturn(['status' => PortalFormDecision::NO_OUTCOME]);

		$quiet = (new PortalSubmissionChecks($this->validator(), null, null, $calc, $noOutcome))->prepare($render, [], '/r', ['a' => 1], null, [], []);
		$this->assertSame(['answers' => ['a' => 1, 'calc' => 1], 'computed' => ['calc'], 'decisions' => [], 'statements' => [], 'verified' => []], $quiet);

		$bare = (new PortalSubmissionChecks($this->validator(), null, null, null, $decision))->prepare($render, [], '/r', ['a' => 1], null, [], []);
		$this->assertSame(['answers' => ['a' => 1, 'eligible' => 'no'], 'computed' => ['eligible'], 'decisions' => ['s2' => 'no'], 'statements' => [], 'verified' => []], $bare);
	}//end testNoOutcomeAndNoCalculator()

	/**
	 * Family fields without a name or an answer are skipped; a session without
	 * a subject trusts no reference even with the family service.
	 *
	 * @return void
	 */
	public function testFamilyFieldsSkippedAndNoSubject(): void {
		$render = ['fields' => [['type' => 'familyMembers'], ['name' => 'empty', 'type' => 'familyMembers'], ['name' => 'kids', 'type' => 'familyMembers']]];
		$family = $this->createMock(PortalFamilyMembers::class);
		$family->expects($this->never())->method('forged');

		$refused = (new PortalSubmissionChecks($this->validator(), $family))->prepare($render, [], '/r', ['empty' => [], 'kids' => ['good']], null, [], []);
		$this->assertSame(['errors' => ['kids' => 'Choose the people from the list we found.']], $refused->getData());

		$skipped = (new PortalSubmissionChecks($this->validator(), $family))->prepare($render, [], '/r', ['empty' => []], null, [], []);
		$this->assertIsArray($skipped);
	}//end testFamilyFieldsSkippedAndNoSubject()

	/**
	 * decideStep takes the last matching step and hands the engine only the
	 * form's own fields when no calculator is there.
	 *
	 * @return void
	 */
	public function testDecideStepLastMatchWithoutCalculator(): void {
		$render = ['fields' => [['name' => 'age']], 'steps' => [['id' => 's1', 'decision' => ['rule' => 'first']], ['id' => 's1', 'decision' => ['rule' => 'last']]]];
		$decision = $this->createMock(PortalFormDecision::class);
		$decision->expects($this->once())->method('decide')->with(['rule' => 'last'], ['age' => 3])
			->willReturn(['status' => PortalFormDecision::DECIDED, 'outcome' => 'yes', 'output' => 'o', 'nextStep' => []]);

		$response = (new PortalSubmissionChecks($this->validator(), null, null, null, $decision))->decideStep($render, 's1', ['age' => 3, 'junk' => 1]);
		$this->assertSame(['outcome' => 'yes', 'output' => 'o', 'nextStep' => []], $response->getData());
	}//end testDecideStepLastMatchWithoutCalculator()
	/**
	 * A form whose second step asks the Kadaster who owns the building.
	 *
	 * @return array<string, mixed>
	 */
	private function fetchForm(): array {
		return [
			'fields' => [['name' => 'postcode'], ['name' => 'eigenaar'], ['name' => 'naam']],
			'steps' => [
				['id' => 'adres', 'fields' => ['postcode']],
				[
					'id' => 'pand',
					'fields' => ['eigenaar', 'naam'],
					'fetch' => [[
						'id' => 'eigenaar',
						'source' => 'kadaster-eigendom',
						'inputs' => ['postcode' => 'postcode'],
						'outputs' => ['eigenaarNaam' => 'eigenaar'],
						'check' => ['label' => 'Wij controleren of u eigenaar bent', 'output' => 'isEigenaar', 'lines' => ['true' => 'Volgens het Kadaster staat dit pand op uw naam.']],
					]],
				],
			],
		];
	}//end fetchForm()

	/**
	 * Integriq's fetch, answered by $answer, or left unhandled when null.
	 *
	 * @param callable|null $answer     Answers the dispatched command.
	 * @param array         $dispatched Receives every dispatched command.
	 *
	 * @return PortalFormFetch
	 */
	private function fetches(?callable $answer, array &$dispatched = []): PortalFormFetch {
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use ($answer, &$dispatched): void {
				$dispatched[] = $event;
				if ($answer !== null) {
					$answer($event);
				}
			}
		);

		return new PortalFormFetch($events, $this->createMock(LoggerInterface::class), ServiceFetchEventFake::class);
	}//end fetches()

	/**
	 * REQ-DIF-005: the step's fetch answers the fields it fills and its check
	 * line, with only the form's own answers sent; an unknown fetch is a 404
	 * and a source that is down a 503.
	 *
	 * @return void
	 */
	public function testFetchStepAnswersTheFieldsAndTheCheckLine(): void {
		$dispatched = [];
		$fetches    = $this->fetches(
			static fn (ServiceFetchEventFake $e) => $e->setOutputs(['eigenaarNaam' => 'S. de Vries', 'isEigenaar' => true], '2026-11-17T12:00:00Z'),
			$dispatched
		);
		$checks     = new PortalSubmissionChecks($this->validator(), null, null, null, null, null, $fetches);

		$response = $checks->fetchStep($this->fetchForm(), 'pand', 'eigenaar', ['postcode' => '3311AB', 'junk' => 'x']);
		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['fetch' => 'eigenaar', 'values' => ['eigenaar' => 'S. de Vries'], 'check' => 'Volgens het Kadaster staat dit pand op uw naam.', 'fetchedAt' => '2026-11-17T12:00:00Z'],
			$response->getData()
		);
		$this->assertSame(['postcode' => '3311AB'], $dispatched[0]->inputs);

		$this->assertSame(404, $checks->fetchStep($this->fetchForm(), 'adres', 'eigenaar', [])->getStatus());
		$this->assertSame(404, (new PortalSubmissionChecks($this->validator()))->fetchStep($this->fetchForm(), 'pand', 'eigenaar', [])->getStatus(), 'no fetch service, nothing to run');

		$down = new PortalSubmissionChecks($this->validator(), null, null, null, null, null, $this->fetches(static fn (ServiceFetchEventFake $e) => $e->refuse('timeout')));
		$failed = $down->fetchStep($this->fetchForm(), 'pand', 'eigenaar', []);
		$this->assertSame(503, $failed->getStatus());
		$this->assertSame(['error' => 'fetch_unavailable'], $failed->getData());
	}//end testFetchStepAnswersTheFieldsAndTheCheckLine()

	/**
	 * REQ-DIF-005: on submit the field a fetch fills holds what the source
	 * says, not what the browser sent; a source that is down refuses the
	 * submission and a form without fetches asks nothing.
	 *
	 * @return void
	 */
	public function testPrepareFetchesAgainAndRefusesWhenTheSourceIsDown(): void {
		$fetches = $this->fetches(static fn (ServiceFetchEventFake $e) => $e->setOutputs(['eigenaarNaam' => 'S. de Vries', 'isEigenaar' => true], 'now'));
		$checks  = new PortalSubmissionChecks($this->validator(), null, null, null, null, null, $fetches);

		$prepared = $checks->prepare($this->fetchForm(), [], '/r', ['postcode' => '3311AB', 'eigenaar' => 'Iemand anders', 'naam' => 'Sanne'], null, [], []);
		$this->assertIsArray($prepared);
		$this->assertSame('S. de Vries', $prepared['answers']['eigenaar']);
		$this->assertSame('Sanne', $prepared['answers']['naam']);

		$down    = new PortalSubmissionChecks($this->validator(), null, null, null, null, null, $this->fetches(null));
		$refused = $down->prepare($this->fetchForm(), [], '/r', ['eigenaar' => 'x'], null, [], []);
		$this->assertInstanceOf(JSONResponse::class, $refused);
		$this->assertSame(503, $refused->getStatus());

		$noService = (new PortalSubmissionChecks($this->validator()))->prepare($this->fetchForm(), [], '/r', ['eigenaar' => 'x'], null, [], []);
		$this->assertInstanceOf(JSONResponse::class, $noService, 'a form that fetches is not sent without the fetch service');

		$events = $this->createMock(IEventDispatcher::class);
		$events->expects($this->never())->method('dispatchTyped');
		$plain = new PortalSubmissionChecks($this->validator(), null, null, null, null, null, new PortalFormFetch($events, $this->createMock(LoggerInterface::class), ServiceFetchEventFake::class));
		$this->assertIsArray($plain->prepare(['fields' => [['name' => 'naam']], 'steps' => [['id' => 'a', 'fields' => ['naam']]]], [], '/r', ['naam' => 'n'], null, [], []));
	}//end testPrepareFetchesAgainAndRefusesWhenTheSourceIsDown()

	/**
	 * REQ-DIF-005: the browser sees a fetch's id, the fields it fills and its
	 * check label; the source and its inputs stay on the server.
	 *
	 * @return void
	 */
	public function testTheBrowserSeesNoSourceAndNoInputs(): void {
		$steps = (new PortalSubmissionChecks($this->validator()))->stepsForTheBrowser($this->fetchForm()['steps']);

		$this->assertSame([['id' => 'eigenaar', 'fields' => ['eigenaar'], 'checkLabel' => 'Wij controleren of u eigenaar bent']], $steps[1]['fetch']);
		$this->assertStringNotContainsString('kadaster-eigendom', json_encode($steps));
		$this->assertArrayNotHasKey('fetch', $steps[0]);
	}//end testTheBrowserSeesNoSourceAndNoInputs()
}//end class
