<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Messaging;

use OCA\Portaliq\Service\GroupStaffFixtureReader;
use OCA\Portaliq\Service\GuardianAudienceFixtureReader;
use OCA\Portaliq\Service\Messaging\MessageThreadAccessGuard;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/guardian-direct-messages/design.md#participation-model
 */
class MessageThreadAccessGuardTest extends TestCase {

	public function testCanCreateDirectWhenTheOtherPartyTeachesAReachableGroup(): void {
		$staffReader = $this->createMock(GroupStaffFixtureReader::class);
		$staffReader->method('groupsTaughtBy')->willReturnMap([
			['staff-leerkracht-5a', ['groep-5a']],
			['guardian-anna-devries', []],
		]);

		$audienceReader = $this->createMock(GuardianAudienceFixtureReader::class);
		$audienceReader->method('guardianReachesGroup')->willReturnMap([
			['guardian-anna-devries', 'groep-5a', true],
		]);

		$guard = new MessageThreadAccessGuard($audienceReader, $staffReader);

		$this->assertTrue($guard->canCreateDirect(['guardian-anna-devries', 'staff-leerkracht-5a'], 'guardian-anna-devries'));
	}//end testCanCreateDirectWhenTheOtherPartyTeachesAReachableGroup()

	public function testCannotCreateDirectWithAStaffMemberOutsideTheGuardiansReach(): void {
		$staffReader = $this->createMock(GroupStaffFixtureReader::class);
		$staffReader->method('groupsTaughtBy')->willReturn(['groep-4c']);

		$audienceReader = $this->createMock(GuardianAudienceFixtureReader::class);
		$audienceReader->method('guardianReachesGroup')->willReturn(false);

		$guard = new MessageThreadAccessGuard($audienceReader, $staffReader);

		$this->assertFalse($guard->canCreateDirect(['guardian-anna-devries', 'staff-leerkracht-4c'], 'guardian-anna-devries'));
	}//end testCannotCreateDirectWithAStaffMemberOutsideTheGuardiansReach()

	public function testCanCreateDirectRejectsAMalformedParticipantList(): void {
		$guard = new MessageThreadAccessGuard($this->createMock(GuardianAudienceFixtureReader::class), $this->createMock(GroupStaffFixtureReader::class));

		$this->assertFalse($guard->canCreateDirect(['only-one'], 'only-one'));
		$this->assertFalse($guard->canCreateDirect(['a', 'b'], 'someone-else-entirely'));
	}//end testCanCreateDirectRejectsAMalformedParticipantList()

	public function testCanCreateGroupOnlyWhenTheStaffMemberTeachesIt(): void {
		$staffReader = $this->createMock(GroupStaffFixtureReader::class);
		$staffReader->method('staffTeachesGroup')->willReturnMap([
			['staff-leerkracht-5a', 'groep-5a', true],
			['staff-leerkracht-5a', 'groep-4c', false],
		]);

		$guard = new MessageThreadAccessGuard($this->createMock(GuardianAudienceFixtureReader::class), $staffReader);

		$this->assertTrue($guard->canCreateGroup('groep-5a', 'staff-leerkracht-5a'));
		$this->assertFalse($guard->canCreateGroup('groep-4c', 'staff-leerkracht-5a'));
	}//end testCanCreateGroupOnlyWhenTheStaffMemberTeachesIt()

	public function testIsParticipantForADirectThread(): void {
		$guard = new MessageThreadAccessGuard($this->createMock(GuardianAudienceFixtureReader::class), $this->createMock(GroupStaffFixtureReader::class));
		$thread = ['kind' => 'direct', 'participantRefs' => ['guardian-1', 'staff-1']];

		$this->assertTrue($guard->isParticipant($thread, 'guardian-1', false));
		$this->assertFalse($guard->isParticipant($thread, 'guardian-outsider', false));
	}//end testIsParticipantForADirectThread()

	public function testIsParticipantForAGroupThreadStaffAndGuardian(): void {
		$staffReader = $this->createMock(GroupStaffFixtureReader::class);
		$staffReader->method('staffTeachesGroup')->willReturn(true);

		$audienceReader = $this->createMock(GuardianAudienceFixtureReader::class);
		$audienceReader->method('guardianReachesGroup')->willReturn(true);

		$guard = new MessageThreadAccessGuard($audienceReader, $staffReader);
		$thread = ['kind' => 'group', 'groupRef' => 'groep-5a'];

		$this->assertTrue($guard->isParticipant($thread, 'staff-leerkracht-5a', true));
		$this->assertTrue($guard->isParticipant($thread, 'guardian-anna-devries', false));
	}//end testIsParticipantForAGroupThreadStaffAndGuardian()

	public function testIsParticipantFailsClosedForAnEmptySubjectOrGroup(): void {
		$guard = new MessageThreadAccessGuard($this->createMock(GuardianAudienceFixtureReader::class), $this->createMock(GroupStaffFixtureReader::class));

		$this->assertFalse($guard->isParticipant(['kind' => 'direct', 'participantRefs' => ['a']], '', false));
		$this->assertFalse($guard->isParticipant(['kind' => 'group', 'groupRef' => ''], 'someone', false));
	}//end testIsParticipantFailsClosedForAnEmptySubjectOrGroup()
}//end class
