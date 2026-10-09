<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\SessionAdminController;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkTokens;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Security review S5 and S6: the admin's revoke-all names the acting admin,
 * and a run that could not reach or revoke every session answers 503 with
 * its counts, never a plain "0 revoked".
 *
 * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#3.2
 */
class SessionAdminControllerTest extends TestCase {
	/**
	 * The controller over a session service that answers `$result`.
	 *
	 * @param array<string, mixed> $result The service's answer.
	 * @param string $uid The signed-in admin.
	 *
	 * @return SessionAdminController
	 */
	private function controller(array $result, string $uid = 'beheerder'): SessionAdminController {
		$session = $this->createMock(PortalSessionService::class);
		$session->expects($this->once())->method('revokeAllForOrganisation')->with('org-1', $uid)->willReturn($result);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$users = $this->createMock(IUserSession::class);
		$users->method('getUser')->willReturn($user);

		return new SessionAdminController($this->createMock(IRequest::class), $session, $users);
	}//end controller()

	/**
	 * A complete run answers 200 with its counts and passes the admin on.
	 *
	 * @return void
	 */
	public function testACompleteRunAnswersItsCount(): void {
		$response = $this->controller(['revoked' => 3, 'failed' => 0, 'complete' => true])->revokeOrganisation('org-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['revoked' => 3, 'failed' => 0, 'complete' => true], $response->getData());
	}//end testACompleteRunAnswersItsCount()

	/**
	 * An incomplete run answers 503 with an error and the counts.
	 *
	 * @return void
	 */
	public function testAnIncompleteRunIsAnError(): void {
		$response = $this->controller(['revoked' => 0, 'failed' => 0, 'complete' => false])->revokeOrganisation('org-1');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(['error' => 'revoke_incomplete', 'revoked' => 0, 'failed' => 0, 'complete' => false], $response->getData());
	}//end testAnIncompleteRunIsAnError()

	/**
	 * Staff revoke one account's unspent links and live sessions.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#12
	 */
	public function testRevokeAccountVoidsTheLinksAndTheSessionsOfOneAccount(): void {
		$session = $this->createMock(PortalSessionService::class);
		$session->expects($this->once())->method('revokeAllForSubject')->with('email:tom', 'academie', 'beheerder')
			->willReturn(['revoked' => 1, 'failed' => 0, 'complete' => true]);
		$session->expects($this->never())->method('revokeAllForOrganisation');
		$links = $this->getMockBuilder(EmailLinkTokens::class)->disableOriginalConstructor()->onlyMethods(['voidFor'])->getMock();
		$links->expects($this->once())->method('voidFor')->with('email:tom', 'academie')->willReturn(2);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$users = $this->createMock(IUserSession::class);
		$users->method('getUser')->willReturn($user);

		$controller = new SessionAdminController($this->createMock(IRequest::class), $session, $users, $links);

		$response = $controller->revokeAccount('email:tom', 'academie');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['links' => 2, 'revoked' => 1, 'failed' => 0, 'complete' => true], $response->getData());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->revokeAccount('', 'academie')->getStatus());
	}//end testRevokeAccountVoidsTheLinksAndTheSessionsOfOneAccount()
}//end class
