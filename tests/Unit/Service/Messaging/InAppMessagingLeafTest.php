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
		$store->expects($this->once())->method('save')->with('message', $this->anything())->willReturn('message-1');

		$leaf = new InAppMessagingLeaf($store, $access);

		$this->assertTrue($leaf->postMessage('thread-1', 'guardian-1', false, 'hi'));
	}//end testPostMessageSucceedsForAParticipant()

	public function testMarkThreadReadIsIdempotent(): void {
		$access = $this->createMock(MessageThreadAccessGuard::class);
		$access->method('isParticipant')->willReturn(true);

		$store = $this->createMock(MessageStore::class);
		$store->method('findAll')->willReturnMap([
			['messageThread', [['id' => 'thread-1', 'kind' => 'direct', 'participantRefs' => ['guardian-1', 'staff-1']]]],
			['message', [['id' => 'msg-1', 'threadRef' => 'thread-1', 'readBy' => ['guardian-1']]]],
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
			['messageThread', [['id' => 'thread-1', 'kind' => 'direct', 'participantRefs' => ['guardian-1', 'staff-1']]]],
			['message', [['id' => 'msg-1', 'threadRef' => 'thread-1', 'readBy' => []]]],
		]);
		$store->method('rowId')->willReturnCallback(fn (array $row) => $row['id'] ?? null);
		$store->expects($this->once())->method('save')->with('message', $this->callback(fn (array $o): bool => $o['readBy'] === ['guardian-1']), 'msg-1');

		$leaf = new InAppMessagingLeaf($store, $access);

		$this->assertTrue($leaf->markThreadRead('thread-1', 'guardian-1', false));
	}//end testMarkThreadReadAppendsAFirstTimeReceipt()
}//end class
