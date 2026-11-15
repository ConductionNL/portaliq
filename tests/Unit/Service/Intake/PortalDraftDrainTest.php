<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use DateTimeImmutable;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\Intake\ActionDraft;
use OCA\Portaliq\Service\Intake\PortalDraftDrain;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Tests\Unit\Service\Intake\Fakes\FormSubmitFake;
use OCA\Portaliq\Service\Intake\DestinationSubmit;
use OCA\Portaliq\Tests\Unit\Service\Intake\Fakes\PortalOwnerDraft;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\Portaliq\Contribution\CreateBody;

/**
 * submit-creates-the-case-directly 5.3: each portalDraft row becomes an
 * OpenRegister draft of its action's destination, owned by the portal
 * subject, and only then is the row deleted. A refused or orphaned row is
 * reported and kept; an expired one is deleted unconverted.
 *
 * @covers \OCA\Portaliq\Service\Intake\PortalDraftDrain
 * @uses \OCA\Portaliq\Service\Intake\ActionDraft
 * @uses \OCA\Portaliq\Service\Intake\ActionAnswerFields
 * @uses \OCA\Portaliq\Service\Intake\DestinationDraft
 * @uses \OCA\Portaliq\Service\Intake\DestinationSubmit
 * @uses \OCA\Portaliq\Contribution\ActionScopeResolver
 * @uses \OCA\Portaliq\Contribution\CreateBody
 * @uses \OCA\Portaliq\Service\PortalSessionService
 * @uses \OCA\Portaliq\Kernel\Support\SessionTrust
 *
 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#5.3
 */
class PortalDraftDrainTest extends TestCase {

	private const ACTION = ['id' => 'request', 'type' => 'create', 'register' => 'dossiq', 'schema' => 'case', 'fields' => ['title', 'preferredDate']];

