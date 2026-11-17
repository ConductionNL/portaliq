<?php

/**
 * PortalAdminScope: an instance administrator administers every portal, a
 * member of a portal's own `adminGroups` that portal only, and nobody else
 * any (operate-portals-per-organisation REQ-OPO-005).
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Tenancy
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
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t11
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Tenancy;

use OCA\Portaliq\Service\PortalSlugLookup;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;
use OCA\Portaliq\Service\Tenancy\PortalAdminScope;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(PortalAdminScope::class)]
#[UsesClass(PortalSlugLookup::class)]
class PortalAdminScopeTest extends TestCase {

	/**
	 * The two portals of the shared installation.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private const PORTALS = [
		'zeist' => ['slug' => 'zeist', 'adminGroups' => ['zeist-beheer']],
		'utrecht' => ['slug' => 'utrecht', 'adminGroups' => ['utrecht-beheer', 'utrecht-redactie']],
	];

	/**
	 * The reader double.
	 *
	 * @var ScopedCollectionReaderInterface&MockObject
	 */
	private $reader;

	/**
	 * A scope for a signed-in user (or nobody) with these groups.
	 *
	 * @param string|null        $uid    The signed-in user, or null.
	 * @param array<int, string> $groups The user's groups.
	 * @param bool               $admin  Whether the user is an instance administrator.
	 *
	 * @return PortalAdminScope
	 */
	private function scope(?string $uid, array $groups = [], bool $admin = false): PortalAdminScope {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $who): bool => ($who === $uid && $admin));
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $who, string $gid): bool => ($who === $uid && in_array($gid, $groups, true))
		);

		$this->reader = $this->createMock(ScopedCollectionReaderInterface::class);
		$this->reader->method('readCollection')->willReturnCallback(
			static fn (string $register, string $schema, string $scopeField, string $subjectRef): array =>
				array_values(array_filter([(self::PORTALS[$subjectRef] ?? null)]))
		);

		return new PortalAdminScope(userSession: $session, groupManager: $groupManager, reader: $this->reader);
	}//end scope()

	/**
	 * A member of a portal's admin group administers that portal and no other.
	 *
	 * @return void
	 */
	public function testAMemberAdministersOwnPortalOnly(): void {
		$scope = $this->scope(uid: 'anne', groups: ['zeist-beheer']);

		$this->assertTrue($scope->mayAdminister(portal: self::PORTALS['zeist']));
		$this->assertFalse($scope->mayAdminister(portal: self::PORTALS['utrecht']));
		$this->assertTrue($scope->mayAdministerSlug(slug: 'zeist'));
		$this->assertFalse($scope->mayAdministerSlug(slug: 'utrecht'));
	}//end testAMemberAdministersOwnPortalOnly()

	/**
	 * Any one of a portal's groups is enough.
	 *
	 * @return void
	 */
	public function testAnyOfThePortalsGroupsIsEnough(): void {
		$this->assertTrue($this->scope(uid: 'bram', groups: ['utrecht-redactie'])->mayAdminister(portal: self::PORTALS['utrecht']));
	}//end testAnyOfThePortalsGroupsIsEnough()

	/**
	 * An instance administrator administers every portal, without reading it.
	 *
	 * @return void
	 */
	public function testAnInstanceAdministratorAdministersEveryPortal(): void {
		$scope = $this->scope(uid: 'root', admin: true);
		$this->reader->expects($this->never())->method('readCollection');

		$this->assertTrue($scope->isInstanceAdmin());
		$this->assertTrue($scope->mayAdminister(portal: self::PORTALS['utrecht']));
		$this->assertTrue($scope->mayAdminister(portal: ['slug' => 'no-groups']));
		$this->assertTrue($scope->mayAdministerSlug(slug: 'utrecht'));
	}//end testAnInstanceAdministratorAdministersEveryPortal()

	/**
	 * A user in no admin group, a portal naming no groups and an unknown portal are refused.
	 *
	 * @return void
	 */
	public function testEveryoneElseIsRefused(): void {
		$scope = $this->scope(uid: 'carla', groups: ['portaliq-medewerkers']);

		$this->assertFalse($scope->isInstanceAdmin());
		$this->assertFalse($scope->mayAdminister(portal: self::PORTALS['zeist']));
		$this->assertFalse($scope->mayAdminister(portal: ['slug' => 'no-groups']));
		$this->assertFalse($scope->mayAdminister(portal: ['slug' => 'odd', 'adminGroups' => 'portaliq-medewerkers']), 'a string is not a list of groups');
		$this->assertFalse($scope->mayAdministerSlug(slug: 'nowhere'));
		$this->assertFalse($scope->mayAdministerSlug(slug: ''));
	}//end testEveryoneElseIsRefused()

	/**
	 * Without a signed-in user nothing is administered.
	 *
	 * @return void
	 */
	public function testNobodySignedInAdministersNothing(): void {
		$scope = $this->scope(uid: null);

		$this->assertFalse($scope->isInstanceAdmin());
		$this->assertFalse($scope->mayAdminister(portal: self::PORTALS['zeist']));
		$this->assertFalse($scope->mayAdministerSlug(slug: 'zeist'));
	}//end testNobodySignedInAdministersNothing()

	/**
	 * A portal that cannot be read is refused, not granted.
	 *
	 * @return void
	 */
	public function testAnUnreadablePortalIsRefused(): void {
		$scope = $this->scope(uid: 'anne', groups: ['zeist-beheer']);
		$reader = $this->createMock(ScopedCollectionReaderInterface::class);
		$reader->method('readCollection')->willThrowException(new RuntimeException('down'));
		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anne');
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('isInGroup')->willReturn(true);
		$unreadable = new PortalAdminScope(userSession: $session, groupManager: $groupManager, reader: $reader);

		$this->assertTrue($scope->mayAdministerSlug(slug: 'zeist'));
		$this->assertFalse($unreadable->mayAdministerSlug(slug: 'zeist'));
	}//end testAnUnreadablePortalIsRefused()

	/**
	 * The admin groups are the non-empty string ids, trimmed, once each.
	 *
	 * @return void
	 */
	public function testAdminGroupsAreCleanStrings(): void {
		$this->assertSame(['a', 'b'], PortalAdminScope::adminGroupsOf(portal: ['adminGroups' => [' a ', '', 'b', 7, null, 'a']]));
		$this->assertSame([], PortalAdminScope::adminGroupsOf(portal: []));
		$this->assertSame([], PortalAdminScope::adminGroupsOf(portal: ['adminGroups' => 'a']));
	}//end testAdminGroupsAreCleanStrings()
}//end class
