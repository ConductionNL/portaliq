<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\PortalFormValidator;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * portal-intake-form-as-an-object REQ-PIFO-004: a submission is validated
 * against the form it was rendered from, the error is on the field, and
 * nothing a client invented survives into the answers a create would see.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */
class PortalFormValidatorTest extends TestCase {

	public function testAMissingRequiredAnswerIsAFieldError(): void {
		$validator = $this->validator();

		$result = $validator->validate(
			fields: [['name' => 'postcode', 'required' => true], ['name' => 'toelichting']],
			answers: ['toelichting' => 'Ik verhuis.']
		);

		$this->assertFalse($result['valid']);
		$this->assertArrayHasKey('postcode', $result['errors']);
		$this->assertArrayNotHasKey('toelichting', $result['errors']);

	}//end testAMissingRequiredAnswerIsAFieldError()

	public function testAnOptionalAnswerMayBeLeftOut(): void {
		$validator = $this->validator();

		$result = $validator->validate(fields: [['name' => 'toelichting']], answers: []);

		$this->assertTrue($result['valid']);
		$this->assertSame([], $result['answers']);

	}//end testAnOptionalAnswerMayBeLeftOut()

	/**
	 * A required field answered with an empty array is not answered. The
	 * array case is judged on emptiness rather than on trimmed text, because
	 * casting an array to a string is not a question worth asking.
	 *
	 * @return void
	 */
	public function testARequiredMultiAnswerIsMissingWhenTheArrayIsEmpty(): void {
		$validator = $this->validator();

		$result = $validator->validate(
			fields: [['name' => 'bijlagen', 'required' => true, 'type' => 'array']],
			answers: ['bijlagen' => []]
		);

		$this->assertFalse($result['valid']);
		$this->assertArrayHasKey('bijlagen', $result['errors']);

	}//end testARequiredMultiAnswerIsMissingWhenTheArrayIsEmpty()

	/**
	 * The same field with one entry is answered, so the emptiness test is
	 * what decides it and not the presence of the key.
	 *
	 * @return void
	 */
	public function testAMultiAnswerWithOneEntryIsAnswered(): void {
		$validator = $this->validator();

		$result = $validator->validate(
			fields: [['name' => 'bijlagen', 'required' => true, 'type' => 'array']],
			answers: ['bijlagen' => ['bewijs.pdf']]
		);

		$this->assertTrue($result['valid']);
		$this->assertArrayNotHasKey('bijlagen', $result['errors']);

	}//end testAMultiAnswerWithOneEntryIsAnswered()


	public function testAFieldTheFormDoesNotDeclareNeverReachesTheAnswers(): void {
		$validator = $this->validator();

		$result = $validator->validate(
			fields: [['name' => 'postcode', 'required' => true]],
			answers: ['postcode' => '1234 AB', 'status' => 'afgehandeld', 'subjectRef' => 'somebody-else']
		);

		$this->assertTrue($result['valid']);
		$this->assertSame(['postcode' => '1234 AB'], $result['answers']);

	}//end testAFieldTheFormDoesNotDeclareNeverReachesTheAnswers()

	public function testAPatternIsEnforced(): void {
		$validator = $this->validator();

		$bad = $validator->validate(fields: [['name' => 'postcode', 'pattern' => '^[0-9]{4} ?[A-Z]{2}$']], answers: ['postcode' => 'ergens']);
		$good = $validator->validate(fields: [['name' => 'postcode', 'pattern' => '^[0-9]{4} ?[A-Z]{2}$']], answers: ['postcode' => '1234 AB']);

		$this->assertFalse($bad['valid']);
		$this->assertTrue($good['valid']);

	}//end testAPatternIsEnforced()

	public function testATypeIsEnforced(): void {
		$validator = $this->validator();

		$this->assertFalse($this->validator()->validate(fields: [['name' => 'aantal', 'type' => 'number']], answers: ['aantal' => 'drie'])['valid']);
		$this->assertTrue($validator->validate(fields: [['name' => 'aantal', 'type' => 'number']], answers: ['aantal' => '3'])['valid']);
		$this->assertFalse($validator->validate(fields: [['name' => 'mail', 'type' => 'email']], answers: ['mail' => 'ans-at-example'])['valid']);

	}//end testATypeIsEnforced()

	public function testAnAnswerOutsideTheOfferedOptionsIsRefused(): void {
		$validator = $this->validator();
		$field = [['name' => 'reden', 'options' => ['verhuizing', 'overlijden']]];

		$this->assertFalse($validator->validate(fields: $field, answers: ['reden' => 'anders'])['valid']);
		$this->assertTrue($validator->validate(fields: $field, answers: ['reden' => 'verhuizing'])['valid']);

	}//end testAnAnswerOutsideTheOfferedOptionsIsRefused()

	public function testAnAnswerLongerThanTheFormAllowsIsRefused(): void {
		$validator = $this->validator();

		$result = $validator->validate(fields: [['name' => 'toelichting', 'maxLength' => 5]], answers: ['toelichting' => 'veel te lang']);

		$this->assertFalse($result['valid']);
		$this->assertArrayHasKey('toelichting', $result['errors']);

	}//end testAnAnswerLongerThanTheFormAllowsIsRefused()

	/**
	 * The validator with a translator that answers the text it was given.
	 *
	 * @return PortalFormValidator
	 */
	private function validator(): PortalFormValidator {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new PortalFormValidator($l10n);
	}//end validator()

}//end class
