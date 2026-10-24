<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\CollectionConfigNormaliser;
use OCA\Portaliq\Contribution\InboxMessageFields;
use OCA\Portaliq\Contribution\ManifestValueNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * An inbox collection names which of its own fields carry the inbox's
 * subject, text, date, read date and attachments (portaliq#702). dossiq keeps
 * its message text in `content` and its date in `sentAt`; without the map the
 * inbox showed a subject line only.
 *
 * @spec openspec/changes/inbox-reads-each-apps-message-fields/specs/supplier-portal/spec.md#requirement-an-inbox-collection-names-its-own-message-fields-req-imf-001
 */
class InboxMessageFieldsTest extends TestCase {

	private const DOSSIQ = [
		'id' => 'berichten',
		'kind' => 'inbox',
		'messageFields' => [
			'body' => 'content',
			'receivedAt' => 'sentAt',
			'readAt' => 'readByRecipientAt',
			'attachments' => 'attachments',
		],
	];

	/**
	 * Plain field names on an inbox collection are kept; an unknown key is not.
	 *
	 * @return void
	 */
	public function testKeepsPlainFieldNamesOnAnInbox(): void {
		$declared = self::DOSSIQ;
		$declared['messageFields']['sender'] = 'senderName';

		$out = (new InboxMessageFields())->normalise(collection: $declared);

		$this->assertSame(self::DOSSIQ['messageFields'], $out['messageFields']);
	}//end testKeepsPlainFieldNamesOnAnInbox()

	/**
	 * A collection that is not an inbox loses the whole key; the rest stands.
	 *
	 * @return void
	 */
	public function testDropsItOnANonInboxCollection(): void {
		$normaliser = new InboxMessageFields();
		foreach ([
			['kind' => 'cases', 'messageFields' => ['body' => 'content']],
			['messageFields' => ['body' => 'content']],
			['kind' => 'inbox', 'messageFields' => 'content'],
			['kind' => 'inbox', 'messageFields' => ['body' => 'a.b', 'receivedAt' => ['x'], 'readAt' => '']],
		] as $collection) {
			$out = $normaliser->normalise(collection: $collection);
			$this->assertArrayNotHasKey('messageFields', $out, json_encode($collection));
		}

		$this->assertSame(['kind' => 'inbox'], $normaliser->normalise(collection: ['kind' => 'inbox']), 'no declaration, nothing added');
	}//end testDropsItOnANonInboxCollection()

	/**
	 * A malformed entry is dropped, the well-formed ones stand.
	 *
	 * @return void
	 */
	public function testDropsAMalformedEntry(): void {
		$out = (new InboxMessageFields())->normalise(collection: [
			'kind' => 'inbox',
			'messageFields' => ['body' => 'content', 'receivedAt' => 'sent at', 'readAt' => '1read'],
		]);

		$this->assertSame(['body' => 'content'], $out['messageFields']);
	}//end testDropsAMalformedEntry()

	/**
	 * The collection normaliser every contribution passes through applies it.
	 *
	 * @return void
	 */
	public function testTheCollectionNormaliserAppliesIt(): void {
		$collections = (new CollectionConfigNormaliser(values: new ManifestValueNormaliser()))->normaliseCollections(collections: [
			self::DOSSIQ,
			['id' => 'zaken', 'kind' => 'cases', 'messageFields' => ['body' => 'content']],
		]);

		$this->assertSame(self::DOSSIQ['messageFields'], $collections[0]['messageFields']);
		$this->assertArrayNotHasKey('messageFields', $collections[1]);
	}//end testTheCollectionNormaliserAppliesIt()

	/**
	 * The app's fields land under the inbox's names; a read date reads as read.
	 *
	 * @return void
	 */
	public function testApplyFillsTheInboxShape(): void {
		$fields = new InboxMessageFields();
		$row = $fields->apply(
			row: ['id' => 'b1', 'subject' => 'Uw aanvraag', 'content' => 'De tekst', 'sentAt' => '2026-10-02T10:00:00+00:00', 'attachments' => ['f1']],
			collection: self::DOSSIQ
		);

		$this->assertSame('De tekst', $row['body']);
		$this->assertSame('2026-10-02T10:00:00+00:00', $row['receivedAt']);
		$this->assertSame(['f1'], $row['attachments']);
		$this->assertFalse($row['read']);

		$read = $fields->apply(row: ['id' => 'b2', 'readByRecipientAt' => '2026-10-02T11:00:00+00:00'], collection: self::DOSSIQ);
		$this->assertTrue($read['read']);
		$this->assertArrayNotHasKey('body', $read, 'a field the row lacks adds nothing');
	}//end testApplyFillsTheInboxShape()

	/**
	 * Without a declaration the row is untouched.
	 *
	 * @return void
	 */
	public function testApplyWithoutADeclarationChangesNothing(): void {
		$row = ['id' => 'm1', 'body' => 'Tekst', 'read' => true];

		$this->assertSame($row, (new InboxMessageFields())->apply(row: $row, collection: ['kind' => 'inbox']));
	}//end testApplyWithoutADeclarationChangesNothing()

	/**
	 * Mark-read writes the named read date, else `read: true`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbox-reads-each-apps-message-fields/specs/supplier-portal/spec.md#requirement-mark-read-writes-the-collections-own-read-field-req-imf-002
	 */
	public function testReadPayloadWritesTheNamedField(): void {
		$fields = new InboxMessageFields();

		$this->assertSame(['readByRecipientAt' => '2026-10-02T12:00:00Z'], $fields->readPayload(collection: self::DOSSIQ, now: '2026-10-02T12:00:00Z'));
		$this->assertSame(['read' => true], $fields->readPayload(collection: ['kind' => 'inbox'], now: '2026-10-02T12:00:00Z'));
	}//end testReadPayloadWritesTheNamedField()

	private const PETRA = [
		'id' => 'berichten',
		'kind' => 'inbox',
		'senderRoleField' => 'senderJob',
		'aboutField' => 'recordTitle',
		'aboutLinkField' => 'recordUrl',
		'actionField' => 'cta',
		'tabField' => 'topic',
	];

	/**
	 * The five extra fields survive on an inbox collection through the shared normaliser.
	 *
	 * @spec openspec/changes/a-message-names-its-record-and-links-its-action/tasks.md#task-1
	 *
	 * @return void
	 */
	public function testTheExtraFieldsSurviveOnAnInbox(): void {
		$out = (new CollectionConfigNormaliser(values: new ManifestValueNormaliser()))->normaliseCollections(collections: [
			self::PETRA,
			['id' => 'zaken', 'kind' => 'cases', 'senderRoleField' => 'x', 'actionField' => 'y'],
			['id' => 'bad', 'kind' => 'inbox', 'senderRoleField' => 'a b', 'aboutField' => ['x'], 'tabField' => 'topic'],
		]);

		$this->assertSame('senderJob', $out[0]['senderRoleField']);
		$this->assertSame('cta', $out[0]['actionField']);
		$this->assertSame('topic', $out[0]['tabField']);
		$this->assertArrayNotHasKey('senderRoleField', $out[1]);
		$this->assertArrayNotHasKey('actionField', $out[1]);
		$this->assertArrayNotHasKey('senderRoleField', $out[2], 'a field name with a space is dropped');
		$this->assertArrayNotHasKey('aboutField', $out[2]);
		$this->assertSame('topic', $out[2]['tabField']);
	}//end testTheExtraFieldsSurviveOnAnInbox()

	/**
	 * The row carries role, about, link, tab and an in-portal action.
	 *
	 * @spec openspec/changes/a-message-names-its-record-and-links-its-action/tasks.md#task-1
	 *
	 * @return void
	 */
	public function testApplyCopiesTheExtraFields(): void {
		$row = (new InboxMessageFields())->apply(
			row: [
				'id' => 'm1',
				'senderJob' => 'Praktijkopleider, Bakker Techniek BV',
				'recordTitle' => 'Uren week 40',
				'recordUrl' => '/mijn/bpv-uren',
				'topic' => 'BPV',
				'cta' => ['label' => 'Uren aanpassen', 'href' => '/mijn/bpv-uren/2026-09-29'],
			],
			collection: self::PETRA
		);

		$this->assertSame('Praktijkopleider, Bakker Techniek BV', $row['senderRole']);
		$this->assertSame('Uren week 40', $row['about']);
		$this->assertSame('/mijn/bpv-uren', $row['aboutLink']);
		$this->assertSame('BPV', $row['tab']);
		$this->assertSame(['label' => 'Uren aanpassen', 'href' => '/mijn/bpv-uren/2026-09-29'], $row['action']);
	}//end testApplyCopiesTheExtraFields()

	/**
	 * An action that leaves the portal is not served.
	 *
	 * @spec openspec/changes/a-message-names-its-record-and-links-its-action/tasks.md#task-1
	 *
	 * @return void
	 */
	public function testAnActionOutsideThePortalIsNotServed(): void {
		$fields = new InboxMessageFields();
		foreach (['https://example.org/pay', '//example.org/pay', 'javascript:alert(1)', '/\\evil.example', ''] as $href) {
			$row = $fields->apply(row: ['cta' => ['label' => 'Betalen', 'href' => $href]], collection: self::PETRA);
			$this->assertArrayNotHasKey('action', $row, $href);
		}

		$row = $fields->apply(row: ['cta' => ['label' => '', 'href' => '/mijn']], collection: self::PETRA);
		$this->assertArrayNotHasKey('action', $row, 'an empty label is not a button');
		$this->assertNotNull(InboxMessageFields::sameSiteAction(action: ['label' => 'Open', 'href' => '#open=a/b/c']));
	}//end testAnActionOutsideThePortalIsNotServed()

	/**
	 * inbox-read-receipt-on-request T02: an app's inbox may name the field
	 * that says a receipt was asked.
	 *
	 * @spec openspec/changes/inbox-read-receipt-on-request/tasks.md#t02
	 */
	public function testKeepsTheReceiptRequestKey(): void {
		$out = (new InboxMessageFields())->normalise(['kind' => 'inbox', 'messageFields' => ['readReceiptRequested' => 'wantsReceipt', 'bad' => 'x']]);

		$this->assertSame(['readReceiptRequested' => 'wantsReceipt'], $out['messageFields']);
		$row = (new InboxMessageFields())->apply(['wantsReceipt' => true], $out);
		$this->assertTrue($row['readReceiptRequested']);
	}//end testKeepsTheReceiptRequestKey()

	/**
	 * T03: without a request only `read` is written, never a moment.
	 *
	 * @spec openspec/changes/inbox-read-receipt-on-request/tasks.md#t03
	 */
	public function testNoRequestWritesNoMoment(): void {
		$fields = new InboxMessageFields();

		$this->assertSame(['read' => true], $fields->readPayload([], '2026-10-08T09:14:00Z', ['readReceiptRequested' => false]));
		$this->assertSame(['read' => true], $fields->readPayload([], '2026-10-08T09:14:00Z', []));
	}//end testNoRequestWritesNoMoment()

	/**
	 * T03: the first open writes the moment, a later one keeps it.
	 *
	 * @spec openspec/changes/inbox-read-receipt-on-request/tasks.md#t03
	 */
	public function testFirstOpenWritesTheMomentAndALaterOneKeepsIt(): void {
		$fields = new InboxMessageFields();

		$this->assertSame(['read' => true, 'readAt' => '2026-10-08T09:14:00Z'], $fields->readPayload([], '2026-10-08T09:14:00Z', ['readReceiptRequested' => true]));
		$this->assertSame(['read' => true], $fields->readPayload([], '2026-10-08T11:02:00Z', ['readReceiptRequested' => true, 'readAt' => '2026-10-08T09:14:00Z']));
	}//end testFirstOpenWritesTheMomentAndALaterOneKeepsIt()

	/**
	 * T03: an app inbox writes its own read date once.
	 *
	 * @spec openspec/changes/inbox-read-receipt-on-request/tasks.md#t03
	 */
	public function testAppInboxKeepsAnExistingReadAt(): void {
		$fields     = new InboxMessageFields();
		$collection = ['messageFields' => ['readAt' => 'readByRecipientAt']];

		$this->assertSame(['readByRecipientAt' => 'NOW'], $fields->readPayload($collection, 'NOW', []));
		$this->assertSame([], $fields->readPayload($collection, 'LATER', ['readByRecipientAt' => 'NOW']));
	}//end testAppInboxKeepsAnExistingReadAt()
}
