<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalInboxReader;
use OCA\Portaliq\Service\PortalObjectReader;
use PHPUnit\Framework\TestCase;

/**
 * Tests the cross-app inbox aggregation (portal-inbox-v2 T01/T07): every
 * `kind: inbox` collection across the subject's contributions is read through
 * PortalObjectReader (the identical per-row subject + tenant + trust
 * boundary), merged, sorted by `receivedAt` descending, and tagged with its
 * source app/label. Non-inbox collections are skipped entirely. The unread
 * count (T04/T09) is derived from the SAME merged rows.
 *
 * @spec openspec/changes/portal-inbox-v2/tasks.md#T01
 * @spec openspec/changes/portal-inbox-v2/tasks.md#T04
 * @spec openspec/changes/portal-inbox-v2/tasks.md#T07
 * @spec openspec/changes/portal-inbox-v2/tasks.md#T09
 */
class PortalInboxReaderTest extends TestCase {
	private const SUBJECT = [
		'subjectRef' => 's1',
		'audience' => 'supplier',
		'organisation' => 'org-1',
	];

	/**
	 * Two apps each contribute a `kind: inbox` collection; rows merge into
	 * one list sorted newest-first, each tagged with its source app + label.
	 * A non-inbox collection in the SAME contribution is skipped.
	 */
	public function testMergesInboxCollectionsAcrossAppsSortedByReceivedAtDesc(): void {
		$aggregate = [
			'contributions' => [
				[
					'app' => 'procest',
					'label' => 'Procest',
					'collections' => [
						['id' => 'procestInbox', 'kind' => 'inbox', 'register' => 'procest', 'schema' => 'message', 'scopeField' => 'subjectRef'],
						// Not an inbox collection — must be skipped entirely.
						['id' => 'procestContracts', 'register' => 'procest', 'schema' => 'contract', 'scopeField' => 'subjectRef'],
					],
				],
				[
					'app' => 'pipelinq',
					'label' => 'Pipelinq',
					'collections' => [
						['id' => 'pipelinqInbox', 'kind' => 'inbox', 'register' => 'pipelinq', 'schema' => 'notification', 'scopeField' => 'subjectRef'],
					],
				],
			],
		];

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			function (string $register, string $schema) {
				if ($register === 'procest' && $schema === 'message') {
					return [['id' => 'p1', 'subject' => 'Oud bericht', 'receivedAt' => '2026-07-01T00:00:00Z', 'read' => false]];
				}

				if ($register === 'pipelinq' && $schema === 'notification') {
					return [['id' => 'q1', 'subject' => 'Nieuw bericht', 'receivedAt' => '2026-07-20T00:00:00Z', 'read' => false]];
				}

				// The non-inbox collection must never even be READ.
				$this->fail('Only kind:inbox collections may be read.');
			}
		);

		$inboxReader = new PortalInboxReader($reader);
		$messages = $inboxReader->aggregateInbox(self::SUBJECT, $aggregate);

