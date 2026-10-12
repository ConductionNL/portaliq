<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

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

/**
 * submit-creates-the-case-directly 5.1/5.2: a create action's draft is the
 * destination object in status draft; the action is the subject's own, the
 * body holds only declared answers plus the create's stamps, and a draft past
 * the action's retention (decision 201, 30 days by default) reads as none and
 * is discarded.
 *
 * @covers \OCA\Portaliq\Service\Intake\ActionDraft
 * @uses \OCA\Portaliq\Service\Intake\DestinationDraft
 * @uses \OCA\Portaliq\Service\Intake\DestinationSubmit
 * @uses \OCA\Portaliq\Contribution\ActionScopeResolver
 * @uses \OCA\Portaliq\Contribution\CreateBody
 * @uses \OCA\Portaliq\Service\PortalSessionService
 *
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
 */
class ActionDraftTest extends TestCase {

	private const ACTION = [
		'id' => 'request',
		'type' => 'create',
		'register' => 'dossiq',
		'schema' => 'case',
		'fields' => ['title', 'description', 'attachment', 'claims'],
		'fieldConfigs' => ['attachment' => ['type' => 'file']],
		'defaults' => ['caseType' => 'ct-1', 'labels' => ['a']],
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

			public function discard(array $destination, string $draftId, object $owner): bool {
				$this->asked[] = $draftId;
				return true;
			}
		};
		$submit = new PortalOwnerDraft($this->fake->container(['OCA\\OpenRegister\\Service\\Object\\DraftDiscard' => $this->discard]), $this->createMock(LoggerInterface::class), new DestinationSubmit($this->fake->container(['OCA\\OpenRegister\\Service\\Object\\DraftDiscard' => $this->discard]), $this->createMock(LoggerInterface::class)));

