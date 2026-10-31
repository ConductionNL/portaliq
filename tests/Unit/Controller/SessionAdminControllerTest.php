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
		$session->expects($this->once())->method('revokeAllForOrganisation')->with('academie', 'beheerder', 'email:tom')
			->willReturn(['revoked' => 1, 'failed' => 0, 'complete' => true]);
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
	/**
	 * The controller over a session service whose one-session revoke answers `$found`.
	 *
	 * @param bool $found Whether the service finds and revokes the session.
	 * @param int $calls How often the service may be asked.
	 *
	 * @return SessionAdminController
	 */
	private function oneSession(bool $found, int $calls = 1): SessionAdminController {
		$session = $this->createMock(originalClassName: PortalSessionService::class);
		$session->expects($this->exactly(count: $calls))->method('revokeByAdmin')->with('jti-1', 'beheerder')->willReturn($found);
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('beheerder');
		$users = $this->createMock(originalClassName: IUserSession::class);
		$users->method('getUser')->willReturn($user);

		return new SessionAdminController(request: $this->createMock(originalClassName: IRequest::class), session: $session, userSession: $users);
	}//end oneSession()

	/**
	 * Staff revoke one session by its token id, named as the acting admin.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/staff-revoke-one-session/specs/portal-account-administration/spec.md#requirement-staff-revoke-one-portal-session-req-isa-010
	 */
	public function testRevokeSessionRevokesOneSessionAsTheAdmin(): void {
		$response = $this->oneSession(found: true)->revokeSession(jti: 'jti-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['revoked' => true], actual: $response->getData());
	}//end testRevokeSessionRevokesOneSessionAsTheAdmin()

	/**
	 * An unknown or already revoked session answers 404, not a quiet success.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/staff-revoke-one-session/specs/portal-account-administration/spec.md#requirement-staff-revoke-one-portal-session-req-isa-010
	 */
	public function testRevokeSessionOfAnUnknownSessionIsNotFound(): void {
		$response = $this->oneSession(found: false)->revokeSession(jti: 'jti-1');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'not_found'], actual: $response->getData());
	}//end testRevokeSessionOfAnUnknownSessionIsNotFound()

	/**
	 * Without a token id nothing is asked of the service.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/staff-revoke-one-session/specs/portal-account-administration/spec.md#requirement-staff-revoke-one-portal-session-req-isa-010
	 */
	public function testRevokeSessionWithoutATokenIdIsRefused(): void {
		$response = $this->oneSession(found: true, calls: 0)->revokeSession(jti: '');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
	}//end testRevokeSessionWithoutATokenIdIsRefused()
}//end class
