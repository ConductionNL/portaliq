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
}//end class
