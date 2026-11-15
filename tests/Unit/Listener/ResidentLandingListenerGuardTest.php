<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Listener;

use OCA\Portaliq\Listener\ResidentLandingListener;
use OCA\Portaliq\Service\Identity\NextcloudRegistrationDoor;
use OCA\Portaliq\Service\PublishedPortals;
use OCP\EventDispatcher\Event;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Groups no portal names, a group without an id, and other events never
 * touch a start page.
 *
 * @covers \OCA\Portaliq\Listener\ResidentLandingListener
 */
class ResidentLandingListenerGuardTest extends TestCase {

	/**
	 * The listener over a config that must stay untouched; portals name only `inwoners`.
	 *
	 * @return ResidentLandingListener
	 */
	private function listener(): ResidentLandingListener {
		$config = $this->createMock(IConfig::class);
		$config->expects($this->never())->method('getUserValue');
		$config->expects($this->never())->method('setUserValue');
		$config->expects($this->never())->method('deleteUserValue');
		$portals = $this->createMock(PublishedPortals::class);
		$portals->method('allPublishedPortals')->willReturn([['slug' => 'zuiddrecht'], 'not-a-portal']);
		$door = $this->getMockBuilder(NextcloudRegistrationDoor::class)->disableOriginalConstructor()->onlyMethods(['portalGroups', 'residentPortalOf'])->getMock();
		$door->method('portalGroups')->willReturn(['inwoners' => 'resident']);
		$door->expects($this->never())->method('residentPortalOf');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		return new ResidentLandingListener(
			config: $config,
			groups: $this->createMock(IGroupManager::class),
			portals: $portals,
			residents: $door,
			logger: $logger
		);
	}//end listener()

	/**
	 * A group and a user.
	 *
	 * @param string $gid The group id.
	 *
	 * @return array{0: IGroup, 1: IUser}
	 */
	private function pair(string $gid): array {
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn($gid);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('jan');

		return [$group, $user];
	}//end pair()

	/**
	 * Leaving a group no portal names leaves the start page alone.
	 *
	 * @return void
	 */
	public function testLeavingAnUnnamedGroupChangesNothing(): void {
		[$group, $user] = $this->pair('sportclub');

		$this->listener()->handle(new UserRemovedEvent($group, $user));
	}//end testLeavingAnUnnamedGroupChangesNothing()

	/**
	 * A group without an id is never a portal's group, joining or leaving.
	 *
	 * @return void
	 */
	public function testAGroupWithoutAnIdChangesNothing(): void {
		[$group, $user] = $this->pair('');
		$listener = $this->listener();

		$listener->handle(new UserAddedEvent($group, $user));
		$listener->handle(new UserRemovedEvent($group, $user));
	}//end testAGroupWithoutAnIdChangesNothing()

	/**
	 * Another event is ignored.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$this->listener()->handle(new Event());
	}//end testAnotherEventIsIgnored()
}//end class
