<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Tenancy;

use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\Tenancy\PortalAdminGroups;
use OCA\Portaliq\Service\Tenancy\PortalAdminScope;
use OCP\IGroup;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * The portal's administering groups as stored and as listed
 * (operate-portals-per-organisation REQ-OPO-005).
 *
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t12
 */
class PortalAdminGroupsTest extends TestCase {

	public function testASaveKeepsOnlyExistingGroupsOnce(): void {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->once())
			->method('updateObject')
			->with('portaliq', 'portal', 'slug', 'zeist', '', 'p-1', ['adminGroups' => ['zeist-beheer', 'zeist-web']])
			->willReturn(['slug' => 'zeist']);

		$service = new PortalAdminGroups($this->manager(), $writer, $this->scope());

		$this->assertSame(['slug' => 'zeist'], $service->save(portal: ['id' => 'p-1', 'slug' => 'zeist'], groups: ['zeist-beheer', ['id' => 'zeist-web'], 'typo', 'zeist-beheer', '', 3]));
	}//end testASaveKeepsOnlyExistingGroupsOnce()

	public function testAGoneGroupIsListedByItsId(): void {
		$service = new PortalAdminGroups($this->manager(), $this->createMock(PortalObjectWriter::class), $this->scope());

		$this->assertSame(
			[['id' => 'zeist-beheer', 'label' => 'Zeist beheer'], ['id' => 'gone', 'label' => 'gone']],
			$service->listFor(portal: ['adminGroups' => ['zeist-beheer', 'gone']])
		);
	}//end testAGoneGroupIsListedByItsId()

	/**
	 * A group manager that knows two groups.
	 *
	 * @return IGroupManager
	 */
	private function manager(): IGroupManager {
		$known = ['zeist-beheer' => 'Zeist beheer', 'zeist-web' => 'Zeist web'];
		$manager = $this->createMock(IGroupManager::class);
		$manager->method('groupExists')->willReturnCallback(static fn (string $gid): bool => isset($known[$gid]));
		$manager->method('get')->willReturnCallback(function (string $gid) use ($known): ?IGroup {
			if (isset($known[$gid]) === false) {
				return null;
			}

			$group = $this->createMock(IGroup::class);
			$group->method('getDisplayName')->willReturn($known[$gid]);

			return $group;
		});

		return $manager;
	}//end manager()

	/**
	 * A scope that reads the groups with its own rule.
	 *
	 * @return PortalAdminScope
	 */
	private function scope(): PortalAdminScope {
		return $this->getMockBuilder(PortalAdminScope::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
	}//end scope()
}//end class
