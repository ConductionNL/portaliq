<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Tenancy;

use OCA\Portaliq\Service\ExampleSite\ExampleSiteStore;
use OCA\Portaliq\Service\Tenancy\PortalAdminScope;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * operate-portals-per-organisation REQ-OPO-005: a member of a portal's admin
 * group administers that portal and no other; an instance administrator
 * administers every portal and alone hands out the groups.
 *
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t11
 */
class PortalAdminScopeTest extends TestCase {

	private const ZEIST = ['slug' => 'zeist', 'adminGroups' => ['zeist-beheer']];

	private const UTRECHT = ['slug' => 'utrecht', 'adminGroups' => ['utrecht-beheer']];

	private const OPEN = ['slug' => 'open', 'adminGroups' => []];

	public function testAMemberAdministersTheirOwnPortal(): void {
		$scope = $this->scope(admin: false, groups: ['zeist-beheer']);

		$this->assertTrue($scope->mayAdminister(portal: self::ZEIST));
		$this->assertTrue($scope->mayAdministerSlug(slug: 'zeist'));
	}//end testAMemberAdministersTheirOwnPortal()

	public function testAMemberDoesNotAdministerAnotherPortal(): void {
		$scope = $this->scope(admin: false, groups: ['zeist-beheer']);

		$this->assertFalse($scope->mayAdminister(portal: self::UTRECHT));
		$this->assertFalse($scope->mayAdministerSlug(slug: 'utrecht'));
		$this->assertFalse($scope->mayAdministerSlug(slug: 'open'), 'a portal without adminGroups is the instance administrators\' only');
		$this->assertFalse($scope->mayAdministerSlug(slug: 'nowhere'), 'an unknown slug answers the same as a foreign one');
		$this->assertSame(['zeist'], $scope->administeredSlugs());
	}//end testAMemberDoesNotAdministerAnotherPortal()

	public function testOnlyAnInstanceAdministratorChangesTheGroups(): void {
		$this->assertFalse($this->scope(admin: false, groups: ['zeist-beheer'])->mayChangeAdminGroups());
		$this->assertTrue($this->scope(admin: true, groups: [])->mayChangeAdminGroups());
	}//end testOnlyAnInstanceAdministratorChangesTheGroups()

	public function testAnInstanceAdministratorAdministersEveryPortal(): void {
		$scope = $this->scope(admin: true, groups: []);

		$this->assertTrue($scope->mayAdminister(portal: self::UTRECHT));
		$this->assertTrue($scope->mayAdminister(portal: self::OPEN));
		$this->assertNull($scope->administeredSlugs());
	}//end testAnInstanceAdministratorAdministersEveryPortal()

	public function testWithoutASessionNothingIsAdministered(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$scope = new PortalAdminScope($session, $this->createMock(IGroupManager::class), $this->createMock(ExampleSiteStore::class));

		$this->assertFalse($scope->mayAdminister(portal: self::ZEIST));
		$this->assertFalse($scope->mayChangeAdminGroups());
		$this->assertSame([], $scope->administeredSlugs());
	}//end testWithoutASessionNothingIsAdministered()

	public function testGroupsOfKeepsOnlyNamedGroups(): void {
		$this->assertSame(['a', 'b'], PortalAdminScope::groupsOf(portal: ['adminGroups' => [' a ', '', 'b', 'a', 7, null]]));
		$this->assertSame([], PortalAdminScope::groupsOf(portal: ['adminGroups' => 'a']));
		$this->assertSame([], PortalAdminScope::groupsOf(portal: []));
	}//end testGroupsOfKeepsOnlyNamedGroups()

	/**
	 * A scope for one user over the three portals.
	 *
	 * @param bool          $admin  Whether the user administers the instance.
	 * @param array<string> $groups The groups the user is in.
	 *
	 * @return PortalAdminScope
	 */
	private function scope(bool $admin, array $groups): PortalAdminScope {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('someone');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$manager = $this->createMock(IGroupManager::class);
		$manager->method('isAdmin')->willReturn($admin);
		$manager->method('isInGroup')->willReturnCallback(static fn (string $uid, string $gid): bool => in_array($gid, $groups, true));
		$store = $this->createMock(ExampleSiteStore::class);
		$store->method('find')->with('portal', [])->willReturn([self::ZEIST, self::UTRECHT, self::OPEN]);

		return new PortalAdminScope($session, $manager, $store);
	}//end scope()
}//end class
