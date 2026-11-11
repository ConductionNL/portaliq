<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\FormStatements;
use OCA\Portaliq\Service\Intake\PortalEmailVerification;
use OCA\Portaliq\Service\Intake\PortalFamilyMembers;
use OCA\Portaliq\Service\Intake\PortalFormCalculator;
use OCA\Portaliq\Service\Intake\PortalFormDecision;
use OCA\Portaliq\Service\Intake\PortalFormValidator;
use OCA\Portaliq\Service\Intake\PortalSubmissionChecks;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pre-submission checks: validation, family refs, e-mail proofs, decisions, statements.
 */
#[CoversClass(PortalSubmissionChecks::class)]
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
}//end class
