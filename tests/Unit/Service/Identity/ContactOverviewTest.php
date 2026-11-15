<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\Identity\ContactOverview;
use OCA\Portaliq\Service\Identity\ContactRows;
use PHPUnit\Framework\TestCase;

/**
 * own-contacts-and-invitations: the overview sorts a resident's contact rows
 * into the three lists a portal shows — incoming requests, outgoing
 * invitations, and approved contacts — and counts the approved ones by role.
 * A state nobody declared falls into none of them.
 *
 * @covers \OCA\Portaliq\Service\Identity\ContactOverview
 *
 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
 */
class ContactOverviewTest extends TestCase {

	/**
	 * A ContactRows double whose `shown` and `idOf` are the real reductions.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows it answers with.
	 *
	 * @return ContactRows
	 */
	private function rowsReturning(array $rows): ContactRows {
		$double = $this->createMock(ContactRows::class);
		$double->method('rowsOf')->willReturn($rows);
		$double->method('idOf')->willReturnCallback(
			static fn (array $row): string => (string)($row['id'] ?? '')
		);
		$double->method('shown')->willReturnCallback(
			static fn (array $row): array => [
				'id'    => (string)($row['id'] ?? ''),
				'role'  => (string)($row['role'] ?? 'contact'),
				'state' => (string)($row['state'] ?? ''),
			]
		);

		return $double;

	}//end rowsReturning()

	public function testTheOverviewSortsRowsByStateAndCountsApprovedByRole(): void {
		$overview = (new ContactOverview(rows: $this->rowsReturning([
			['id' => 'a', 'state' => 'requested'],
			['id' => 'b', 'state' => 'invited'],
			['id' => 'c', 'state' => 'declined'],
			['id' => 'd', 'state' => 'approved', 'role' => 'begeleider'],
			['id' => 'e', 'state' => 'approved', 'role' => 'contact'],
			['id' => 'f', 'state' => 'approved', 'role' => 'organisatie'],
			// A role the counter does not know is counted in `all` only.
			['id' => 'g', 'state' => 'approved', 'role' => 'onbekend'],
			['id' => 'h', 'state' => 'nonsense'],
		])))->overview(owner: 'owner-1', organisation: 'acme');

		$this->assertSame(['a'], array_column($overview['incoming'], 'id'));
		$this->assertSame(['b', 'c'], array_column($overview['outgoing'], 'id'));
		$this->assertSame(['d', 'e', 'f', 'g'], array_column($overview['contacts'], 'id'));
		$this->assertSame(
			['all' => 4, 'begeleider' => 1, 'contact' => 1, 'organisatie' => 1],
			$overview['counts']
		);

	}//end testTheOverviewSortsRowsByStateAndCountsApprovedByRole()

	public function testAnEmptyOwnerYieldsTheEmptyOverviewShape(): void {
		$overview = (new ContactOverview(rows: $this->rowsReturning([])))
			->overview(owner: 'owner-1', organisation: 'acme');

		$this->assertSame([], $overview['incoming']);
		$this->assertSame([], $overview['outgoing']);
		$this->assertSame([], $overview['contacts']);
		$this->assertSame(['all' => 0, 'begeleider' => 0, 'contact' => 0, 'organisatie' => 0], $overview['counts']);

	}//end testAnEmptyOwnerYieldsTheEmptyOverviewShape()

	public function testApprovedContactsKeepsOnlyApprovedRowsThatNameAContactRef(): void {
		$rows = $this->rowsReturning([
			['id' => 'a', 'state' => 'approved', 'contactRef' => 'ref-a', 'displayName' => 'Ann'],
			// Approved but without a contact reference: not a usable contact.
			['id' => 'b', 'state' => 'approved', 'contactRef' => ''],
			['id' => 'c', 'state' => 'requested', 'contactRef' => 'ref-c'],
		]);
		$overview = new ContactOverview(rows: $rows);

		$contacts = $overview->approvedContacts(owner: 'owner-1', organisation: 'acme');

		$this->assertSame([['id' => 'a', 'ref' => 'ref-a', 'displayName' => 'Ann']], $contacts);

	}//end testApprovedContactsKeepsOnlyApprovedRowsThatNameAContactRef()

	public function testApprovedContactFindsOneByIdAndRefusesAnEmptyId(): void {
		$overview = new ContactOverview(rows: $this->rowsReturning([
			['id' => 'a', 'state' => 'approved', 'contactRef' => 'ref-a', 'displayName' => 'Ann'],
		]));

		$this->assertSame('ref-a', $overview->approvedContact(owner: 'o', organisation: 'acme', id: 'a')['ref']);
		$this->assertNull($overview->approvedContact(owner: 'o', organisation: 'acme', id: 'missing'));
		$this->assertNull($overview->approvedContact(owner: 'o', organisation: 'acme', id: ''));

	}//end testApprovedContactFindsOneByIdAndRefusesAnEmptyId()

	public function testApprovedRefsAndNamesByRefReadOnlyTheUsableApprovedRows(): void {
		$overview = new ContactOverview(rows: $this->rowsReturning([
			['id' => 'a', 'state' => 'approved', 'contactRef' => 'ref-a', 'displayName' => 'Ann'],
			['id' => 'b', 'state' => 'approved', 'contactRef' => 'ref-b', 'displayName' => ''],
			['id' => 'c', 'state' => 'approved', 'contactRef' => ''],
			['id' => 'd', 'state' => 'invited', 'contactRef' => 'ref-d', 'displayName' => 'Dan'],
		]));

		$this->assertSame(['ref-a', 'ref-b'], $overview->approvedRefs(owner: 'o', organisation: 'acme'));
		// namesByRef keeps only rows that carry both a ref and a display name,
		// for any state.
		$this->assertSame(['ref-a' => 'Ann', 'ref-d' => 'Dan'], $overview->namesByRef(owner: 'o', organisation: 'acme'));

	}//end testApprovedRefsAndNamesByRefReadOnlyTheUsableApprovedRows()
}//end class
