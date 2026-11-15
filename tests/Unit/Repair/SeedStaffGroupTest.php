<?php

/**
 * The staff group is created and seeded once from the editor groups and the action matrix.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/close-portaliq-register-to-staff/specs/admin-ui/spec.md#requirement-the-staff-group-exists-and-keeps-everybody-who-already-worked-in-portaliq
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
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Portaliq\Repair\SeedStaffGroup
 *
 * @spec openspec/changes/close-portaliq-register-to-staff/specs/admin-ui/spec.md#requirement-the-staff-group-exists-and-keeps-everybody-who-already-worked-in-portaliq
 */
class SeedStaffGroupTest extends TestCase {

	/**
	 * The uids added to the staff group.
	 *
	 * @var array<string>
	 */
	private array $added = [];

	/**
	 * Build the step over named groups.
	 *
	 * @param array<string, array<string>> $members The members of each existing group.
	 * @param array<string>                $editors The configured editor groups.
	 * @param array<string, array<string>> $matrix  The action matrix.
	 * @param bool                         $seeded  Whether the step has seeded before.
	 * @param bool                         $exists  Whether the staff group already exists.
	 * @param string                       $signup  The registration app's group.
	 *
	 * @return SeedStaffGroup
	 */
	private function step(array $members, array $editors, array $matrix, bool $seeded=false, bool $exists=true, string $signup='inwoners'): SeedStaffGroup {
		$this->added = [];
		$staff = $this->createMock(IGroup::class);
		$staff->method('inGroup')->willReturn(false);
		$staff->method('addUser')->willReturnCallback(function (IUser $user): void {
			$this->added[] = $user->getUID();
		});

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(function (string $gid) use ($members, $staff, $exists): ?IGroup {
			if ($gid === AdminMenuAccess::STAFF_GROUP) {
				return ($exists === true) ? $staff : null;
			}

			if (isset($members[$gid]) === false) {
				return null;
			}

			$group = $this->createMock(IGroup::class);
			$group->method('getUsers')->willReturn(array_map(function (string $uid): IUser {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				return $user;
			}, $members[$gid]));
			return $group;
		});
		$groups->method('createGroup')->with(AdminMenuAccess::STAFF_GROUP)->willReturn($staff);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')->willReturn($seeded);
		$config->method('getValueString')->willReturn($signup);

		$pages = $this->createMock(PageEditorService::class);
		$pages->method('getEditorGroups')->willReturn($editors);
		$actions = $this->createMock(ActionAuthService::class);
		$actions->method('getMatrix')->willReturn($matrix);

		return new SeedStaffGroup($groups, $config, $pages, $actions);
	}//end step()

	/**
	 * Editors and action holders become staff; administrators and residents do not.
	 *
	 * @return void
	 */
	public function testEditorsAndActionHoldersAreSeededButNotAdminsOrResidents(): void {
		$step = $this->step(
			members: ['redactie' => ['els'], 'leerkrachten' => ['piet', 'els'], 'admin' => ['root'], 'inwoners' => ['jan']],
			editors: ['redactie'],
			matrix: ['portal.author-news' => ['admin', 'leerkrachten'], 'portal.provision' => ['admin', 'inwoners']]
		);

		$step->run($this->createMock(IOutput::class));

		$this->assertSame(['els', 'piet'], array_values(array_unique($this->added)));
	}//end testEditorsAndActionHoldersAreSeededButNotAdminsOrResidents()

	/**
	 * The seeding runs once; a later upgrade never re-adds somebody an admin removed.
	 *
	 * @return void
	 */
	public function testTheSeedingRunsOnce(): void {
		$step = $this->step(members: ['redactie' => ['els']], editors: ['redactie'], matrix: [], seeded: true);

		$step->run($this->createMock(IOutput::class));

		$this->assertSame([], $this->added);
	}//end testTheSeedingRunsOnce()

	/**
	 * A missing staff group is created.
	 *
	 * @return void
	 */
	public function testAMissingStaffGroupIsCreated(): void {
		$step = $this->step(members: ['redactie' => ['els']], editors: ['redactie'], matrix: [], exists: false);

		$step->run($this->createMock(IOutput::class));

		$this->assertSame(['els'], $this->added);
	}//end testAMissingStaffGroupIsCreated()

	/**
	 * The step is registered for install and for upgrade.
	 *
	 * @return void
	 */
	public function testTheStepRunsOnInstallAndUpgrade(): void {
		$info = (string)file_get_contents(dirname(__DIR__, 3).'/appinfo/info.xml');

		$this->assertSame(2, substr_count($info, '<step>OCA\Portaliq\Repair\SeedStaffGroup</step>'));
	}//end testTheStepRunsOnInstallAndUpgrade()
}//end class
