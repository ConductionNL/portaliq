<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Messaging;

use OCA\Portaliq\Service\Messaging\InAppMessagingLeaf;
use OCA\Portaliq\Service\Messaging\MessageStore;
use OCA\Portaliq\Service\Messaging\MessageThreadAccessGuard;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/guardian-direct-messages/specs/guardian-direct-messaging/spec.md#requirement-a-guardian-may-start-a-direct-thread-with-a-teacher-who-teaches-their-childs-group
 */
class InAppMessagingLeafTest extends TestCase {

	public function testCreateThreadRefusesAStaffMemberOutsideTheGuardiansReach(): void {
		$access = $this->createMock(MessageThreadAccessGuard::class);
		$access->method('canCreateDirect')->willReturn(false);

		$store = $this->createMock(MessageStore::class);
		$store->expects($this->never())->method('save');

		$leaf = new InAppMessagingLeaf($store, $access);
		$id = $leaf->createThread('direct', ['guardian-1', 'staff-4c'], null, 'guardian-1');

		$this->assertNull($id);
	}//end testCreateThreadRefusesAStaffMemberOutsideTheGuardiansReach()

	public function testCreateDirectThreadSucceedsWhenAllowed(): void {
		$access = $this->createMock(MessageThreadAccessGuard::class);
		$access->method('canCreateDirect')->willReturn(true);

		$store = $this->createMock(MessageStore::class);
		$store->expects($this->once())->method('save')->with('messageThread', $this->callback(fn (array $o): bool => $o['kind'] === 'direct'))->willReturn('thread-1');

		$leaf = new InAppMessagingLeaf($store, $access);
		$id = $leaf->createThread('direct', ['guardian-1', 'staff-5a'], null, 'guardian-1');

		$this->assertSame('thread-1', $id);
	}//end testCreateDirectThreadSucceedsWhenAllowed()

	public function testOutOfGroupGuardianCannotReadOrPost(): void {
		$access = $this->createMock(MessageThreadAccessGuard::class);
		$access->method('isParticipant')->willReturn(false);

		$store = $this->createMock(MessageStore::class);
		$store->method('findAll')->willReturn([['id' => 'thread-1', 'kind' => 'group', 'groupRef' => 'groep-5a']]);
		$store->method('rowId')->willReturn('thread-1');
		$store->expects($this->never())->method('save');

		$leaf = new InAppMessagingLeaf($store, $access);

		$this->assertNull($leaf->listMessages('thread-1', 'guardian-outsider', false));
		$this->assertFalse($leaf->postMessage('thread-1', 'guardian-outsider', false, 'hi'));
	}//end testOutOfGroupGuardianCannotReadOrPost()

	public function testNonParticipantCannotPostIntoADirectThread(): void {
		$access = $this->createMock(MessageThreadAccessGuard::class);
		$access->method('isParticipant')->willReturn(false);

		$store = $this->createMock(MessageStore::class);
		$store->method('findAll')->willReturn([['id' => 'thread-1', 'kind' => 'direct', 'participantRefs' => ['guardian-1', 'staff-1']]]);
		$store->method('rowId')->willReturn('thread-1');
		$store->expects($this->never())->method('save');

		$leaf = new InAppMessagingLeaf($store, $access);

		$this->assertFalse($leaf->postMessage('thread-1', 'guardian-2', false, 'hi'));
	}//end testNonParticipantCannotPostIntoADirectThread()

	public function testPostMessageSucceedsForAParticipant(): void {
		$access = $this->createMock(MessageThreadAccessGuard::class);
		$access->method('isParticipant')->willReturn(true);

		$store = $this->createMock(MessageStore::class);
		$store->method('findAll')->willReturn([['id' => 'thread-1', 'kind' => 'direct', 'participantRefs' => ['guardian-1', 'staff-1']]]);
		$store->method('rowId')->willReturn('thread-1');
		$store->expects($this->once())->method('save')->with('guardianMessage', $this->anything())->willReturn('message-1');

		$leaf = new InAppMessagingLeaf($store, $access);

		$this->assertTrue($leaf->postMessage('thread-1', 'guardian-1', false, 'hi'));
	}//end testPostMessageSucceedsForAParticipant()

	/**
	 * A thread is asked for by id first; a store that does not narrow by id
	 * still finds it through the full read.
	 */
	public function testAThreadIsFoundByIdOrByTheFullRead(): void {
		$access = $this->createMock(MessageThreadAccessGuard::class);
		$access->method('isParticipant')->willReturn(true);
		$thread = ['id' => 'thread-1', 'kind' => 'direct', 'participantRefs' => ['guardian-1', 'staff-1']];

		$byId = $this->createMock(MessageStore::class);
		$byId->method('rowId')->willReturnCallback(fn (array $row) => $row['id'] ?? null);
		$byId->expects($this->exactly(2))->method('findAll')->willReturnMap([
			['messageThread', [], ['thread-1'], [$thread]],
			['guardianMessage', ['threadRef' => 'thread-1'], null, [['id' => 'msg-1', 'threadRef' => 'thread-1']]],
		]);
		$this->assertCount(1, (new InAppMessagingLeaf($byId, $access))->listMessages('thread-1', 'guardian-1', false));

		$fullRead = $this->createMock(MessageStore::class);
		$fullRead->method('rowId')->willReturnCallback(fn (array $row) => $row['id'] ?? null);
		$fullRead->method('findAll')->willReturnMap([
			['messageThread', [], ['thread-1'], []],
			['messageThread', [], ['thread-9'], []],
			['messageThread', [], null, [['id' => 'thread-0'], $thread]],
			['guardianMessage', ['threadRef' => 'thread-1'], null, []],
		]);
		$this->assertSame([], (new InAppMessagingLeaf($fullRead, $access))->listMessages('thread-1', 'guardian-1', false));
		$this->assertNull((new InAppMessagingLeaf($fullRead, $access))->listMessages('thread-9', 'guardian-1', false));
	}//end testAThreadIsFoundByIdOrByTheFullRead()

