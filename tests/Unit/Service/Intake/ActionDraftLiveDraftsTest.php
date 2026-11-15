<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\OpenRegister\Service\Object\DiscardDraftRequest;
use DateTimeImmutable;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\Intake\ActionDraft;
use OCA\Portaliq\Service\Intake\DestinationSubmit;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Tests\Unit\Service\Intake\Fakes\FormSubmitFake;
use OCA\Portaliq\Tests\Unit\Service\Intake\Fakes\PortalOwnerDraft;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\Portaliq\Contribution\CreateBody;

/**
 * form-save-and-resume-dialogs REQ-FSR-003: the own area lists the subject's
 * unfinished forms, one per draftable action that keeps a draft, with its
 * label, last save and expiry.
 *
 * @covers \OCA\Portaliq\Service\Intake\ActionDraft
 * @uses \OCA\Portaliq\Service\Intake\ActionAnswerFields
 * @uses \OCA\Portaliq\Service\Intake\DestinationDraft
 * @uses \OCA\Portaliq\Service\Intake\DestinationSubmit
 * @uses \OCA\Portaliq\Contribution\ActionScopeResolver
 * @uses \OCA\Portaliq\Contribution\CreateBody
 * @uses \OCA\Portaliq\Service\PortalSessionService
 * @uses \OCA\Portaliq\Kernel\Support\SessionTrust
 *
 *
 * @spec openspec/changes/form-save-and-resume-dialogs/specs/site-forms/spec.md#requirement-the-own-area-lists-unfinished-forms-req-fsr-003
 */
class ActionDraftLiveDraftsTest extends TestCase {

	private const ACTION = [
		'id' => 'request',
		'label' => 'Request a permit',
		'type' => 'create',
		'register' => 'dossiq',
		'schema' => 'case',
		'fields' => ['title'],
		'defaults' => ['caseType' => 'ct-1'],
		'draft' => ['retentionDays' => 10],
	];

	private FormSubmitFake $fake;

	private object $discard;

	private function drafts(array $aggregate = []): ActionDraft {
		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn($aggregate);
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('resolveScopeValue')->willReturnCallback(
			static fn (string $scopeClaim, string $contributingApp, array $subject): ?string => ($scopeClaim === 'missing' ? null : (string)($subject['subjectRef'] ?? ''))
		);
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('stamp')->willReturnCallback(
			static fn (string $schema, string $scopeField, string $subjectRef, string $organisation, array $data): array => array_merge($data, [$scopeField => $subjectRef, 'organisation' => $organisation])
		);

		$this->fake    = new FormSubmitFake();
		$this->discard = new class {
			public array $asked = [];

			public function discard(DiscardDraftRequest $request): bool {
				[$destination, $draftId, $owner] = [$request->destination, $request->draftId, $request->owner];
				$this->asked[] = $draftId;
				return true;
			}
		};
		$submit = new PortalOwnerDraft($this->fake->container(['OCA\\OpenRegister\\Service\\Object\\DraftDiscard' => $this->discard]), $this->createMock(LoggerInterface::class), new DestinationSubmit($this->fake->container(['OCA\\OpenRegister\\Service\\Object\\DraftDiscard' => $this->discard]), $this->createMock(LoggerInterface::class)));

		return new ActionDraft(registry: $registry, reader: $reader, writer: $writer, drafts: $submit, createBody: new CreateBody());
	}//end drafts()

	public function testEachDraftableActionWithALiveDraftIsListedWithItsExpiry(): void {
		$aggregate = ['contributions' => [
			'not-a-contribution',
			['app' => 'dossiq', 'actions' => [
				'not-an-action',
				['id' => 'no-draft', 'type' => 'create', 'register' => 'dossiq', 'schema' => 'case'],
				['id' => 'strict', 'type' => 'create', 'register' => 'dossiq', 'schema' => 'case', 'minTrust' => 'substantial', 'draft' => []],
				self::ACTION,
			]],
		]];
		$drafts            = $this->drafts($aggregate);
		$updatedAt         = (new DateTimeImmutable('-1 day'))->format(DATE_ATOM);
		$this->fake->found = ['id' => 'd-1', 'status' => 'draft', 'data' => ['title' => 'Half'], 'updatedAt' => $updatedAt];

		$live = $drafts->liveDrafts(subject: ['subjectRef' => 'sub-1', 'trust' => 'low']);

		$this->assertSame(
			[[
				'app'       => 'dossiq',
				'action'    => 'request',
				'label'     => 'Request a permit',
				'updatedAt' => $updatedAt,
				'expiresAt' => (new DateTimeImmutable($updatedAt))->modify('+10 days')->format(DATE_ATOM),
			]],
			$live
		);
		$this->assertCount(1, $this->fake->calls, 'Only the one draftable action with a draft declaration is looked up.');
	}//end testEachDraftableActionWithALiveDraftIsListedWithItsExpiry()

	public function testAnActionWithoutADraftOrPastItsRetentionIsNotListed(): void {
		$aggregate = ['contributions' => [['app' => 'dossiq', 'actions' => [self::ACTION]]]];

		$drafts            = $this->drafts($aggregate);
		$this->fake->found = null;
		$this->assertSame([], $drafts->liveDrafts(subject: ['subjectRef' => 'sub-1']));

		$drafts            = $this->drafts($aggregate);
		$this->fake->found = ['id' => 'd-old', 'data' => [], 'updatedAt' => '2020-01-01T00:00:00+00:00'];
		$this->assertSame([], $drafts->liveDrafts(subject: ['subjectRef' => 'sub-1']));
		$this->assertSame(['d-old'], $this->discard->asked, 'A draft past its retention is discarded on the way.');
	}//end testAnActionWithoutADraftOrPastItsRetentionIsNotListed()
}//end class