		$this->assertCount(2, $messages);
		// Newest first.
		$this->assertSame('q1', $messages[0]['id']);
		$this->assertSame('p1', $messages[1]['id']);
		// Provenance tags — appId/label plus the register/schema/collection id
		// the SPA needs to address the row through the mark-read endpoint.
		$this->assertSame(
			['appId' => 'pipelinq', 'label' => 'Pipelinq', 'register' => 'pipelinq', 'schema' => 'notification', 'collection' => 'pipelinqInbox'],
			$messages[0]['_source']
		);
		$this->assertSame(
			['appId' => 'procest', 'label' => 'Procest', 'register' => 'procest', 'schema' => 'message', 'collection' => 'procestInbox'],
			$messages[1]['_source']
		);

	}//end testMergesInboxCollectionsAcrossAppsSortedByReceivedAtDesc()

	/**
	 * Rows without a `receivedAt` sort last, never crashing the comparator.
	 */
	public function testRowsWithoutReceivedAtSortLast(): void {
		$aggregate = [
			'contributions' => [
				[
					'app' => 'portaliq',
					'label' => 'Portaliq',
					'collections' => [
						['id' => 'inbox', 'kind' => 'inbox', 'register' => 'portaliq', 'schema' => 'portalMessage', 'scopeField' => 'subjectRef'],
					],
				],
			],
		];

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn(
			[
				['id' => 'no-date', 'subject' => 'Zonder datum'],
				['id' => 'dated', 'subject' => 'Met datum', 'receivedAt' => '2026-07-01T00:00:00Z'],
			]
		);

		$inboxReader = new PortalInboxReader($reader);
		$messages = $inboxReader->aggregateInbox(self::SUBJECT, $aggregate);

		$this->assertSame('dated', $messages[0]['id']);
		$this->assertSame('no-date', $messages[1]['id']);

	}//end testRowsWithoutReceivedAtSortLast()

	/**
	 * No `kind: inbox` collection anywhere → an empty inbox (fail-closed
	 * default, not an error).
	 */
	public function testNoInboxCollectionsYieldsAnEmptyInbox(): void {
		$aggregate = [
			'contributions' => [
				[
					'app' => 'portaliq',
					'label' => 'Portaliq',
					'collections' => [
						['id' => 'exampleCollection', 'register' => 'portaliq', 'schema' => 'exampleDocument', 'scopeField' => 'subjectRef'],
					],
				],
			],
		];

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->expects($this->never())->method('readCollection');

		$inboxReader = new PortalInboxReader($reader);
		$this->assertSame([], $inboxReader->aggregateInbox(self::SUBJECT, $aggregate));

	}//end testNoInboxCollectionsYieldsAnEmptyInbox()

	/**
	 * A per-row trust/tenant drop (or an OR error) inside PortalObjectReader
	 * degrades to fewer/zero rows there — PortalInboxReader must never
	 * compensate or re-widen; it only ever relays what the reader returns.
	 */
	public function testNeverWidensWhatTheUnderlyingReaderReturns(): void {
		$aggregate = [
			'contributions' => [
				[
					'app' => 'portaliq',
					'label' => 'Portaliq',
					'collections' => [
						['id' => 'inbox', 'kind' => 'inbox', 'register' => 'portaliq', 'schema' => 'portalMessage', 'scopeField' => 'subjectRef', 'minTrust' => 'substantial'],
					],
				],
			],
		];

		// The reader (already re-checking trust/tenant per row) returns nothing.
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn([]);

		$inboxReader = new PortalInboxReader($reader);
		$this->assertSame([], $inboxReader->aggregateInbox(self::SUBJECT, $aggregate));

	}//end testNeverWidensWhatTheUnderlyingReaderReturns()

	/**
	 * The unread count reflects only the subject's own unread rows across
	 * every inbox collection, computed from the SAME aggregation pass.
	 */
	public function testUnreadCountCountsOnlyUnreadMessages(): void {
		$aggregate = [
			'contributions' => [
				[
					'app' => 'portaliq',
					'label' => 'Portaliq',
					'collections' => [
						['id' => 'inbox', 'kind' => 'inbox', 'register' => 'portaliq', 'schema' => 'portalMessage', 'scopeField' => 'subjectRef'],
					],
				],
			],
		];

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn(
			[
				['id' => 'm1', 'read' => true],
				['id' => 'm2', 'read' => false],
				// A row without an explicit `read` field counts as unread.
				['id' => 'm3'],
			]
		);

		$inboxReader = new PortalInboxReader($reader);
		$this->assertSame(2, $inboxReader->unreadCount(self::SUBJECT, $aggregate));

	}//end testUnreadCountCountsOnlyUnreadMessages()

	/**
	 * An inbox collection that declares NO way to scope itself must be
	 * REFUSED, not read.
	 *
	 * PortalObjectReader is unscoped-by-omission by design: scopedFilters()
	 * adds the scope filter only when both scopeField and scopeValue are
	 * non-empty, verifyScope()'s per-row check is guarded by
	 * `$scopeField !== ''`, and findAll() runs `_rbac: false,
	 * _multitenancy: false` because portal subjects are not Nextcloud users.
	 * With an empty scopeField all three line up into an unfiltered read of
	 * every row of that schema — every other subject's records.
	 *
	 * Nothing upstream closes it: lib/Contribution/ does not validate
	 * scopeField at all, and the register schema types it as a bare string
	 * with no minLength. So this reader must refuse.
	 *
	 * The assertion is that readCollection is NEVER CALLED. Asserting on an
	 * empty return would also pass if the call happened and the mock simply
	 * returned nothing — which is exactly the shape being defended against.
	 *
	 * @return void
	 */
	public function testRefusesAnInboxCollectionWithNoScopeAtAll(): void {
		$aggregate = [
			'contributions' => [
				[
					'app' => 'rogue',
					'label' => 'Rogue',
					'collections' => [
						// scopeField explicitly empty: `??` does not catch '',
						// so this reaches readCollection as an empty scope.
						['id' => 'leak', 'kind' => 'inbox', 'register' => 'procest', 'schema' => 'message', 'scopeField' => ''],
					],
				],
			],
		];

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->expects($this->never())
			->method('readCollection');

		$inboxReader = new PortalInboxReader($reader);

		$this->assertSame([], $inboxReader->aggregateInbox(self::SUBJECT, $aggregate));

	}//end testRefusesAnInboxCollectionWithNoScopeAtAll()

	/**
	 * The same refusal when `scopeField` is absent entirely and the default
	 * would have applied, but the SUBJECT carries no subjectRef — an
	 * anonymous caller cannot be scoped either.
	 *
	 * @return void
	 */
	public function testRefusesWhenTheSubjectHasNoSubjectRef(): void {
		$aggregate = [
			'contributions' => [
				[
					'app' => 'procest',
					'label' => 'Procest',
					'collections' => [
						['id' => 'inbox', 'kind' => 'inbox', 'register' => 'procest', 'schema' => 'message', 'scopeField' => 'subjectRef'],
					],
				],
			],
		];

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->expects($this->never())
			->method('readCollection');

		$inboxReader = new PortalInboxReader($reader);

		$this->assertSame(
			[],
			$inboxReader->aggregateInbox(['subjectRef' => '', 'audience' => 'supplier', 'organisation' => 'org-1'], $aggregate)
		);

	}//end testRefusesWhenTheSubjectHasNoSubjectRef()

	/**
	 * POSITIVE CONTROL for the two refusals above. `via` and `scopeClaim` are
	 * each a legitimate way to scope a collection WITHOUT a scopeField —
	 * readViaCollection filters the outer rows itself, and a scopeClaim
	 * resolves server-side and returns [] when absent. Neither may be caught
	 * by the refusal, or the fix would have closed the hole by breaking two
	 * working features.
	 *
	 * @return void
	 */
	public function testScopeClaimAndViaAreStillReadWithoutAScopeField(): void {
		$aggregate = [
			'contributions' => [
				[
					'app' => 'procest',
					'label' => 'Procest',
					'collections' => [
						['id' => 'byClaim', 'kind' => 'inbox', 'register' => 'procest', 'schema' => 'message', 'scopeField' => '', 'scopeClaim' => 'kvk'],
						['id' => 'byVia', 'kind' => 'inbox', 'register' => 'procest', 'schema' => 'note', 'scopeField' => '', 'via' => ['register' => 'procest', 'schema' => 'rol']],
					],
				],
			],
		];

		$seen = [];
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			function (string $register, string $schema) use (&$seen) {
				$seen[] = $schema;
				return [['id' => $schema . '-1', 'receivedAt' => '2026-07-01T00:00:00Z']];
			}
		);

		$inboxReader = new PortalInboxReader($reader);
		$messages = $inboxReader->aggregateInbox(self::SUBJECT, $aggregate);

		sort($seen);
		$this->assertSame(['message', 'note'], $seen, 'scopeClaim and via must still be read');
		$this->assertCount(2, $messages);

	}//end testScopeClaimAndViaAreStillReadWithoutAScopeField()

}//end class