	/**
	 * The portalDraft rows by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = [];

	private FormSubmitFake $fake;

	public function testRowsBecomeOpenRegisterDraftsAndOnlyConvertedOrExpiredRowsGo(): void {
		$this->rows = [
			'r-ok' => ['id' => 'r-ok', 'subjectRef' => 'sanne', 'actionKey' => 'dossiq/request', 'answers' => ['title' => 'Half', 'file' => ['fileName' => 'x']], 'expiresAt' => '2026-12-01T00:00:00+00:00'],
			'r-old' => ['id' => 'r-old', 'subjectRef' => 'sanne', 'actionKey' => 'dossiq/request', 'answers' => [], 'expiresAt' => '2026-09-01T00:00:00+00:00'],
			'r-gone' => ['id' => 'r-gone', 'subjectRef' => 'sanne', 'actionKey' => 'dossiq/retired', 'answers' => [], 'expiresAt' => ''],
			'r-bad' => ['uuid' => 'r-bad', 'subjectRef' => 'sanne', 'actionKey' => 'nonsense', 'answers' => []],
		];

		$result = $this->drain(outcome: ['id' => 'd-1'])->drain(now: new DateTimeImmutable('2026-10-11T00:00:00+00:00'));

		$this->assertSame(1, $result['converted']);
		$this->assertSame(1, $result['expired']);
		$this->assertSame(2, $result['refused']);
		$this->assertArrayHasKey('r-gone', $result['reasons']);
		$this->assertArrayHasKey('r-bad', $result['reasons']);
		$this->assertSame(2, $result['waiting']);
		$this->assertSame(['r-gone', 'r-bad'], array_keys($this->rows));

		$saved = $this->fake->calls[1];
		$this->assertTrue($saved['saveDraft']);
		$this->assertSame(['title' => 'Half', 'subjectRef' => 'sanne'], $saved['payload']);
		$this->assertSame('portal:sanne', $saved['owner']->value());
	}//end testRowsBecomeOpenRegisterDraftsAndOnlyConvertedOrExpiredRowsGo()

	public function testATypeInvalidRowIsReportedAndKept(): void {
		$this->rows = ['r-1' => ['id' => 'r-1', 'subjectRef' => 'sanne', 'actionKey' => 'dossiq/request', 'answers' => ['preferredDate' => '0612345678'], 'expiresAt' => '2026-12-01T00:00:00+00:00']];

		$drain  = $this->drain(outcome: FormSubmitFake::refusal(status: 422, findings: [['property' => 'preferredDate', 'message' => 'Not a date.']]));
		$result = $drain->drain(now: new DateTimeImmutable('2026-10-11T00:00:00+00:00'));

		$this->assertSame(0, $result['converted']);
		$this->assertSame(['r-1' => 'the destination refused it (422): Not a date.'], $result['reasons']);
		$this->assertArrayHasKey('r-1', $this->rows);
		$this->assertSame(1, $drain->waiting());
	}//end testATypeInvalidRowIsReportedAndKept()

	public function testARowThatCannotBeDeletedIsReported(): void {
		$this->rows = ['r-1' => ['id' => 'r-1', 'subjectRef' => 'sanne', 'actionKey' => 'dossiq/request', 'answers' => [], 'expiresAt' => '2026-12-01T00:00:00+00:00']];

		$result = $this->drain(outcome: ['id' => 'd-1'], deletes: false)->drain(now: new DateTimeImmutable('2026-10-11T00:00:00+00:00'));

		$this->assertSame(['r-1' => 'it was converted but the row could not be deleted'], $result['reasons']);
	}//end testARowThatCannotBeDeletedIsReported()

	/**
	 * Every row's outcome and reason, exactly as recorded.
	 *
	 * @return void
	 */
	public function testEveryRowOutcomeIsAsRecorded(): void {
		$this->rows = [
			'r-now' => ['id' => 'r-now', 'subjectRef' => 'sanne', 'actionKey' => 'dossiq/request', 'answers' => [], 'expiresAt' => '2026-10-11T00:00:00+00:00'],
			'r-later' => ['id' => 'r-later', 'subjectRef' => 'sanne', 'actionKey' => 'dossiq/request', 'answers' => 'x', 'expiresAt' => 'not a date'],
			'r-nobody' => ['id' => 'r-nobody', 'subjectRef' => '', 'actionKey' => 'dossiq/request', 'answers' => []],
			'r-noone' => ['id' => 'r-noone', 'actionKey' => 'dossiq/request'],
			'r-three' => ['id' => 'r-three', 'subjectRef' => 'sanne', 'actionKey' => 'dossiq/request/extra', 'answers' => []],
			'r-nokey' => ['id' => 'r-nokey', 'subjectRef' => 'sanne'],
		];

		$result = $this->drain(outcome: ['id' => 'd-1'])->drain(now: new DateTimeImmutable('2026-10-11T00:00:00+00:00'));

		$this->assertSame(1, $result['converted']);
		$this->assertSame(1, $result['expired']);
		$this->assertSame(
			[
				'r-nobody' => 'its action is not a create action this subject can use',
				'r-noone' => 'its action is not a create action this subject can use',
				'r-three' => 'its action is not a create action this subject can use',
				'r-nokey' => 'its action is not a create action this subject can use',
			],
			$result['reasons']
		);
		$this->assertSame(['r-nobody', 'r-noone', 'r-three', 'r-nokey'], array_keys($this->rows));
	}//end testEveryRowOutcomeIsAsRecorded()

	/**
	 * The drain over fakes.
	 *
	 * @param array<string, mixed>|\Throwable $outcome What saveDraft answers.
	 */
	private function drain(array|\Throwable $outcome, bool $deletes = true): PortalDraftDrain {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(fn (): array => array_values($this->rows) + ['not-a-row']);
		$reader->method('resolveScopeValue')->willReturnCallback(static fn (string $scopeClaim, string $contributingApp, array $subject): string => (string)$subject['subjectRef']);

		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('stamp')->willReturnCallback(
			static fn (string $schema, string $scopeField, string $subjectRef, string $organisation, array $data): array => array_merge($data, [$scopeField => $subjectRef])
		);
		$writer->method('deleteObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id) use ($deletes): bool {
				if ($deletes === true) {
					unset($this->rows[$id]);
				}

				return $deletes;
			}
		);

		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn(['contributions' => [['app' => 'dossiq', 'actions' => [self::ACTION]]]]);

		$this->fake = new FormSubmitFake(outcome: $outcome);
		$drafts     = new PortalOwnerDraft($this->fake->container(), $this->createMock(LoggerInterface::class), new DestinationSubmit($this->fake->container(), $this->createMock(LoggerInterface::class)));

		return new PortalDraftDrain(
			reader: $reader,
			writer: $writer,
			actions: new ActionDraft(registry: $registry, reader: $reader, writer: $writer, drafts: $drafts, createBody: new CreateBody()),
			drafts: $drafts
		);
	}//end drain()
}//end class
