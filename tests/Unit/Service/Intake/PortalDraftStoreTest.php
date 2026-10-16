<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use DateTimeImmutable;
use OCA\Portaliq\Service\Intake\PortalDraftStore;
use OCA\Portaliq\Tests\Unit\Service\Identity\PortalIdentityStoreTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * site-multi-step-forms T8 (REQ-SMF-021): a draft is its owner's, holds no
 * files, is replaced rather than duplicated and is purged after its retention.
 *
 * @spec openspec/changes/site-multi-step-forms/tasks.md#T8
 */
#[CoversClass(PortalDraftStore::class)]
class PortalDraftStoreTest extends TestCase {
	use PortalIdentityStoreTrait;

	private const NOW = '2026-10-02 12:00:00';

	protected function setUp(): void {
		$this->rows = [];
	}//end setUp()

	public function testADraftIsOnlyItsOwnersToRead(): void {
		$store = $this->store();
		$store->save(subjectRef: 'sanne', actionKey: 'dossiq/woo', answers: ['a' => '1'], step: 'step-2', retentionDays: 30, now: $this->now());

		$this->assertSame('step-2', $store->read(subjectRef: 'sanne', actionKey: 'dossiq/woo', now: $this->now())['step']);
		$this->assertNull($store->read(subjectRef: 'ahmed', actionKey: 'dossiq/woo', now: $this->now()));
		$this->assertNull($store->read(subjectRef: 'sanne', actionKey: 'dossiq/other', now: $this->now()));
	}//end testADraftIsOnlyItsOwnersToRead()

	public function testASignedOutVisitorGetsNoDraft(): void {
		$store = $this->store();

		$this->assertNull($store->save(subjectRef: '', actionKey: 'dossiq/woo', answers: ['a' => '1'], step: 's', retentionDays: 30));
		$this->assertSame([], $this->storedRows('portalDraft'));
	}//end testASignedOutVisitorGetsNoDraft()

	public function testFileAnswersAreNotKept(): void {
		$store = $this->store();
		$store->save(
			subjectRef: 'sanne',
			actionKey: 'dossiq/woo',
			answers: ['naam' => 'Sanne', 'bijlage' => ['fileName' => 'a.pdf', 'contentBase64' => 'AAAA']],
			step: 's',
			retentionDays: 30
		);

		$this->assertSame(['naam' => 'Sanne'], $this->storedRows('portalDraft')[0]['answers']);
	}//end testFileAnswersAreNotKept()

	public function testSavingAgainReplacesTheDraft(): void {
		$store = $this->store();
		$store->save(subjectRef: 'sanne', actionKey: 'dossiq/woo', answers: ['a' => '1'], step: 'step-1', retentionDays: 30);
		$store->save(subjectRef: 'sanne', actionKey: 'dossiq/woo', answers: ['a' => '2'], step: 'step-3', retentionDays: 30);

		$this->assertCount(1, $this->storedRows('portalDraft'));
		$this->assertSame('step-3', $this->storedRows('portalDraft')[0]['step']);
	}//end testSavingAgainReplacesTheDraft()

	public function testRetentionIsClampedTo1To90Days(): void {
		$store = $this->store();
		$long = $store->save(subjectRef: 'a', actionKey: 'x/y', answers: [], step: '', retentionDays: 500, now: $this->now());
		$short = $store->save(subjectRef: 'b', actionKey: 'x/y', answers: [], step: '', retentionDays: 0, now: $this->now());

		$this->assertStringStartsWith('2026-12-31', $long['expiresAt']);
		$this->assertStringStartsWith('2026-10-03', $short['expiresAt']);
	}//end testRetentionIsClampedTo1To90Days()

	public function testSendingDeletesTheDraft(): void {
		$store = $this->store();
		$store->save(subjectRef: 'sanne', actionKey: 'dossiq/woo', answers: ['a' => '1'], step: 's', retentionDays: 30);

		$this->assertTrue($store->discard(subjectRef: 'sanne', actionKey: 'dossiq/woo'));
		$this->assertSame([], $this->storedRows('portalDraft'));
		$this->assertFalse($store->discard(subjectRef: 'sanne', actionKey: 'dossiq/woo'));
	}//end testSendingDeletesTheDraft()

	public function testAnExpiredDraftIsDeleted(): void {
		$store = $this->store();
		$store->save(subjectRef: 'old', actionKey: 'x/y', answers: [], step: '', retentionDays: 1, now: new DateTimeImmutable('2026-09-01 12:00:00'));
		$store->save(subjectRef: 'new', actionKey: 'x/y', answers: [], step: '', retentionDays: 30, now: $this->now());

		$this->assertSame(1, $store->purgeExpired(now: $this->now()));
		$remaining = $this->storedRows('portalDraft');
		$this->assertCount(1, $remaining);
		$this->assertSame('new', $remaining[0]['subjectRef']);
	}//end testAnExpiredDraftIsDeleted()

	public function testAnExpiredDraftIsNotReadBeforeThePurgeRuns(): void {
		$store = $this->store();
		$store->save(subjectRef: 'old', actionKey: 'x/y', answers: [], step: '', retentionDays: 1, now: new DateTimeImmutable('2026-09-01 12:00:00'));

		$this->assertNull($store->read(subjectRef: 'old', actionKey: 'x/y', now: $this->now()));
	}//end testAnExpiredDraftIsNotReadBeforeThePurgeRuns()

	private function now(): DateTimeImmutable {
		return new DateTimeImmutable(self::NOW);
	}//end now()

	private function store(): PortalDraftStore {
		return new PortalDraftStore($this->fakeReader(), $this->fakeWriter());
	}//end store()
}//end class