		return new ActionDraft(registry: $registry, reader: $reader, writer: $writer, drafts: $submit);
	}//end drafts()

	public function testOnlyTheSubjectsOwnCreateActionInThatAppHoldsADraft(): void {
		$aggregate = ['contributions' => [
			['app' => 'other', 'actions' => [self::ACTION]],
			['app' => 'dossiq', 'actions' => [
				'not-an-action',
				['id' => 'send', 'type' => 'endpoint', 'register' => 'dossiq', 'schema' => 'case'],
				['id' => 'strict', 'type' => 'create', 'register' => 'dossiq', 'schema' => 'case', 'minTrust' => 'substantial'],
				['id' => 'nowhere', 'type' => 'create'],
				self::ACTION,
			]],
		]];
		$drafts  = $this->drafts($aggregate);
		$subject = ['subjectRef' => 'sub-1', 'trust' => 'low'];

		$this->assertSame(['action' => self::ACTION, 'app' => 'dossiq'], $drafts->actionFor(subject: $subject, appId: 'dossiq', actionId: 'request'));
		$this->assertNull($drafts->actionFor(subject: $subject, appId: 'dossiq', actionId: 'send'));
		$this->assertNull($drafts->actionFor(subject: $subject, appId: 'dossiq', actionId: 'strict'));
		$this->assertNull($drafts->actionFor(subject: $subject, appId: 'dossiq', actionId: 'nowhere'));
		$this->assertNull($drafts->actionFor(subject: $subject, appId: 'dossiq', actionId: 'unknown'));
		$this->assertNull($drafts->actionFor(subject: $subject, appId: 'none', actionId: 'request'));
	}//end testOnlyTheSubjectsOwnCreateActionInThatAppHoldsADraft()

	public function testTheBodyHoldsDeclaredAnswersDefaultsAndTheCreatesStamps(): void {
		$body = $this->drafts()->bodyOf(
			match: ['action' => self::ACTION, 'app' => 'dossiq'],
			subject: ['subjectRef' => 'sub-1', 'organisation' => 'org-1'],
			answers: ['title' => 'Half', 'attachment' => ['fileName' => 'x.pdf'], 'claims' => ['bsn' => '1'], 'caseType' => 'forged', 'other' => 'x', 'description' => null]
		);

		$this->assertSame(['title' => 'Half', 'caseType' => 'ct-1', 'labels' => ['a'], 'subjectRef' => 'sub-1', 'organisation' => 'org-1'], $body);
		$this->assertNull(
			$this->drafts()->bodyOf(match: ['action' => self::ACTION + ['scopeClaim' => 'missing'], 'app' => 'dossiq'], subject: ['subjectRef' => 'sub-1'], answers: [])
		);
	}//end testTheBodyHoldsDeclaredAnswersDefaultsAndTheCreatesStamps()

	public function testALiveDraftIsFoundByItsScalarDefaultsWithItsExpiry(): void {
		$drafts            = $this->drafts();
		$this->fake->found = ['id' => 'd-1', 'status' => 'draft', 'data' => ['title' => 'Half', 'subjectRef' => 'sub-1'], 'updatedAt' => '2026-10-01T10:00:00+00:00'];

		$draft = $drafts->find(action: self::ACTION, subjectRef: 'sub-1', now: new DateTimeImmutable('2026-10-05T10:00:00+00:00'));

		$this->assertSame('d-1', $draft['id']);
		$this->assertSame('2026-10-11T10:00:00+00:00', $draft['expiresAt']);
		$this->assertSame(['caseType' => 'ct-1'], $this->fake->calls[0]['match']);
		$this->assertSame(['register' => 'dossiq', 'schema' => 'case'], $this->fake->calls[0]['destination']);
		$this->assertSame(['title' => 'Half'], $drafts->answersOf(action: self::ACTION, draft: $draft));
		$this->assertSame([], $this->discard->asked);
	}//end testALiveDraftIsFoundByItsScalarDefaultsWithItsExpiry()

	public function testADraftPastItsRetentionOrWithoutAMomentIsDiscardedAndReadsAsNone(): void {
		$drafts            = $this->drafts();
		$this->fake->found = ['id' => 'd-old', 'data' => [], 'updatedAt' => '2026-09-01T10:00:00+00:00'];
		$this->assertNull($drafts->find(action: self::ACTION, subjectRef: 'sub-1', now: new DateTimeImmutable('2026-10-05T10:00:00+00:00')));

		$this->fake->found = ['id' => 'd-blank', 'data' => [], 'updatedAt' => ''];
		$this->assertNull($drafts->find(action: self::ACTION, subjectRef: 'sub-1'));

		$this->fake->found = ['id' => 'd-bad', 'data' => [], 'updatedAt' => 'not a date'];
		$this->assertNull($drafts->find(action: self::ACTION, subjectRef: 'sub-1'));

		$this->assertSame(['d-old', 'd-blank', 'd-bad'], $this->discard->asked);

		$this->fake->found = null;
		$this->assertNull($drafts->find(action: self::ACTION, subjectRef: 'sub-1'));
	}//end testADraftPastItsRetentionOrWithoutAMomentIsDiscardedAndReadsAsNone()

	public function testRetentionIsTheActionsWithinOneToNinetyElseThirty(): void {
		$this->assertSame(10, $this->drafts()->retentionDays(action: self::ACTION));
		$this->assertSame(30, $this->drafts()->retentionDays(action: []));
		$this->assertSame(30, $this->drafts()->retentionDays(action: ['draft' => ['retentionDays' => 0]]));
		$this->assertSame(30, $this->drafts()->retentionDays(action: ['draft' => ['retentionDays' => '12']]));
		$this->assertSame(90, $this->drafts()->retentionDays(action: ['draft' => ['retentionDays' => 400]]));
		$this->assertSame('2026-10-31T00:00:00+00:00', $this->drafts()->expiresAt(action: [], updatedAt: '2026-10-01T00:00:00+00:00')?->format(DATE_ATOM));
	}//end testRetentionIsTheActionsWithinOneToNinetyElseThirty()
}//end class
