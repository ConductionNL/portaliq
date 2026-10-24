<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\DestinationDraft;
use OCA\Portaliq\Service\Intake\DestinationSubmit;
use OCA\Portaliq\Tests\Unit\Service\Intake\Fakes\FormSubmitFake;
use OCA\Portaliq\Tests\Unit\Service\Intake\Fakes\PortalOwnerDraft;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * submit-creates-the-case-directly 5.1/5.2: a draft is saved, found, sent
 * and discarded in OpenRegister for the owner `portal:<subjectRef>`
 * (decision 195); a type-invalid draft keeps its findings, and without an
 * owner or OpenRegister every call refuses.
 *
 * @covers \OCA\Portaliq\Service\Intake\DestinationDraft
 * @uses \OCA\Portaliq\Service\Intake\DestinationSubmit
 *
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
 */
class DestinationDraftTest extends TestCase {

	/**
	 * A draft service with an owner built from the subject reference, as `portal:<ref>`.
	 */
	private function drafts(FormSubmitFake $fake, array $more = [], ?LoggerInterface $logger = null): DestinationDraft {
		$logger ??= $this->createMock(LoggerInterface::class);

		return new PortalOwnerDraft($fake->container($more), $logger, new DestinationSubmit($fake->container($more), $logger));
	}//end drafts()

	public function testADraftIsSavedUnmappedForThePortalOwnerAndAnswersItsId(): void {
		$fake   = new FormSubmitFake(outcome: ['id' => 'd-1', 'status' => 'draft']);
		$result = $this->drafts($fake)->saveDraft(destination: ['register' => 'dossiq', 'schema' => 'case'], payload: ['title' => 'Half'], subjectRef: 'sub-1');

		$this->assertTrue($result['ok']);
		$this->assertSame('d-1', $result['answer']['id']);
		$this->assertTrue($fake->calls[0]['saveDraft']);
		$this->assertNull($fake->calls[0]['mapping']);
		$this->assertNull($fake->calls[0]['subject']);
		$this->assertNull($fake->calls[0]['draftId']);
		$this->assertSame('portal:sub-1', $fake->calls[0]['owner']->value);

		$this->drafts($fake)->saveDraft(destination: ['register' => 'dossiq', 'schema' => 'case'], payload: [], subjectRef: 'sub-1', draftId: 'd-1');
		$this->assertSame('d-1', $fake->calls[1]['draftId']);
	}//end testADraftIsSavedUnmappedForThePortalOwnerAndAnswersItsId()

	public function testATypeInvalidDraftKeepsItsFindingsAndNoOwnerOrServiceIsTryAgainLater(): void {
		$findings = [['property' => 'preferredDate', 'code' => 'format-mismatch', 'message' => 'Not a date.']];
		$refused  = $this->drafts(new FormSubmitFake(outcome: FormSubmitFake::refusal(status: 422, findings: $findings)))
			->saveDraft(destination: ['register' => 'a', 'schema' => 'b'], payload: ['preferredDate' => '0612345678'], subjectRef: 'sub-1');
		$this->assertSame(422, $refused['status']);
		$this->assertSame($findings, $refused['findings']);

		$this->assertSame(503, $this->drafts(new FormSubmitFake(outcome: ['id' => 'd']))->saveDraft(destination: [], payload: [], subjectRef: '')['status']);
		$this->assertSame(503, $this->drafts(new FormSubmitFake(outcome: []))->saveDraft(destination: [], payload: [], subjectRef: 's')['status']);
	}//end testATypeInvalidDraftKeepsItsFindingsAndNoOwnerOrServiceIsTryAgainLater()

