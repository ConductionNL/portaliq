<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Listener;

use OCA\Portaliq\Listener\ResidentLandingListener;
use OCA\Portaliq\Service\Identity\NextcloudRegistrationDoor;
use OCA\Portaliq\Service\PortalResolver;
use OCP\App\IAppManager;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A member of a portal's resident group starts on portaliq, so the
 * registration app's last step lands on the portal.
 *
 * @spec openspec/changes/resident-lands-on-the-portal/specs/portal-nextcloud-account-login/spec.md#requirement-a-residents-nextcloud-start-page-is-the-portal-req-ncl-007
 */
class ResidentLandingListenerTest extends TestCase {
	/**
	 * The users' preferences, by user id: `core`/`defaultapp`.
	 *
	 * @var array<string, string>
	 */
	private array $startPages = [];

	/**
	 * The groups each user is in.
	 *
	 * @var array<string, list<string>>
	 */
	private array $memberships = [];

	/**
	 * The published portals.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $portals = [];

	/**
	 * Whether reading the portals fails.
	 *
	 * @var bool
	 */
	private bool $portalsFail = false;

	protected function setUp(): void {
		parent::setUp();
		$this->portals = [
			[
				'slug'           => 'zuiddrecht',
				'authentication' => ['modes' => ['digid', 'nextcloud'], 'registration' => ['residentGroup' => 'inwoners', 'businessGroup' => 'ondernemers']],
			],
			[
				'slug'           => 'elders',
				'authentication' => ['modes' => ['digid'], 'registration' => ['residentGroup' => 'zonder-account']],
			],
		];
	}

