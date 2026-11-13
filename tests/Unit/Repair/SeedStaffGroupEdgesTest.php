<?php

/**
 * Unit tests for the staff group seeding when groups are missing or uncreatable.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Repair;

use OCA\Portaliq\Repair\SeedStaffGroup;
use OCA\Portaliq\Service\ActionAuthService;
use OCA\Portaliq\Service\AdminMenuAccess;
use OCA\Portaliq\Service\PageEditorService;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * An uncreatable staff group postpones the seeding; a vanished source group and
 * existing members are skipped; the registration group is excluded unless it is
 * `none` or unreadable.
 */
#[CoversClass(SeedStaffGroup::class)]
class SeedStaffGroupEdgesTest extends TestCase {

	/**
	 * The group manager.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groups;

	/**
	 * The app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $config;

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->groups = $this->createMock(IGroupManager::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->config->method('getValueBool')->willReturn(false);
	}//end setUp()

	/**
	 * The step says what it does.
	 *
	 * @return void
	 */
	public function testTheName(): void {
		$this->assertSame('Create the portaliq staff group and put the current editors and action holders in it', $this->step(editors: [])->getName());
	}//end testTheName()

	/**
	 * A staff group that cannot be created leaves the seeding for the next upgrade.
	 *
	 * @return void
	 */
	public function testAnUncreatableStaffGroupPostponesTheSeeding(): void {
		$this->groups->method('get')->willReturn(null);
		$this->groups->expects($this->once())->method('createGroup')->with(AdminMenuAccess::STAFF_GROUP)->willReturn(null);
		$this->config->expects($this->never())->method('setValueBool');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')
			->with('Staff group: "'.AdminMenuAccess::STAFF_GROUP.'" could not be created, seeding left for the next upgrade.');

		$this->step(editors: ['redactie'])->run($output);
	}//end testAnUncreatableStaffGroupPostponesTheSeeding()

	/**
	 * A source group that no longer exists and a user already in the staff group are skipped.
	 *
	 * @return void
	 */
	public function testMissingGroupsAndExistingMembersAreSkipped(): void {
		$els   = $this->user(uid: 'els');
		$piet  = $this->user(uid: 'piet');
		$staff = $this->createMock(IGroup::class);
		$staff->method('inGroup')->willReturnCallback(static fn (IUser $user): bool => $user === $els);
		$staff->expects($this->once())->method('addUser')->with($piet);
		$editors = $this->createMock(IGroup::class);
		$editors->method('getUsers')->willReturn([$els, $piet]);
		$this->groups->method('get')->willReturnMap(
			[
				[AdminMenuAccess::STAFF_GROUP, $staff],
				['redactie', $editors],
				['gone', null],
			]
		);
		$this->config->method('getValueString')->willReturn('none');
		$this->config->expects($this->once())->method('setValueBool')->with('portaliq', $this->anything(), true);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Staff group: 1 people added to "'.AdminMenuAccess::STAFF_GROUP.'".');

		$this->step(editors: ['gone', 'redactie'])->run($output);
	}//end testMissingGroupsAndExistingMembersAreSkipped()

	/**
	 * With the registration group set to `none`, or unreadable, no group is excluded for it.
	 *
	 * @return void
	 */
	public function testANoneOrUnreadableRegistrationGroupExcludesNothing(): void {
		$asked = [];
		$this->groups->method('get')->willReturnCallback(
			function (string $gid) use (&$asked): ?IGroup {
				if ($gid === AdminMenuAccess::STAFF_GROUP) {
					return $this->createMock(IGroup::class);
				}

				$asked[] = $gid;
				return null;
			}
		);
		$this->config->method('getValueString')->willReturnOnConsecutiveCalls('none', $this->throwException(new RuntimeException('no config')));

		$this->step(editors: ['none', ''])->run($this->createMock(IOutput::class));
		$this->step(editors: ['inwoners'])->run($this->createMock(IOutput::class));

		$this->assertSame(['none', 'inwoners'], $asked);
	}//end testANoneOrUnreadableRegistrationGroupExcludesNothing()

	/**
	 * The step over the given editor groups and an empty action matrix.
	 *
	 * @param array<string> $editors The editor groups.
	 *
	 * @return SeedStaffGroup
	 */
	private function step(array $editors): SeedStaffGroup {
		$pages = $this->createMock(PageEditorService::class);
		$pages->method('getEditorGroups')->willReturn($editors);
		$actions = $this->createMock(ActionAuthService::class);
		$actions->method('getMatrix')->willReturn([]);

		return new SeedStaffGroup($this->groups, $this->config, $pages, $actions);
	}//end step()

	/**
	 * A user with the given uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser&MockObject
	 */
	private function user(string $uid): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()
}//end class