	public function testFindingADraftAsksForTheOwnersNewestWithTheMatch(): void {
		$fake        = new FormSubmitFake();
		$fake->found = ['id' => 'd-1', 'status' => 'draft', 'data' => ['title' => 'Half'], 'updatedAt' => '2026-10-10T10:00:00+00:00'];
		$drafts      = $this->drafts($fake);

		$this->assertSame('d-1', $drafts->findDraft(destination: ['register' => 'a', 'schema' => 'b'], subjectRef: 'sub-1', match: ['caseType' => 'ct'])['id']);
		$this->assertSame(['caseType' => 'ct'], $fake->calls[0]['match']);
		$this->assertSame('portal:sub-1', $fake->calls[0]['owner']->value);
		$this->assertNull($drafts->findDraft(destination: [], subjectRef: ''));

		$fake->found = null;
		$this->assertNull($drafts->findDraft(destination: [], subjectRef: 'sub-1'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$fake->found = new RuntimeException('gone');
		$this->assertNull($this->drafts($fake, [], $logger)->findDraft(destination: [], subjectRef: 'sub-1'));
	}//end testFindingADraftAsksForTheOwnersNewestWithTheMatch()

	public function testSendingADraftSubmitsItWithItsIdAndOwner(): void {
		$fake   = new FormSubmitFake(outcome: ['reference' => '2026-7', 'id' => 'd-1']);
		$result = $this->drafts($fake)->sendDraft(destination: ['register' => 'a', 'schema' => 'b'], payload: ['title' => 'Whole'], subjectRef: 'sub-1', draftId: 'd-1', idempotencyKey: 'k');

		$this->assertSame(201, $result['status']);
		$this->assertSame('2026-7', $result['answer']['reference']);
		$this->assertSame('d-1', $fake->calls[0]['draftId']);
		$this->assertSame('k', $fake->calls[0]['idempotencyKey']);
		$this->assertSame('portal:sub-1', $fake->calls[0]['owner']->value);
		$this->assertNull($fake->calls[0]['mapping']);

		$this->assertSame(503, $this->drafts($fake)->sendDraft(destination: [], payload: [], subjectRef: 'sub-1', draftId: '')['status']);
		$refused = $this->drafts(new FormSubmitFake(outcome: FormSubmitFake::refusal(status: 422, findings: [['property' => 'title', 'code' => 'required', 'message' => 'x']])))
			->sendDraft(destination: [], payload: [], subjectRef: 'sub-1', draftId: 'd-1');
		$this->assertSame(422, $refused['status']);
	}//end testSendingADraftSubmitsItWithItsIdAndOwner()

	public function testDiscardingADraftAsksOpenRegistersDiscardForTheOwner(): void {
		$discard = new class {
			public array $asked = [];

			public function discard(array $destination, string $draftId, object $owner): bool {
				if ($draftId === 'boom') {
					throw new RuntimeException('gone');
				}

				$this->asked[] = [$destination, $draftId, $owner->value];
				return $draftId === 'd-1';
			}
		};
		$drafts = $this->drafts(new FormSubmitFake(), ['OCA\\OpenRegister\\Service\\Object\\DraftDiscard' => $discard]);

		$this->assertTrue($drafts->discardDraft(destination: ['register' => 'a', 'schema' => 'b'], subjectRef: 'sub-1', draftId: 'd-1'));
		$this->assertSame([['register' => 'a', 'schema' => 'b'], 'd-1', 'portal:sub-1'], $discard->asked[0]);
		$this->assertFalse($drafts->discardDraft(destination: [], subjectRef: 'sub-1', draftId: 'theirs'));
		$this->assertFalse($drafts->discardDraft(destination: [], subjectRef: 'sub-1', draftId: ''));
		$this->assertFalse($drafts->discardDraft(destination: [], subjectRef: 'sub-1', draftId: 'boom'));
		$this->assertFalse($this->drafts(new FormSubmitFake())->discardDraft(destination: [], subjectRef: 'sub-1', draftId: 'd-1'));
	}//end testDiscardingADraftAsksOpenRegistersDiscardForTheOwner()

	public function testTheDefaultOwnerIsOpenRegistersPortalSubjectOrNothing(): void {
		$fake   = new FormSubmitFake(outcome: ['id' => 'd-1']);
		$logger = $this->createMock(LoggerInterface::class);
		$submit = new DestinationDraft($fake->container(), $logger, new DestinationSubmit($fake->container(), $logger));
		$result = $submit->saveDraft(destination: [], payload: [], subjectRef: 'sub-1');

		if (class_exists('OCA\\OpenRegister\\Service\\Object\\DraftOwner') === false) {
			// Without OpenRegister there is no owner, so nothing is saved.
			$this->assertSame(503, $result['status']);
			$this->assertSame([], $fake->calls);
			return;
		}

		$this->assertTrue($result['ok']);
		$this->assertSame('portal:sub-1', $fake->calls[0]['owner']->value());
		$this->assertSame(503, $submit->saveDraft(destination: [], payload: [], subjectRef: str_repeat('x', 80))['status']);
	}//end testTheDefaultOwnerIsOpenRegistersPortalSubjectOrNothing()
	public function testFindingsAreFiledUnderTheFieldOfTheirProperty(): void {
		$this->assertSame(
			['preferredDate' => 'Not a date.', 'address' => 'No street.', '_form' => 'Try again.'],
			$this->drafts(new FormSubmitFake())->errorsOf(findings: [
				['property' => 'preferredDate', 'message' => 'Not a date.'],
				['property' => 'address.street', 'message' => 'No street.'],
				['property' => '/address/number', 'message' => 'Second.'],
				['property' => '', 'message' => 'Try again.'],
			])
		);
	}//end testFindingsAreFiledUnderTheFieldOfTheirProperty()
}//end class