	/**
	 * The listener over this test's preferences, memberships and portals.
	 *
	 * @return ResidentLandingListener
	 */
	private function listener(): ResidentLandingListener {
		$config = $this->createMock(originalClassName: IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			fn (string $uid, string $app, string $key, mixed $default = ''): string => (
				($app === 'core' && $key === 'defaultapp') ? ($this->startPages[$uid] ?? (string)$default) : (string)$default
			)
		);
		$config->method('setUserValue')->willReturnCallback(
			function (string $uid, string $app, string $key, string $value): void {
				$this->assertSame(['core', 'defaultapp'], [$app, $key]);
				$this->startPages[$uid] = $value;
			}
		);
		$config->method('deleteUserValue')->willReturnCallback(
			function (string $uid, string $app, string $key): void {
				$this->assertSame(['core', 'defaultapp'], [$app, $key]);
				unset($this->startPages[$uid]);
			}
		);

		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $uid): bool => in_array(needle: 'admin', haystack: ($this->memberships[$uid] ?? []), strict: true));
		$groups->method('isInGroup')->willReturnCallback(
			fn (string $uid, string $gid): bool => in_array(needle: $gid, haystack: ($this->memberships[$uid] ?? []), strict: true)
		);

		$portals = $this->createMock(originalClassName: PortalResolver::class);
		$portals->method('allPublishedPortals')->willReturnCallback(
			function (): array {
				if ($this->portalsFail === true) {
					throw new RuntimeException('OpenRegister is away');
				}

				return $this->portals;
			}
		);

		$door = new NextcloudRegistrationDoor(
			apps: $this->createMock(originalClassName: IAppManager::class),
			appConfig: $this->createMock(originalClassName: IAppConfig::class),
			urls: $this->createMock(originalClassName: IURLGenerator::class),
			groups: $groups,
			portals: $portals
		);

		return new ResidentLandingListener(
			config: $config,
			groups: $groups,
			portals: $portals,
			residents: $door,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end listener()

	/**
	 * A group and a user for an event.
	 *
	 * @param string $gid The group id.
	 * @param string $uid The user id.
	 *
	 * @return array{0: IGroup, 1: IUser}
	 */
	private function pair(string $gid, string $uid): array {
		$group = $this->createMock(originalClassName: IGroup::class);
		$group->method('getGID')->willReturn($gid);
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);

		return [$group, $user];
	}//end pair()

	/**
	 * A user the registration app puts in the resident group.
	 *
	 * @param string $gid The group id.
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function join(string $gid, string $uid): void {
		$this->memberships[$uid][] = $gid;
		[$group, $user]            = $this->pair(gid: $gid, uid: $uid);
		$this->listener()->handle(new UserAddedEvent($group, $user));
	}//end join()

	/**
	 * A user taken out of a group.
	 *
	 * @param string $gid The group id.
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function leave(string $gid, string $uid): void {
		$this->memberships[$uid] = array_values(array_diff(($this->memberships[$uid] ?? []), [$gid]));
		[$group, $user]          = $this->pair(gid: $gid, uid: $uid);
		$this->listener()->handle(new UserRemovedEvent($group, $user));
	}//end leave()

	/**
	 * @return void
	 *
	 * @spec openspec/changes/resident-lands-on-the-portal/specs/portal-nextcloud-account-login/spec.md#requirement-a-residents-nextcloud-start-page-is-the-portal-req-ncl-007
	 */
	public function testANewResidentStartsOnPortaliq(): void {
		$this->join(gid: 'inwoners', uid: 'sanne');

		$this->assertSame('portaliq', $this->startPages['sanne']);
	}

	/**
	 * @return void
	 *
	 * @spec openspec/changes/resident-lands-on-the-portal/specs/portal-nextcloud-account-login/spec.md#requirement-a-residents-nextcloud-start-page-is-the-portal-req-ncl-007
	 */
	public function testAnotherGroupAnAdministratorOrAnOwnChoiceIsLeftAlone(): void {
		$this->join(gid: 'zuiddrecht-medewerkers', uid: 'daan');
		// A portal without the nextcloud way in names a group that means nothing.
		$this->join(gid: 'zonder-account', uid: 'henk');
		$this->memberships['root'] = ['admin'];
		$this->join(gid: 'inwoners', uid: 'root');
		$this->startPages['linda'] = 'files';
		$this->join(gid: 'inwoners', uid: 'linda');

		$this->assertSame(['linda' => 'files'], $this->startPages);
	}

	/**
	 * @return void
	 *
	 * @spec openspec/changes/resident-lands-on-the-portal/specs/portal-nextcloud-account-login/spec.md#requirement-a-residents-nextcloud-start-page-is-the-portal-req-ncl-007
	 */
	public function testLeavingTheResidentGroupTakesOnlyOurStartPageBack(): void {
		$this->join(gid: 'inwoners', uid: 'sanne');
		$this->startPages['linda'] = 'files';
		$this->memberships['linda'] = ['inwoners'];

		$this->leave(gid: 'inwoners', uid: 'sanne');
		$this->leave(gid: 'inwoners', uid: 'linda');

		$this->assertSame(['linda' => 'files'], $this->startPages);
	}

	/**
	 * @return void
	 *
	 * @spec openspec/changes/business-audience-group/specs/portal-nextcloud-account-login/spec.md#requirement-a-portal-may-name-a-business-group-with-its-own-audience-req-ncl-008
	 */
	public function testABusinessContactStartsOnPortaliqAndLeavesItWithTheGroup(): void {
		$this->join(gid: 'ondernemers', uid: 'karin');
		$this->assertSame('portaliq', $this->startPages['karin']);

		$this->leave(gid: 'ondernemers', uid: 'karin');
		$this->assertSame([], $this->startPages);
	}

	/**
	 * @return void
	 *
	 * @spec openspec/changes/business-audience-group/specs/portal-nextcloud-account-login/spec.md#requirement-a-portal-may-name-a-business-group-with-its-own-audience-req-ncl-008
	 */
	public function testABusinessContactInBothGroupsKeepsPortaliqAfterLeavingOne(): void {
		$this->join(gid: 'ondernemers', uid: 'karin');
		$this->join(gid: 'inwoners', uid: 'karin');

		$this->leave(gid: 'ondernemers', uid: 'karin');

		$this->assertSame(['karin' => 'portaliq'], $this->startPages);
	}

	/**
	 * @return void
	 *
	 * @spec openspec/changes/resident-lands-on-the-portal/specs/portal-nextcloud-account-login/spec.md#requirement-a-residents-nextcloud-start-page-is-the-portal-req-ncl-007
	 */
	public function testAPortalReadThatFailsNeverStopsTheMembership(): void {
		$this->portalsFail = true;

		$this->join(gid: 'inwoners', uid: 'sanne');

		$this->assertSame([], $this->startPages);
	}
}
