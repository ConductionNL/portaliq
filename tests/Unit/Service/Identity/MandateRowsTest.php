<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use DateTimeImmutable;
use OCA\Portaliq\Service\Identity\ContactRows;
use OCA\Portaliq\Service\Identity\MandateDays;
use OCA\Portaliq\Service\Identity\MandateListing;
use OCA\Portaliq\Service\Identity\MandateParties;
use OCA\Portaliq\Service\Identity\MandateStore;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Reading and listing mandate and contact rows over a stubbed object reader.
 */
#[CoversClass(MandateStore::class)]
#[CoversClass(MandateListing::class)]
#[CoversClass(ContactRows::class)]
#[UsesClass(MandateParties::class)]
#[UsesClass(MandateDays::class)]
#[UsesClass(\OCA\Portaliq\Service\Identity\PortalContactService::class)]
class MandateRowsTest extends TestCase {
	private const NOW = '2026-05-10 12:00:00';

	/**
	 * A reader that answers by schema.
	 *
	 * @param array<string, array<int, mixed>> $bySchema Rows per schema.
	 *
	 * @return PortalObjectReader
	 */
	private function reader(array $bySchema): PortalObjectReader {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			static fn (string $register, string $schema): array => ($bySchema[$schema] ?? [])
		);

		return $reader;
	}//end reader()

	/**
	 * The store keeps only rows of the organisation and finds ids in any shape.
	 *
	 * @return void
	 */
	public function testStore(): void {
		$rows = [
			['uuid' => 'm1', 'organisation' => 'zuid'],
			['organisation' => 'other', 'uuid' => 'm2'],
			'junk',
			['organisation' => 'zuid', '@self' => ['id' => 'm3']],
			['organisation' => 'zuid', 'tokenHash' => hash('sha256', 'tok'), 'mandate' => ['x' => 1], 'id' => 7],
		];
		$writer = $this->createMock(PortalObjectWriter::class);
		$store  = new MandateStore($this->reader([MandateStore::MANDATES => $rows, MandateStore::INVITATIONS => $rows]), $writer);

		$this->assertSame([], $store->rows(MandateStore::MANDATES, ''));
		$this->assertCount(3, $store->rows(MandateStore::MANDATES, 'zuid'));
		$this->assertSame('m1', $store->idOf(['uuid' => 'm1']));
		$this->assertSame('7', $store->idOf(['id' => 7]));
		$this->assertSame('x', $store->idOf(['@self' => ['uuid' => 'x']]));
		$this->assertSame('', $store->idOf([]));

		$this->assertNull($store->mandate('', 'zuid'));
		$this->assertSame('m3', $store->idOf($store->mandate('m3', 'zuid')));
		$this->assertNull($store->mandate('nope', 'zuid'));

		$this->assertNull($store->invitationByToken(''));
		$this->assertSame('7', $store->idOf($store->invitationByToken('tok')));
		$this->assertNull($store->invitationByToken('other'));
	}//end testStore()

	/**
	 * Updating writes through the writer with the row's organisation.
	 *
	 * @return void
	 */
	public function testStoreUpdate(): void {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->once())->method('updateObject')->with(
			MandateStore::REGISTER,
			MandateStore::MANDATES,
			'organisation',
			'zuid',
			'zuid',
			'm1',
			['status' => 'revoked']
		);

		(new MandateStore($this->reader([]), $writer))->update(MandateStore::MANDATES, ['uuid' => 'm1', 'organisation' => 'zuid'], ['status' => 'revoked']);
	}//end testStoreUpdate()

	/**
	 * Given lists a party's mandates and open invitations; held lists live ones.
	 *
	 * @return void
	 */
	public function testListing(): void {
		$mandates = [
			['uuid' => 'm1', 'organisation' => 'zuid', 'onBehalfOf' => 'abc', 'holder' => 'kvk:12345678', 'label' => 'L', 'caseTypes' => ['a'], 'expiresAt' => '2030-01-01', 'status' => 'active'],
			['uuid' => 'm2', 'organisation' => 'zuid', 'onBehalfOf' => 'subject:abc', 'subjectRef' => 'h', 'expiresAt' => '2020-01-01'],
			['uuid' => 'm3', 'organisation' => 'zuid', 'onBehalfOf' => 'subject:abc', 'status' => 'revoked'],
			['uuid' => 'm4', 'organisation' => 'zuid', 'onBehalfOf' => 'subject:zzz'],
		];
		$invites = [
			['uuid' => 'i1', 'organisation' => 'zuid', 'onBehalfOf' => 'subject:abc', 'email' => 'a@b.nl', 'mandate' => ['label' => 'IL', 'caseTypes' => ['c'], 'expiresAt' => '2031-01-01']],
			['uuid' => 'i2', 'organisation' => 'zuid', 'onBehalfOf' => 'subject:abc', 'state' => 'accepted'],
			['uuid' => 'i3', 'organisation' => 'zuid', 'onBehalfOf' => 'subject:other', 'state' => 'opened'],
		];
		$store   = new MandateStore($this->reader([MandateStore::MANDATES => $mandates, MandateStore::INVITATIONS => $invites]), $this->createMock(PortalObjectWriter::class));
		$listing = new MandateListing($store, new MandateParties(), new MandateDays());
		$now     = new DateTimeImmutable(self::NOW);

		$given = $listing->given('subject:abc', 'zuid', $now);
		$this->assertSame(['m1', 'm2', 'i1'], array_column($given, 'id'));
		$this->assertSame(['active', 'expired', 'pending'], array_column($given, 'state'));
		$this->assertSame('kvk:12345678', $given[0]['holder']);
		$this->assertSame('IL', $given[2]['label']);
		$this->assertSame(['c'], $given[2]['caseTypes']);

		$held = $listing->held(['kvk:12345678', 'subject:h'], 'zuid', $now);
		$this->assertSame(['m1'], array_column($held, 'id'));
		$this->assertSame('subject:abc', $held[0]['onBehalfOf']);
		$this->assertSame([], $listing->held(['subject:nobody'], 'zuid', $now));
	}//end testListing()

	/**
	 * Contact rows: own lookups, token and account lookups, and the bookkeeping helpers.
	 *
	 * @return void
	 */
	public function testContactRows(): void {
		$contacts = [
			['id' => 'c1', 'state' => 'invited', 'email' => 'a@b.nl', 'sentAt' => '2026-05-10T09:00', 'expiresAt' => '2026-06-01', 'displayName' => 'A'],
			['uuid' => 'c2', 'state' => 'requested', 'email' => 'x@y.nl', 'sentAt' => '2026-05-10T09:00', 'contactRef' => 'ref-9'],
			['@self' => ['id' => 'c3'], 'state' => 'revoked', 'email' => 'q@y.nl'],
		];
		$accounts = [
			['status' => 'inactive', 'subjectRef' => 's1', 'email' => 'a@b.nl'],
			['status' => 'active', 'subjectRef' => '', 'email' => 'a@b.nl'],
			['status' => 'active', 'subjectRef' => 's2', 'email' => 'A@b.nl'],
		];
		$rows = new ContactRows($this->reader(['portalContact' => $contacts, 'portalAccount' => $accounts]));
		$now  = new DateTimeImmutable(self::NOW);
		$me   = ['subjectRef' => 'me', 'organisation' => 'zuid'];

		$this->assertNull($rows->ownRow($me, '', ['invited']));
		$this->assertSame('c1', $rows->idOf($rows->ownRow($me, 'c1', ['invited'])));
		$this->assertNull($rows->ownRow($me, 'c1', ['approved']));
		$this->assertNull($rows->ownRow($me, 'zzz', ['invited']));
		$this->assertSame([], $rows->rowsOf('', 'zuid'));
		$this->assertCount(3, $rows->rowsOf('me', 'zuid'));

		$this->assertSame('s2', $rows->accountByEmail('a@b.nl', 'zuid')['subjectRef'] ?? null);
		$this->assertNull($rows->accountByEmail('nobody@b.nl', 'zuid'));
		$this->assertSame('c1', $rows->idOf((array)$rows->byToken('t', 'zuid')));

		$this->assertSame(1, $rows->sentToday($contacts, $now));
		$this->assertTrue($rows->alreadyOpenOrLinked($contacts, 'a@b.nl', null));
		$this->assertTrue($rows->alreadyOpenOrLinked($contacts, 'new@b.nl', ['subjectRef' => 'ref-9']));
		$this->assertFalse($rows->alreadyOpenOrLinked($contacts, 'q@y.nl', ['subjectRef' => 'none']));

		$this->assertTrue($rows->expired([], $now));
		$this->assertTrue($rows->expired(['expiresAt' => '2026-05-01'], $now));
		$this->assertFalse($rows->expired(['expiresAt' => '2026-06-01'], $now));

		$shown = $rows->shown($contacts[0]);
		$this->assertSame(['id' => 'c1', 'displayName' => 'A', 'line' => '', 'role' => 'contact', 'state' => 'invited', 'email' => 'a@b.nl', 'message' => '', 'sentAt' => '2026-05-10T09:00', 'expiresAt' => '2026-06-01'], $shown);
		$this->assertSame('c2', $rows->idOf($contacts[1]));
		$this->assertSame('c3', $rows->idOf($contacts[2]));
	}//end testContactRows()
}//end class
