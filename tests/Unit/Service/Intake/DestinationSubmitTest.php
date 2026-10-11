<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\DestinationSubmit;
use OCA\Portaliq\Tests\Unit\Service\Intake\Fakes\FormSubmitFake;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * submit-creates-the-case-directly 2.1/2.3: the submit reaches OpenRegister's
 * FormSubmitService in process, anonymous and under RBAC, and every refusal
 * keeps its status: 422 with findings, 503 when nothing answers.
 *
 * @covers \OCA\Portaliq\Service\Intake\DestinationSubmit
 *
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md
 */
class DestinationSubmitTest extends TestCase {

	public function testTheSubmitGoesToOpenRegisterAsAnAnonymousWriteAndAnswersItsReference(): void {
		$fake   = new FormSubmitFake(outcome: ['reference' => '2026-0042', 'id' => 'u-1', 'receivedAt' => '2026-10-11T09:00:00+00:00', 'confirmation' => ['deadline' => '2026-11-22']]);
		$result = (new DestinationSubmit($fake->container(), $this->createMock(LoggerInterface::class)))->submit(
			destination: ['register' => 'dossiq', 'schema' => 'case'],
			mapping: ['fields' => [['field' => 'title', 'property' => 'title']], 'fixed' => []],
			payload: ['title' => 'Bezwaar'],
			idempotencyKey: 'key-1',
			scope: 'gemeente-x/bezwaar'
		);

		$this->assertTrue($result['ok']);
		$this->assertSame('2026-0042', $result['answer']['reference']);
		$this->assertCount(1, $fake->calls);
		$this->assertNull($fake->calls[0]['subject']);
		$this->assertSame('key-1', $fake->calls[0]['idempotencyKey']);
		$this->assertSame('gemeente-x/bezwaar', $fake->calls[0]['scope']);
		$this->assertSame(['title' => 'Bezwaar'], $fake->calls[0]['payload']);
	}//end testTheSubmitGoesToOpenRegisterAsAnAnonymousWriteAndAnswersItsReference()

	public function testNoKeyIsSentAsNoKey(): void {
		$fake = new FormSubmitFake(outcome: ['reference' => 'r']);
		(new DestinationSubmit($fake->container(), $this->createMock(LoggerInterface::class)))->submit(destination: ['register' => 'a', 'schema' => 'b'], mapping: [], payload: []);

		$this->assertNull($fake->calls[0]['idempotencyKey']);
	}//end testNoKeyIsSentAsNoKey()

	public function testARefusalKeepsItsStatusAndFindings(): void {
		$findings = [['property' => 'title', 'code' => 'required-unmapped', 'message' => 'A title is required.']];
		$fake     = new FormSubmitFake(outcome: FormSubmitFake::refusal(status: 422, findings: $findings));

		$result = (new DestinationSubmit($fake->container(), $this->createMock(LoggerInterface::class)))->submit(destination: ['register' => 'a', 'schema' => 'b'], mapping: [], payload: []);

		$this->assertFalse($result['ok']);
		$this->assertSame(422, $result['status']);
		$this->assertSame($findings, $result['findings']);
		$this->assertSame([], $result['answer']);
	}//end testARefusalKeepsItsStatusAndFindings()

	public function testAnyOtherFailureIsTryAgainLaterAndIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');
		$fake = new FormSubmitFake(outcome: new RuntimeException('database gone'));

		$result = (new DestinationSubmit($fake->container(), $logger))->submit(destination: ['register' => 'a', 'schema' => 'b'], mapping: [], payload: []);

		$this->assertSame(503, $result['status']);
	}//end testAnyOtherFailureIsTryAgainLaterAndIsLogged()

	public function testWithoutOpenRegisterTheSubmitIsTryAgainLater(): void {
		$empty  = (new FormSubmitFake())->container();
		$none   = new class implements \Psr\Container\ContainerInterface {
			public function get(string $id): mixed {
				throw new RuntimeException('none');
			}

			public function has(string $id): bool {
				return false;
			}
		};
		$result = (new DestinationSubmit($none, $this->createMock(LoggerInterface::class)))->submit(destination: ['register' => 'a', 'schema' => 'b'], mapping: [], payload: []);

		$this->assertFalse($result['ok']);
		$this->assertSame(503, $result['status']);
		$this->assertTrue($empty->has('OCA\\OpenRegister\\Service\\Form\\FormSubmitService'));
	}//end testWithoutOpenRegisterTheSubmitIsTryAgainLater()

	public function testTheCheckAsksTheValidatorWithTheSchemaEntityAndTheAudience(): void {
		$schema    = new \stdClass();
		$validator = new class {
			public array $asked = [];

			public function validate(array $mapping, object $schema, array $options = []): array {
				$this->asked = compact('mapping', 'schema', 'options');
				return [['property' => 'caseType', 'code' => 'required-unmapped', 'message' => 'No case type.'], 'not-a-finding'];
			}
		};
		$mapper = new class($schema) {
			public function __construct(private object $schema) {
			}

			public function findBySlug(string $slug, int $limit, int $offset, bool $_rbac, bool $_multitenancy): array {
				return $slug === 'case' ? [$this->schema] : [];
			}
		};
		$container = (new FormSubmitFake())->container([
			'OCA\\OpenRegister\\Service\\Form\\FormDestinationValidator' => $validator,
			'OCA\\OpenRegister\\Db\\SchemaMapper' => $mapper,
		]);

		$submit   = new DestinationSubmit($container, $this->createMock(LoggerInterface::class));
		$findings = $submit->findings(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: ['fields' => []], audience: 'public');

		$this->assertSame([['property' => 'caseType', 'code' => 'required-unmapped', 'message' => 'No case type.']], $findings);
		$this->assertTrue($validator->asked['schema'] === $schema);
		$this->assertSame(['audience' => 'public'], $validator->asked['options']);
		$this->assertNull($submit->findings(destination: ['register' => 'dossiq', 'schema' => 'unknown'], mapping: [], audience: 'public'));
	}//end testTheCheckAsksTheValidatorWithTheSchemaEntityAndTheAudience()

	public function testWithoutTheValidatorTheCheckSaysItCouldNotCheck(): void {
		$submit = new DestinationSubmit((new FormSubmitFake())->container(), $this->createMock(LoggerInterface::class));

		$this->assertNull($submit->findings(destination: ['register' => 'dossiq', 'schema' => 'case'], mapping: [], audience: 'public'));
	}//end testWithoutTheValidatorTheCheckSaysItCouldNotCheck()
	/**
	 * A draft service with an owner built from the subject reference, as `portal:<ref>`.
	 */
	private function drafts(FormSubmitFake $fake, array $more = [], ?LoggerInterface $logger = null): DestinationSubmit {
		return new DestinationSubmit(
			$fake->container($more),
			$logger ?? $this->createMock(LoggerInterface::class),
			static fn (string $subjectRef): ?object => $subjectRef === '' ? null : (object)['value' => 'portal:' . $subjectRef]
		);
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
		$submit = new DestinationSubmit($fake->container(), $this->createMock(LoggerInterface::class));
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
}//end class
