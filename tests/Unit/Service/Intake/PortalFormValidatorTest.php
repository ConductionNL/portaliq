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
	 * The partner form of REQ-ICQ-002: "Name of your partner" is required and
	 * shows only when "Do you live together?" is "Yes".
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function partnerForm(): array {
		return [
			['name' => 'together', 'required' => true, 'options' => ['Yes', 'No']],
			['name' => 'partnerName', 'required' => true, 'visibleWhen' => ['field' => 'together', 'op' => 'eq', 'value' => 'Yes']],
			['name' => 'partnerBirthDate', 'visibleWhen' => ['field' => 'partnerName', 'op' => 'notEmpty']],
		];
	}//end partnerForm()

	/**
	 * intake-conditional-questions-and-drafts REQ-ICQ-002: a required field
	 * the resident never saw does not block their submission.
	 *
	 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-the-server-skips-a-hidden-field-req-icq-002
	 *
	 * @return void
	 */
	public function testHiddenRequiredFieldIsNotRequired(): void {
		$result = $this->validator()->validate(fields: $this->partnerForm(), answers: ['together' => 'No']);

		$this->assertTrue($result['valid']);
		$this->assertSame([], $result['errors']);
		$this->assertSame(['together' => 'No'], $result['answers']);

	}//end testHiddenRequiredFieldIsNotRequired()

	/**
	 * The same field, shown, is required as before.
	 *
	 * @return void
	 */
	public function testAShownRequiredFieldIsStillRequired(): void {
		$result = $this->validator()->validate(fields: $this->partnerForm(), answers: ['together' => 'Yes']);

		$this->assertFalse($result['valid']);
		$this->assertArrayHasKey('partnerName', $result['errors']);

	}//end testAShownRequiredFieldIsStillRequired()

	/**
	 * A hand-crafted body that answers "No" and still names a partner: the
	 * hidden answer, and the answer whose condition hangs on it, never reach
	 * the case.
	 *
	 * @spec openspec/changes/intake-conditional-questions-and-drafts/specs/portal-intake-form/spec.md#requirement-the-server-skips-a-hidden-field-req-icq-002
	 *
	 * @return void
	 */
	public function testHiddenAnswerIsDropped(): void {
		$result = $this->validator()->validate(
			fields: $this->partnerForm(),
			answers: ['together' => 'No', 'partnerName' => 'Sam', 'partnerBirthDate' => '1990-01-01']
		);

		$this->assertTrue($result['valid']);
		$this->assertSame(['together' => 'No'], $result['answers']);

	}//end testHiddenAnswerIsDropped()

	/**
	 * Shown, the chain keeps every answer.
	 *
	 * @return void
	 */
	public function testShownAnswersAreKept(): void {
		$answers = ['together' => 'Yes', 'partnerName' => 'Sam', 'partnerBirthDate' => '1990-01-01'];

		$result = $this->validator()->validate(fields: $this->partnerForm(), answers: $answers);

		$this->assertTrue($result['valid']);
		$this->assertSame($answers, $result['answers']);

	}//end testShownAnswersAreKept()

	/**
	 * A hidden field is not checked either: an answer it would refuse cannot
	 * stop a submission the resident made without seeing it.
	 *
	 * @return void
	 */
	public function testAHiddenFieldsWrongAnswerIsNotAnError(): void {
		$fields = [
			['name' => 'hasCar', 'options' => ['Yes', 'No']],
			['name' => 'plate', 'pattern' => '^[A-Z0-9-]+$', 'visibleWhen' => ['field' => 'hasCar', 'value' => 'Yes']],
		];

		$result = $this->validator()->validate(fields: $fields, answers: ['hasCar' => 'No', 'plate' => 'not a plate']);

		$this->assertTrue($result['valid']);
		$this->assertSame(['hasCar' => 'No'], $result['answers']);

	}//end testAHiddenFieldsWrongAnswerIsNotAnError()

	/**
	 * A condition on a field declared LATER reads that field's answer, as the
	 * screen does: the form holds every answer at once, not only the ones above.
	 *
	 * @return void
	 */
	public function testAConditionMayNameALaterField(): void {
		$fields = [
			['name' => 'reason', 'required' => true, 'visibleWhen' => ['field' => 'kind', 'value' => 'other']],
			['name' => 'kind', 'required' => true],
		];

		$this->assertFalse($this->validator()->validate(fields: $fields, answers: ['kind' => 'other'])['valid']);
		$this->assertTrue($this->validator()->validate(fields: $fields, answers: ['kind' => 'move'])['valid']);

	}//end testAConditionMayNameALaterField()

	/**
	 * data-lookups-and-checks-in-forms T02: a field naming a Dutch format is
	 * checked on the server and stored normalised; a wrong IBAN is refused
	 * with the sentence the resident reads.
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t02
	 */
	public function testAFieldWithAFormatIsCheckedAndStoredNormalised(): void {
		$fields = [
			['name' => 'iban', 'type' => 'string', 'format' => 'iban'],
			['name' => 'kenteken', 'type' => 'string', 'format' => 'nl-licence-plate'],
			['name' => 'bsn', 'type' => 'string', 'format' => 'bsn'],
		];

		$good = $this->validator()->validate($fields, ['iban' => 'nl91 abna 0417 1643 00', 'kenteken' => 'ab-12-cd', 'bsn' => '111222333']);
		$this->assertTrue($good['valid']);
		$this->assertSame(['iban' => 'NL91ABNA0417164300', 'kenteken' => 'AB12CD', 'bsn' => '111222333'], $good['answers']);

		$bad = $this->validator()->validate($fields, ['iban' => 'NL91ABNA0417164301', 'kenteken' => 'ABC', 'bsn' => '111222334']);
		$this->assertFalse($bad['valid']);
		$this->assertSame(['iban', 'kenteken', 'bsn'], array_keys($bad['errors']));
		$this->assertStringContainsString('IBAN', $bad['errors']['iban']);
	}//end testAFieldWithAFormatIsCheckedAndStoredNormalised()

	/**
	 * data-lookups-and-checks-in-forms T03: a value outside the reference
	 * list is refused, and a list that came back empty accepts nothing.
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t03
	 */
	public function testAValueOutsideTheReferenceListIsRefused(): void {
		$list = ['name' => 'woning', 'type' => 'string', 'options' => [['value' => 'flat', 'label' => 'Flat'], ['value' => 'huis', 'label' => 'Huis']]];
		$this->assertTrue($this->validator()->validate([$list], ['woning' => 'flat'])['valid']);
		$this->assertFalse($this->validator()->validate([$list], ['woning' => 'kasteel'])['valid']);

		$closed = ['name' => 'woning', 'type' => 'string', 'options' => [], 'referenceListEmpty' => true];
		$this->assertFalse($this->validator()->validate([$closed], ['woning' => 'flat'])['valid']);
	}//end testAValueOutsideTheReferenceListIsRefused()

	/**
	 * data-lookups-and-checks-in-forms T01: an address block needs a real
	 * postcode, a number, a street and a town; the street and town may have
	 * been changed by hand.
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t01
	 */
	public function testAnAddressBlockMustBeComplete(): void {
		$field = [['name' => 'adres', 'type' => 'addressNL']];
		$block = ['postcode' => '1234 AB', 'number' => '12', 'street' => 'Lindelaan', 'town' => 'Zuiddrecht'];

		$this->assertTrue($this->validator()->validate($field, ['adres' => $block])['valid']);
		foreach (['postcode' => '12AB', 'number' => '', 'street' => ' ', 'town' => ''] as $key => $bad) {
			$this->assertFalse($this->validator()->validate($field, ['adres' => [$key => $bad] + $block])['valid'], $key);
		}

		$this->assertFalse($this->validator()->validate($field, ['adres' => 'Lindelaan 12'])['valid']);
	}//end testAnAddressBlockMustBeComplete()

	/**
	 * A format this server does not know checks nothing and changes nothing.
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t02
	 */
	public function testAnUnknownFormatIsLeftAlone(): void {
		$result = $this->validator()->validate([['name' => 'x', 'type' => 'string', 'format' => 'colour']], ['x' => 'red']);

		$this->assertTrue($result['valid']);
		$this->assertSame(['x' => 'red'], $result['answers']);
	}//end testAnUnknownFormatIsLeftAlone()

	/**
	 * form-flow-repeating-groups-calculations-and-decisions REQ-FFL-001: a group
	 * is a list whose count fits repeat.min and repeat.max.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t01
	 */
	public function testAGroupMustHoldBetweenMinAndMaxItems(): void {
		$group = ['name' => 'bewoners', 'type' => 'group', 'repeat' => ['min' => 2, 'max' => 3], 'fields' => [['name' => 'naam', 'required' => true]]];
		$item  = static fn (string $name): array => ['naam' => $name];

		$tooFew = $this->validator()->validate(fields: [$group], answers: ['bewoners' => [$item('Ans')]]);
		$this->assertFalse($tooFew['valid']);
		$this->assertSame('Add at least %s.', $tooFew['errors']['bewoners']);

		$none = $this->validator()->validate(fields: [$group], answers: []);
		$this->assertArrayHasKey('bewoners', $none['errors'], 'no items at all is below the minimum');

		$tooMany = $this->validator()->validate(fields: [$group], answers: ['bewoners' => [$item('a'), $item('b'), $item('c'), $item('d')]]);
		$this->assertSame('You can add at most %s.', $tooMany['errors']['bewoners']);

		$fine = $this->validator()->validate(fields: [$group], answers: ['bewoners' => [$item('Ans'), $item('Piet')]]);
		$this->assertTrue($fine['valid']);
		$this->assertSame([['naam' => 'Ans'], ['naam' => 'Piet']], $fine['answers']['bewoners']);

	}//end testAGroupMustHoldBetweenMinAndMaxItems()

	/**
	 * REQ-FFL-001: the server names the item and the field, and keeps only the
	 * sub-fields the group declares.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t01
	 */
	public function testTheServerChecksEachItemAndNamesItAndTheField(): void {
		$group = ['name' => 'bewoners', 'type' => 'group', 'repeat' => ['min' => 1], 'fields' => [['name' => 'naam', 'required' => true], ['name' => 'huisnummer']]];

		$result = $this->validator()->validate(
			fields: [$group],
			answers: ['bewoners' => [['naam' => 'Ans', 'huisnummer' => '14', 'bsn' => '111222333'], ['naam' => ' ']]]
		);

		$this->assertFalse($result['valid']);
		$this->assertSame(['bewoners[1].naam'], array_keys($result['errors']));

		$ok = $this->validator()->validate(fields: [$group], answers: ['bewoners' => [['naam' => 'Ans', 'huisnummer' => '14', 'bsn' => '111222333']]]);
		$this->assertSame([['naam' => 'Ans', 'huisnummer' => '14']], $ok['answers']['bewoners'], 'an invented sub-field is not kept');

		$notAList = $this->validator()->validate(fields: [$group], answers: ['bewoners' => 'Ans']);
		$this->assertSame('This answer must be a list.', $notAList['errors']['bewoners']);

		$optional = $this->validator()->validate(fields: [['name' => 'extra', 'type' => 'group', 'fields' => [['name' => 'x']]]], answers: []);
		$this->assertTrue($optional['valid'], 'a group without a minimum may stay empty');

	}//end testTheServerChecksEachItemAndNamesItAndTheField()

	/**
	 * REQ-FFL-002: a calculated or decided field is never taken from the browser,
	 * and being absent from the submission is not a missing answer.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t03
	 */
	public function testACalculatedFieldIsNeverTakenFromTheBrowser(): void {
		$fields = [
			['name' => 'einddatum', 'required' => true, 'calculate' => ['op' => 'addDays', 'args' => ['x', 1]]],
			['name' => 'soort', 'required' => true, 'computed' => true],
		];

		$result = $this->validator()->validate(fields: $fields, answers: ['einddatum' => '2030-01-01', 'soort' => 'gehackt']);

		$this->assertTrue($result['valid']);
		$this->assertSame([], $result['answers']);

	}//end testACalculatedFieldIsNeverTakenFromTheBrowser()

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
