<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\ActionAuthService;
use OCA\Portaliq\Service\AdminMenuAccess;
use OCA\Portaliq\Service\PageEditorService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The flags the app menu reads, per role (admin-menu-follows-roles).
 *
 * @spec openspec/changes/admin-menu-follows-roles/specs/admin-ui/spec.md#requirement-the-app-menu-must-show-a-user-only-the-pages-their-role-may-use
 */
class AdminMenuAccessTest extends TestCase {

	/**
	 * A teacher outside every configured group gets no administration.
	 *
	 * @return void
	 */
	public function testATeacherGetsNoAdministration(): void {
		$flags = $this->access(uid: 'po-leerkracht-09', admin: false, editor: false, actions: [])->forCurrentUser();

		$this->assertSame(['admin' => false, 'pages' => false, 'accounts' => false, 'accessRequests' => false], $flags);
	}//end testATeacherGetsNoAdministration()

	/**
	 * An administrator gets every flag.
	 *
	 * @return void
	 */
	public function testAnAdministratorGetsEverything(): void {
		$flags = $this->access(uid: 'admin', admin: true, editor: true, actions: ['portal.provision', 'portal.answer-access-request'])->forCurrentUser();

		$this->assertSame(['admin' => true, 'pages' => true, 'accounts' => true, 'accessRequests' => true], $flags);
	}//end testAnAdministratorGetsEverything()

	/**
	 * Each flag follows its own rule: an editor group gives pages, a granted
	 * action gives its page, and neither makes the user an administrator.
	 *
	 * @return void
	 */
	public function testEachFlagFollowsItsOwnRule(): void {
		$editor = $this->access(uid: 'po-ib-01', admin: false, editor: true, actions: [])->forCurrentUser();
		$this->assertSame(['admin' => false, 'pages' => true, 'accounts' => false, 'accessRequests' => false], $editor);

		$office = $this->access(uid: 'po-directeur-01', admin: false, editor: false, actions: ['portal.provision'])->forCurrentUser();
		$this->assertSame(['admin' => false, 'pages' => false, 'accounts' => true, 'accessRequests' => false], $office);
	}//end testEachFlagFollowsItsOwnRule()

	/**
	 * Without a signed-in user every flag is false.
	 *
	 * @return void
	 */
	public function testWithoutAUserEverythingIsFalse(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$access = new AdminMenuAccess(
			$session,
			$this->createMock(IGroupManager::class),
			$this->createMock(PageEditorService::class),
			$this->createMock(ActionAuthService::class)
		);

		$this->assertSame(['admin' => false, 'pages' => false, 'accounts' => false, 'accessRequests' => false], $access->forCurrentUser());
	}//end testWithoutAUserEverythingIsFalse()

	/**
	 * The service for one user.
	 *
	 * @param string       $uid     The user id.
	 * @param bool         $admin   Whether the user administers the instance.
	 * @param bool         $editor  Whether the user may edit pages.
	 * @param list<string> $actions The actions the matrix grants the user.
	 *
	 * @return AdminMenuAccess
	 */
	private function access(string $uid, bool $admin, bool $editor, array $actions): AdminMenuAccess {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with($uid)->willReturn($admin);
		$pages = $this->createMock(PageEditorService::class);
		$pages->method('mayEdit')->with($user)->willReturn($editor);
		$matrix = $this->createMock(ActionAuthService::class);
		$matrix->method('can')->willReturnCallback(fn (IUser $who, string $action): bool => in_array($action, $actions, true));

		return new AdminMenuAccess($session, $groups, $pages, $matrix);
	}//end access()
}//end class
