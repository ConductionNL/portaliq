<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalAccountAdminController;
use OCA\Portaliq\Service\ActionAuthService;
use OCA\Portaliq\Service\Identity\PortalInvitationService;
use OCA\Portaliq\Service\PortalAccountService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * portal-identity-space REQ-PIS-001: provisioning is a staff act behind the
 * ADR-023 action `portal.provision`. The least privileged principal that
 * should be refused is an ordinary authenticated user without the action, and
 * an anonymous caller before them.
 *
 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
 */
class PortalAccountAdminControllerTest extends TestCase {

	public function testAnAuthenticatedUserWithoutTheActionIsRefused(): void {
		$accounts = $this->accounts();
		$accounts->expects($this->never())->method('provision');
		$controller = $this->controller(accounts: $accounts, allowed: false, user: $this->user('ordinary-user'));

		$response = $controller->provision(audience: 'client', organisation: 'gemeente-x', identityType: 'digid', identityRef: 'bsn-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'forbidden'], $response->getData());

	}//end testAnAuthenticatedUserWithoutTheActionIsRefused()

	public function testAnAnonymousCallerIsRefusedBeforeAnythingIsRead(): void {
		$accounts = $this->accounts();
		$accounts->expects($this->never())->method('provision');
		$controller = $this->controller(accounts: $accounts, allowed: true, user: null);

		$response = $controller->provision(audience: 'client', organisation: 'gemeente-x', identityType: 'digid', identityRef: 'bsn-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAnAnonymousCallerIsRefusedBeforeAnythingIsRead()

	public function testAClerkWithTheActionProvisionsAndIsNamedOnTheRow(): void {
		$accounts = $this->accounts();
		$accounts->expects($this->once())
			->method('provision')
			->with(
				$this->equalTo('client'),
				$this->equalTo('gemeente-x'),
				$this->equalTo('digid'),
				$this->equalTo('bsn-1'),
				$this->equalTo(''),
				$this->equalTo(false),
				$this->equalTo('clerk-anna'),
				$this->equalTo('')
			)
			->willReturn(['subjectRef' => 'subject-1', 'isNew' => true, 'status' => 'pending']);
		$controller = $this->controller(accounts: $accounts, allowed: true, user: $this->user('clerk-anna'));

		$response = $controller->provision(audience: 'client', organisation: 'gemeente-x', identityType: 'digid', identityRef: 'bsn-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('pending', $response->getData()['status']);

	}//end testAClerkWithTheActionProvisionsAndIsNamedOnTheRow()

	public function testARefusedProvisioningIsABadRequest(): void {
		$accounts = $this->accounts();
		$accounts->method('provision')->willReturn(null);
		$controller = $this->controller(accounts: $accounts, allowed: true, user: $this->user('clerk-anna'));

		$response = $controller->provision(audience: 'client', organisation: 'gemeente-x');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testARefusedProvisioningIsABadRequest()

	public function testWithdrawingWithoutAReasonIsRefused(): void {
		$accounts = $this->accounts();
		$accounts->expects($this->never())->method('voidPending');
		$controller = $this->controller(accounts: $accounts, allowed: true, user: $this->user('clerk-anna'));

		$response = $controller->void(subjectRef: 'subject-1', reason: '');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'reason_required'], $response->getData());

	}//end testWithdrawingWithoutAReasonIsRefused()

	public function testWithdrawingAPendingAccountSaysItIsVoid(): void {
		$accounts = $this->accounts();
		$accounts->method('voidPending')->willReturn(true);
		$controller = $this->controller(accounts: $accounts, allowed: true, user: $this->user('clerk-anna'));

		$response = $controller->void(subjectRef: 'subject-1', reason: 'Provisioned on a mistyped BSN');

		$this->assertSame(['status' => 'void'], $response->getData());

	}//end testWithdrawingAPendingAccountSaysItIsVoid()

	/**
	 * The controller over doubles.
	 *
	 * @param PortalAccountService $accounts The account service double.
	 * @param bool $allowed Whether the action matrix lets this user through.
	 * @param IUser|null $user The user making the request, or null.
	 *
	 * @return PortalAccountAdminController
	 */
	private function controller(PortalAccountService $accounts, bool $allowed, ?IUser $user): PortalAccountAdminController {
		$actionAuth = $this->getMockBuilder(ActionAuthService::class)
			->disableOriginalConstructor()
			->onlyMethods(['requireAction'])
			->getMock();
		if ($allowed === false) {
			$actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('nope'));
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$invitations = $this->getMockBuilder(PortalInvitationService::class)
			->disableOriginalConstructor()
			->onlyMethods(['invite', 'sentBy'])
			->getMock();
		$invitations->method('invite')->willReturn(['token' => 'secret-1', 'expiresAt' => '2026-09-25T09:00:00+00:00']);
		$invitations->method('sentBy')->willReturn([['email' => 'ans@example.org', 'state' => 'sent', 'sentAt' => '', 'expiresAt' => '']]);

		return new PortalAccountAdminController($this->createMock(IRequest::class), $accounts, $actionAuth, $session, $invitations);
	}//end controller()

	public function testAnInvitationIsOnlySentByAClerkWithTheAction(): void {
		$refused = $this->controller(accounts: $this->accounts(), allowed: false, user: $this->user('ordinary-user'));
		$allowed = $this->controller(accounts: $this->accounts(), allowed: true, user: $this->user('clerk-anna'));

		$this->assertSame(Http::STATUS_FORBIDDEN, $refused->invite(email: 'ans@example.org', organisation: 'gemeente-x')->getStatus());
		$this->assertSame('secret-1', $allowed->invite(email: 'ans@example.org', organisation: 'gemeente-x')->getData()['token']);

	}//end testAnInvitationIsOnlySentByAClerkWithTheAction()

	public function testTheSenderSeesTheStateOfTheirOwnInvitations(): void {
		$controller = $this->controller(accounts: $this->accounts(), allowed: true, user: $this->user('clerk-anna'));

		$response = $controller->invitations(organisation: 'gemeente-x');

		$this->assertSame('sent', $response->getData()['invitations'][0]['state']);

	}//end testTheSenderSeesTheStateOfTheirOwnInvitations()

	/**
	 * A user double with a uid.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	/**
	 * A double that can only answer methods the real service has.
	 *
	 * @return PortalAccountService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function accounts(): PortalAccountService {
		return $this->getMockBuilder(PortalAccountService::class)
			->disableOriginalConstructor()
			->onlyMethods(['provision', 'voidPending'])
			->getMock();
	}//end accounts()

}//end class