	public function testMarkThreadReadIsIdempotent(): void {
		$access = $this->createMock(MessageThreadAccessGuard::class);
		$access->method('isParticipant')->willReturn(true);

		$store = $this->createMock(MessageStore::class);
		$store->method('findAll')->willReturnMap([
			['messageThread', [], ['thread-1'], [['id' => 'thread-1', 'kind' => 'direct', 'participantRefs' => ['guardian-1', 'staff-1']]]],
			['guardianMessage', ['threadRef' => 'thread-1'], null, [['id' => 'msg-1', 'threadRef' => 'thread-1', 'readBy' => ['guardian-1']]]],
		]);
		$store->method('rowId')->willReturnCallback(fn (array $row) => $row['id'] ?? null);
		// Already read by guardian-1 — save must NEVER be called again.
		$store->expects($this->never())->method('save');

		$leaf = new InAppMessagingLeaf($store, $access);

		$this->assertTrue($leaf->markThreadRead('thread-1', 'guardian-1', false));
	}//end testMarkThreadReadIsIdempotent()

	public function testMarkThreadReadAppendsAFirstTimeReceipt(): void {
		$access = $this->createMock(MessageThreadAccessGuard::class);
		$access->method('isParticipant')->willReturn(true);

		$store = $this->createMock(MessageStore::class);
		$store->method('findAll')->willReturnMap([
			['messageThread', [], ['thread-1'], [['id' => 'thread-1', 'kind' => 'direct', 'participantRefs' => ['guardian-1', 'staff-1']]]],
			['guardianMessage', ['threadRef' => 'thread-1'], null, [['id' => 'msg-1', 'threadRef' => 'thread-1', 'readBy' => []]]],
		]);
		$store->method('rowId')->willReturnCallback(fn (array $row) => $row['id'] ?? null);
		$store->expects($this->once())->method('save')->with('guardianMessage', $this->callback(fn (array $o): bool => $o['readBy'] === ['guardian-1']), 'msg-1');

		$leaf = new InAppMessagingLeaf($store, $access);

		$this->assertTrue($leaf->markThreadRead('thread-1', 'guardian-1', false));
	}//end testMarkThreadReadAppendsAFirstTimeReceipt()

	/**
	 * A thread with a proven contact carries the record, the subject line and
	 * the contact's name, and starts with the resident's message, already
	 * read by her (site-messages-per-record).
	 *
	 * @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md#requirement-a-resident-may-start-a-conversation-only-with-a-contact-of-their-own-record
	 */
	public function testAContactThreadCarriesItsRecordAndFirstMessage(): void {
		$saved = [];
		$store = $this->createMock(MessageStore::class);
		$store->method('save')->willReturnCallback(
			static function (string $schema, array $object) use (&$saved): string {
				$saved[] = [$schema, $object];
				return $schema === 'messageThread' ? 'thread-7' : 'message-1';
			}
		);

		$leaf    = new InAppMessagingLeaf($store, $this->createMock(MessageThreadAccessGuard::class));
		$contact = ['staffRef' => 'po-leerkracht-09', 'name' => 'Meester Daan', 'role' => 'Leerkracht', 'recordRef' => 'enr-vera', 'recordLabel' => 'Vera, Groep 7'];

		$this->assertSame('thread-7', $leaf->createContactThread('fatima', $contact, 'Topografie', 'Moet Vera de kaart uit het hoofd kennen?'));
		$this->assertSame(['fatima', 'po-leerkracht-09'], $saved[0][1]['participantRefs']);
		$this->assertSame(['enr-vera', 'Vera, Groep 7', 'Topografie', 'Meester Daan'], [$saved[0][1]['recordRef'], $saved[0][1]['recordLabel'], $saved[0][1]['title'], $saved[0][1]['staffName']]);
		$this->assertSame(['guardianMessage', 'thread-7', 'fatima', ['fatima']], [$saved[1][0], $saved[1][1]['threadRef'], $saved[1][1]['senderRef'], $saved[1][1]['readBy']]);
	}//end testAContactThreadCarriesItsRecordAndFirstMessage()

	public function testAContactThreadWithoutAMessageOrWithHerselfIsNotStored(): void {
		$store = $this->createMock(MessageStore::class);
		$store->expects($this->never())->method('save');
		$leaf = new InAppMessagingLeaf($store, $this->createMock(MessageThreadAccessGuard::class));

		$this->assertNull($leaf->createContactThread('fatima', ['staffRef' => 'po-leerkracht-09'], 'x', '  '));
		$this->assertNull($leaf->createContactThread('fatima', ['staffRef' => 'fatima'], 'x', 'hallo'));
	}//end testAContactThreadWithoutAMessageOrWithHerselfIsNotStored()
}//end class
