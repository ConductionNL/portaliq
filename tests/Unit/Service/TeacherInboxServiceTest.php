<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\Messaging\GuardianMessagingLeafInterface;
use OCA\Portaliq\Service\TeacherInboxService;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/teacher-inbox-per-group/specs/guardian-direct-messaging/spec.md#requirement-a-staff-members-inbox-is-bucketed-by-group-with-an-unread-count
 */
class TeacherInboxServiceTest extends TestCase {

	public function testThreadsAreBucketedByGroupAndDirect(): void {
		$messaging = $this->createMock(GuardianMessagingLeafInterface::class);
		$messaging->method('listThreads')->willReturn([
			['id' => 't1', 'kind' => 'group', 'groupRef' => 'groep-5a'],
			['id' => 't2', 'kind' => 'group', 'groupRef' => 'groep-3b'],
			['id' => 't3', 'kind' => 'direct', 'participantRefs' => ['staff-1', 'guardian-1']],
		]);
		$messaging->method('listMessages')->willReturn([]);

		$service = new TeacherInboxService($messaging);
		$inbox = $service->inboxFor('staff-1');

		$this->assertCount(1, $inbox['groep-5a']);
		$this->assertCount(1, $inbox['groep-3b']);
		$this->assertCount(1, $inbox['direct']);
	}//end testThreadsAreBucketedByGroupAndDirect()

	public function testUnreadCountReflectsReadByExactly(): void {
		$messaging = $this->createMock(GuardianMessagingLeafInterface::class);
		$messaging->method('listThreads')->willReturn([['id' => 't1', 'kind' => 'direct', 'participantRefs' => ['staff-1', 'guardian-1']]]);
		$messaging->method('listMessages')->willReturn([
			['readBy' => ['staff-1']],
			['readBy' => []],
			['readBy' => ['guardian-1']],
		]);

		$service = new TeacherInboxService($messaging);
		$inbox = $service->inboxFor('staff-1');

		$this->assertSame(2, $inbox['direct'][0]['unreadCount']);
	}//end testUnreadCountReflectsReadByExactly()

	public function testInboxNeverExceedsListThreadsOwnResult(): void {
		// listThreads() already scopes to the calling staff member's own
		// participation (MessageThreadAccessGuard, unchanged by this
		// change) — the inbox MUST NOT query anything beyond that result.
		$messaging = $this->createMock(GuardianMessagingLeafInterface::class);
		$messaging->expects($this->once())->method('listThreads')->with('staff-1', true)->willReturn([]);
		$messaging->expects($this->never())->method('listMessages');

		$service = new TeacherInboxService($messaging);

		$this->assertSame([], $service->inboxFor('staff-1'));
	}//end testInboxNeverExceedsListThreadsOwnResult()

	public function testReturnsEmptyForAnEmptyStaffRef(): void {
		$messaging = $this->createMock(GuardianMessagingLeafInterface::class);
		$messaging->expects($this->never())->method('listThreads');

		$service = new TeacherInboxService($messaging);

		$this->assertSame([], $service->inboxFor(''));
	}//end testReturnsEmptyForAnEmptyStaffRef()
}//end class
