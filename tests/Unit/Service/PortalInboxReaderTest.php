<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\Notifications\MessageBoxDeliveries;
use OCA\Portaliq\Service\PortalFileReader;
use OCA\Portaliq\Service\PortalInboxReader;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
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

				// Portaliq's own notices are always an inbox source; none here.
				if ($register === 'portaliq' && $schema === 'portalMessage') {
					return [];
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
	/**
	 * inbox-read-receipt-on-request T02: the receipt fields of Portaliq's own
	 * message reach the inbox row unchanged.
	 *
	 * @spec openspec/changes/inbox-read-receipt-on-request/tasks.md#t02
	 */
	public function testReceiptFieldsReachTheInboxRow(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn([
			['id' => 'm1', 'subject' => 'Brief', 'receivedAt' => '2026-10-07T00:00:00Z', 'read' => true, 'readReceiptRequested' => true, 'readAt' => '2026-10-08T09:14:00Z', 'sendingRef' => 'brief-5b'],
		]);

		$messages = (new PortalInboxReader($reader))->aggregateInbox(self::SUBJECT, ['contributions' => []]);

		$this->assertTrue($messages[0]['readReceiptRequested']);
		$this->assertSame('2026-10-08T09:14:00Z', $messages[0]['readAt']);
		$this->assertSame('brief-5b', $messages[0]['sendingRef']);
	}//end testReceiptFieldsReachTheInboxRow()

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
	 * A message whose `visibleFromField` lies ahead stays out of the inbox
	 * until that moment has passed (site-school-blocks).
	 *
	 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-collection-may-keep-a-row-back-until-its-moment-has-passed
	 */
	public function testAMessageWaitsForItsVisibleFromMoment(): void {
		$aggregate = [
			'contributions' => [
				[
					'app' => 'learniq',
					'label' => 'Learniq',
					'collections' => [
						['id' => 'cijfers', 'kind' => 'inbox', 'register' => 'learniq', 'schema' => 'grade-notice', 'scopeField' => 'learnerRef', 'visibleFromField' => 'visibleFrom'],
					],
				],
			],
		];

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			static fn (string $register): array => ($register === 'learniq') ? [
				['id' => 'past', 'subject' => 'Cijfer Nederlands', 'visibleFrom' => '2000-01-01T08:00:00+00:00', 'receivedAt' => '2000-01-01T08:00:00Z'],
				['id' => 'future', 'subject' => 'Cijfer wiskunde', 'visibleFrom' => '2999-01-01T08:00:00+00:00', 'receivedAt' => '2026-10-02T08:00:00Z'],
			] : []
		);

		$ids = array_column((new PortalInboxReader($reader))->aggregateInbox(self::SUBJECT, $aggregate), 'id');

		$this->assertContains('past', $ids);
		$this->assertNotContains('future', $ids);
	}//end testAMessageWaitsForItsVisibleFromMoment()

	/**
	 * No `kind: inbox` collection anywhere: only portaliq's own notices are
	 * read, so a resident with none has an empty inbox (not an error), and a
	 * non-inbox collection is never read.
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
		$reader->expects($this->once())->method('readCollection')
			->with('portaliq', 'portalMessage', 'subjectRef', 's1')
			->willReturn([]);

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

		// Only portaliq's own, subjectRef-scoped notices may be read; the
		// unscoped rogue collection never is.
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->expects($this->once())
			->method('readCollection')
			->with('portaliq', 'portalMessage', 'subjectRef', 's1')
			->willReturn([]);

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
		$this->assertSame(['message', 'note', 'portalMessage'], $seen, 'scopeClaim and via must still be read');
		$this->assertCount(3, $messages);

	}//end testScopeClaimAndViaAreStillReadWithoutAScopeField()


	/**
	 * A message whose message box send was delivered or read says so; a
	 * pending, failed or simulated send shows nothing, and an organisation
	 * without the channel reads no log at all (inbox-berichtenbox-channel,
	 * REQ-MBC-004).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-the-resident-sees-only-a-real-delivery-req-mbc-004
	 */
	public function testOnlyADeliveredMessageBoxSendIsShown(): void {
		$aggregate = ['contributions' => [[
			'app' => 'dossiq',
			'label' => 'Dossiq',
			'collections' => [['id' => 'berichten', 'kind' => 'inbox', 'register' => 'zaken', 'schema' => 'bericht', 'scopeField' => 'ontvanger', 'messageBox' => ['recipientProvider' => 'messageBoxRecipient']]],
		]]];

		$logReads = 0;
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef) use (&$logReads): array {
				if ($schema === 'bericht') {
					return [
						['id' => 'b1', 'subject' => 'Besluit', 'receivedAt' => '2026-09-04'],
						['@self' => ['id' => 'b2'], 'subject' => 'Gesimuleerd', 'receivedAt' => '2026-09-03'],
						['id' => 'b3', 'subject' => 'Mislukt', 'receivedAt' => '2026-09-02'],
						['uuid' => 'b4', 'subject' => 'Gelezen', 'receivedAt' => '2026-09-01'],
					];
				}

				if ($schema === 'portalAccount') {
					return ($scopeField === 'subjectRef' && $subjectRef === 's1') ? [['@self' => ['id' => 'account-1'], 'subjectRef' => 's1']] : [];
				}

				if ($schema === 'portalNotification') {
					++$logReads;
					$this->assertSame(['accountRef', 'account-1'], [$scopeField, $subjectRef], 'only the resident\'s own log');
					$link = static fn (string $id): array => ['app' => 'dossiq', 'collection' => 'berichten', 'id' => $id];
					return [
						['channel' => 'messageBox', 'status' => 'delivered', 'recordLink' => $link('b1')],
						['channel' => 'messageBox', 'status' => 'simulated', 'recordLink' => $link('b2')],
						['channel' => 'messageBox', 'status' => 'failed', 'recordLink' => $link('b3')],
						['channel' => 'email', 'status' => 'sent', 'recordLink' => $link('b3')],
						['channel' => 'messageBox', 'status' => 'read', 'recordLink' => $link('b4')],
						['channel' => 'messageBox', 'status' => 'delivered', 'recordLink' => ['app' => 'other', 'collection' => 'berichten', 'id' => 'b3']],
					];
				}

				return [];
			}
		);

		$offered = $this->createMock(PortalOrganisationConfigService::class);
		$offered->method('messageBox')->willReturn(['sourceId' => 'src', 'label' => 'MijnOverheid Berichtenbox']);
		$rows = (new PortalInboxReader($reader, null, new MessageBoxDeliveries(reader: $reader, orgConfig: $offered)))->aggregateInbox(self::SUBJECT, $aggregate);

		$shown = [];
		foreach ($rows as $row) {
			$shown[(string)$row['subject']] = ($row['_deliveries'] ?? []);
		}

		$line = [['channel' => 'messageBox', 'label' => 'MijnOverheid Berichtenbox']];
		$this->assertSame(['Besluit' => $line, 'Gesimuleerd' => [], 'Mislukt' => [], 'Gelezen' => $line], $shown);

		$logReads = 0;
		$notOffered = $this->createMock(PortalOrganisationConfigService::class);
		$notOffered->method('messageBox')->willReturn(null);
		$rows = (new PortalInboxReader($reader, null, new MessageBoxDeliveries(reader: $reader, orgConfig: $notOffered)))->aggregateInbox(self::SUBJECT, $aggregate);
		$this->assertSame(0, $logReads, 'no channel, no log read');
		$this->assertArrayNotHasKey('_deliveries', $rows[0]);
	}//end testOnlyADeliveredMessageBoxSendIsShown()

	/**
	 * A resident sees the notices portaliq writes itself (an answered question,
	 * a matched saved search, a published decision, a receipt) even when no
	 * contribution declares a `kind: inbox` collection over `portalMessage`.
	 * They are read through the same scoped reader as every inbox source: on
	 * `subjectRef`, with the subject's own reference and organisation. A
	 * contributed inbox source (dossiq) still merges alongside them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbox-shows-portal-messages/specs/portal-notifications-and-preferences/spec.md#requirement-portaliqs-own-notices-reach-the-residents-inbox-req-nap-009
	 */
	public function testAResidentSeesTheirOwnPortalMessagesAlongsideAContributedInbox(): void {
		$aggregate = ['contributions' => [[
			'app' => 'dossiq',
			'label' => 'Dossiq',
			'collections' => [['id' => 'portaalBericht', 'kind' => 'inbox', 'register' => 'zaken', 'schema' => 'portaalBericht', 'scopeField' => 'ontvanger']],
		]]];

		$calls = [];
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation = '') use (&$calls): array {
				$calls[] = [$register, $schema, $scopeField, $subjectRef, $organisation];
				if ($schema === 'portaalBericht') {
					return [['id' => 'd1', 'subject' => 'Uw zaak', 'receivedAt' => '2026-09-01T00:00:00Z']];
				}

				if ($register === 'portaliq' && $schema === 'portalMessage') {
					return [[
						'id' => 'm1',
						'subject' => 'Uw vraag is beantwoord',
						'body' => 'Bekijk het antwoord.',
						'read' => false,
						'receivedAt' => '2026-10-01T09:00:00Z',
						'recordLink' => ['app' => 'pipelinq', 'collection' => 'myQuestions', 'id' => 't1'],
					]];
				}

				return [];
			}
		);

		$messages = (new PortalInboxReader($reader))->aggregateInbox(self::SUBJECT, $aggregate);

		$this->assertContains(['portaliq', 'portalMessage', 'subjectRef', 's1', 'org-1'], $calls, 'read on the subject\'s own reference and organisation');
		$this->assertSame(['m1', 'd1'], array_column($messages, 'id'));
		$this->assertSame('Uw vraag is beantwoord', $messages[0]['subject']);
		$this->assertSame('Bekijk het antwoord.', $messages[0]['body']);
		$this->assertFalse($messages[0]['read']);
		$this->assertSame('2026-10-01T09:00:00Z', $messages[0]['receivedAt']);
		$this->assertSame(['app' => 'pipelinq', 'collection' => 'myQuestions', 'id' => 't1'], $messages[0]['recordLink']);
		$this->assertSame(
			['appId' => 'portaliq', 'label' => '', 'register' => 'portaliq', 'schema' => 'portalMessage', 'collection' => 'portalMessages', 'deletable' => true],
			$messages[0]['_source']
		);
		$this->assertSame('dossiq', $messages[1]['_source']['appId']);
		$this->assertSame(2, (new PortalInboxReader($reader))->unreadCount(self::SUBJECT, $aggregate), 'the unread notice counts');
	}//end testAResidentSeesTheirOwnPortalMessagesAlongsideAContributedInbox()

	/**
	 * Each row says whether the resident may delete it: portaliq's own
	 * notices always, an app's inbox only when it declares `deletable: true`.
	 *
	 * @spec openspec/changes/inbox-delete-own-messages/specs/portal-notifications-and-preferences/spec.md#requirement-a-resident-can-delete-their-own-inbox-messages
	 */
	public function testEachRowSaysWhetherTheResidentMayDeleteIt(): void {
		$aggregate = ['contributions' => [[
			'app' => 'learniq',
			'label' => 'School',
			'collections' => [
				['id' => 'meldingen', 'kind' => 'inbox', 'register' => 'learniq', 'schema' => 'notice', 'scopeField' => 'guardianRef', 'deletable' => true],
				['id' => 'cijfers', 'kind' => 'inbox', 'register' => 'learniq', 'schema' => 'grade', 'scopeField' => 'guardianRef', 'deletable' => 'yes'],
			],
		]]];

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			fn (string $register, string $schema): array => [['id' => $schema . '-1', 'subject' => $schema, 'receivedAt' => '2026-10-0' . strlen($schema) . 'T09:00:00Z']]
		);

		$sources = [];
		foreach ((new PortalInboxReader($reader))->aggregateInbox(self::SUBJECT, $aggregate) as $row) {
			$sources[$row['id']] = ($row['_source']['deletable'] ?? false);
		}

		$this->assertSame(['notice-1' => true, 'grade-1' => false, 'portalMessage-1' => true], [
			'notice-1' => $sources['notice-1'],
			'grade-1' => $sources['grade-1'],
			'portalMessage-1' => $sources['portalMessage-1'],
		]);
	}//end testEachRowSaysWhetherTheResidentMayDeleteIt()

	/**
	 * Another subject's notice never appears: the read is scoped on the
	 * bearer's own subjectRef, never a wider one, so a row the reader keeps for
	 * someone else is never asked for.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbox-shows-portal-messages/specs/portal-notifications-and-preferences/spec.md#requirement-portaliqs-own-notices-reach-the-residents-inbox-req-nap-009
	 */
	public function testAnotherSubjectsPortalMessageNeverAppears(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			static function (string $register, string $schema, string $scopeField, string $subjectRef): array {
				// The reader keeps one notice for s2 only, as OpenRegister would.
				if ($schema === 'portalMessage' && $scopeField === 'subjectRef' && $subjectRef === 's2') {
					return [['id' => 'not-yours', 'subject' => 'Voor iemand anders', 'subjectRef' => 's2']];
				}

				return [];
			}
		);

		$this->assertSame([], (new PortalInboxReader($reader))->aggregateInbox(self::SUBJECT, ['contributions' => []]));
		$this->assertSame(0, (new PortalInboxReader($reader))->unreadCount(self::SUBJECT, ['contributions' => []]));
	}//end testAnotherSubjectsPortalMessageNeverAppears()

	/**
	 * A portal that already declares a `kind: inbox` collection over
	 * `portalMessage` shows each notice once, under the declared collection
	 * (whose id mark-read already addresses).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbox-shows-portal-messages/specs/portal-notifications-and-preferences/spec.md#requirement-portaliqs-own-notices-reach-the-residents-inbox-req-nap-009
	 */
	public function testADeclaredPortalMessageInboxShowsEachNoticeOnce(): void {
		$aggregate = ['contributions' => [[
			'app' => 'portaliq',
			'label' => 'Voorbeeld',
			'collections' => [['id' => 'inbox', 'kind' => 'inbox', 'register' => 'portaliq', 'schema' => 'portalMessage', 'scopeField' => 'subjectRef']],
		]]];

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn(
			[
				['@self' => ['id' => 'm1'], 'subject' => 'Een', 'receivedAt' => '2026-09-02'],
				['uuid' => 'm2', 'subject' => 'Twee', 'receivedAt' => '2026-09-01'],
				['subject' => 'Zonder id'],
			]
		);

		$messages = (new PortalInboxReader($reader))->aggregateInbox(self::SUBJECT, $aggregate);

		$this->assertSame(['Een', 'Twee', 'Zonder id'], array_column($messages, 'subject'));
		$this->assertSame(['inbox', 'inbox', 'inbox'], array_column(array_column($messages, '_source'), 'collection'));
	}//end testADeclaredPortalMessageInboxShowsEachNoticeOnce()

	/**
	 * A dossiq-shaped inbox: the collection names its own fields, and the row
	 * the screen gets carries the text, the date and the unread state.
	 *
	 * @param array<int, array<string, mixed>> $rows The dossiq rows.
	 * @param array<string, mixed>             $extra Extra collection keys.
	 *
	 * @return array{0: array<string, mixed>, 1: PortalObjectReader}
	 */
	private function dossiqInbox(array $rows, array $extra = []): array {
		$aggregate = ['contributions' => [[
			'app' => 'dossiq',
			'label' => 'Zaken',
			'collections' => [array_merge(
				[
					'id' => 'berichten',
					'kind' => 'inbox',
					'register' => 'dossiq',
					'schema' => 'portaalBericht',
					'scopeField' => 'recipientRef',
					'messageFields' => ['body' => 'content', 'receivedAt' => 'sentAt', 'readAt' => 'readByRecipientAt', 'attachments' => 'attachments'],
				],
				$extra
			)],
		]]];

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			static fn (string $register, string $schema): array => ($schema === 'portalMessage' ? [['id' => 'own', 'subject' => 'Ontvangen', 'receivedAt' => '2026-10-01T09:00:00Z', 'read' => false]] : $rows)
		);

		return [$aggregate, $reader];
	}//end dossiqInbox()

	/**
	 * portaliq#702: a dossiq message shows its text and date.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbox-reads-each-apps-message-fields/specs/supplier-portal/spec.md#requirement-an-inbox-collection-names-its-own-message-fields-req-imf-001
	 */
	public function testDeclaredMessageFieldsFillTheInboxRow(): void {
		[$aggregate, $reader] = $this->dossiqInbox([
			['id' => 'b1', 'subject' => 'Besluit', 'content' => 'Uw verzoek is toegekend.', 'sentAt' => '2026-10-02T10:00:00Z', 'attachments' => ['f1']],
		]);

		$messages = (new PortalInboxReader($reader))->aggregateInbox(self::SUBJECT, $aggregate);
		$dossiq = $messages[0];

		$this->assertSame('b1', $dossiq['id']);
		$this->assertSame('Uw verzoek is toegekend.', $dossiq['body']);
		$this->assertSame('2026-10-02T10:00:00Z', $dossiq['receivedAt']);
		$this->assertSame(['f1'], $dossiq['attachments']);
		$this->assertFalse($dossiq['read']);
	}//end testDeclaredMessageFieldsFillTheInboxRow()

	/**
	 * A read date reads as read, and the unread count follows it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbox-reads-each-apps-message-fields/specs/supplier-portal/spec.md#requirement-an-inbox-collection-names-its-own-message-fields-req-imf-001
	 */
	public function testReadAtMarksARowRead(): void {
		[$aggregate, $reader] = $this->dossiqInbox([
			['id' => 'b1', 'subject' => 'Gelezen', 'sentAt' => '2026-10-02T10:00:00Z', 'readByRecipientAt' => '2026-10-02T11:00:00Z'],
			['id' => 'b2', 'subject' => 'Nieuw', 'sentAt' => '2026-10-02T12:00:00Z'],
		]);

		$inbox = new PortalInboxReader($reader);
		$messages = $inbox->aggregateInbox(self::SUBJECT, $aggregate);
		$read = array_column($messages, 'read', 'id');

		$this->assertTrue($read['b1']);
		$this->assertFalse($read['b2']);
		// b2 and portaliq's own notice are unread.
		$this->assertSame(2, $inbox->unreadCount(self::SUBJECT, $aggregate));
	}//end testReadAtMarksARowRead()

	/**
	 * A dossiq message sorts by its own date, not last.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbox-reads-each-apps-message-fields/specs/supplier-portal/spec.md#requirement-an-inbox-collection-names-its-own-message-fields-req-imf-001
	 */
	public function testMappedRowsSortByTheirOwnDate(): void {
		[$aggregate, $reader] = $this->dossiqInbox([
			['id' => 'older', 'subject' => 'Oud', 'sentAt' => '2026-09-01T10:00:00Z'],
			['id' => 'newer', 'subject' => 'Nieuw', 'sentAt' => '2026-10-02T10:00:00Z'],
		]);

		$messages = (new PortalInboxReader($reader))->aggregateInbox(self::SUBJECT, $aggregate);

		$this->assertSame(['newer', 'own', 'older'], array_column($messages, 'id'));
	}//end testMappedRowsSortByTheirOwnDate()

	/**
	 * inbox-reply-with-attachments REQ-IRA-001: a message from a collection that
	 * declares a reply carries what the screen needs to offer it; a message from
	 * a collection without one, and portaliq's own notices, carry nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbox-reply-with-attachments/tasks.md#t05
	 */
	public function testOnlyAMessageWithAReplyDeclarationCarriesIt(): void {
		$row = ['id' => 'b1', 'subject' => 'Besluit', 'sentAt' => '2026-10-02T10:00:00Z'];
		[$aggregate, $reader] = $this->dossiqInbox([$row], ['reply' => ['action' => 'replyToMessage', 'carry' => ['caseId' => 'caseId'], 'subjectFrom' => 'subject']]);
		$aggregate['contributions'][0]['actions'] = [[
			'id' => 'replyToMessage', 'type' => 'create', 'label' => 'Antwoorden', 'register' => 'dossiq', 'schema' => 'portaalBericht',
			'fields' => ['subject', 'content', 'attachments', 'caseId'], 'fieldConfigs' => ['attachments' => ['type' => 'file', 'multiple' => true]],
			'defaults' => ['direction' => 'citizen_to_handler'],
		]];

		$byId = array_column((new PortalInboxReader($reader))->aggregateInbox(self::SUBJECT, $aggregate), null, 'id');

		$reply = $byId['b1']['_source']['reply'];
		$this->assertSame('replyToMessage', $reply['action']['id']);
		$this->assertSame(['subject', 'content', 'attachments', 'caseId'], $reply['action']['fields']);
		$this->assertSame('file', ((array)$reply['action']['fieldConfigs'])['attachments']['type']);
		$this->assertSame(['caseId'], $reply['carried']);
		$this->assertSame('subject', $reply['subjectFrom']);
		$this->assertArrayNotHasKey('defaults', $reply['action'], 'the server keeps its defaults');
		$this->assertArrayNotHasKey('reply', $byId['own']['_source'], 'portaliq notices cannot be answered');

		[$plain, $plainReader] = $this->dossiqInbox([$row]);
		$this->assertArrayNotHasKey('reply', array_column((new PortalInboxReader($plainReader))->aggregateInbox(self::SUBJECT, $plain), null, 'id')['b1']['_source']);

		[$orphan, $orphanReader] = $this->dossiqInbox([$row], ['reply' => ['action' => 'gone']]);
		$this->assertArrayNotHasKey('reply', array_column((new PortalInboxReader($orphanReader))->aggregateInbox(self::SUBJECT, $orphan), null, 'id')['b1']['_source']);

	}//end testOnlyAMessageWithAReplyDeclarationCarriesIt()

	/**
	 * Files are listed only for a collection that declares `filesDownload`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbox-reply-with-attachments/specs/portal-inbox-reply/spec.md#requirement-files-that-came-with-a-message-open-req-ira-004
	 */
	public function testFilesOnlyWhenDeclared(): void {
		$row = ['id' => 'b1', 'subject' => 'Besluit', 'sentAt' => '2026-10-02T10:00:00Z'];
		$listed = [['id' => 7, 'name' => 'besluit.pdf', 'size' => 1200]];

		$files = $this->createMock(PortalFileReader::class);
		$files->expects($this->once())->method('listFiles')
			->with('dossiq', 'portaalBericht', 'b1')
			->willReturn($listed);

		[$aggregate, $reader] = $this->dossiqInbox([$row], ['filesDownload' => true]);
		$messages = (new PortalInboxReader($reader, null, null, $files))->aggregateInbox(self::SUBJECT, $aggregate);
		$byId = array_column($messages, null, 'id');
		$this->assertSame($listed, $byId['b1']['_files']);
		$this->assertArrayNotHasKey('_files', $byId['own'], 'portaliq notices list no files');

		$never = $this->createMock(PortalFileReader::class);
		$never->expects($this->never())->method('listFiles');
		[$plain, $plainReader] = $this->dossiqInbox([$row]);
		$messages = (new PortalInboxReader($plainReader, null, null, $never))->aggregateInbox(self::SUBJECT, $plain);
		$this->assertArrayNotHasKey('_files', array_column($messages, null, 'id')['b1']);
	}//end testFilesOnlyWhenDeclared()
}//end class
