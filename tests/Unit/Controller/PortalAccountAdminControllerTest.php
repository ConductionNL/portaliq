<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalAccountAdminController;
use OCA\Portaliq\Service\ActionAuthService;
use OCA\Portaliq\Service\Identity\PortalIdentityMailer;
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
	 * identity-staff-account-screens T02, T03: withdrawing an invitation and
	 * approving or refusing a registration need the provision action.
	 *
	 * @return void
	 */
	public function testTheNewStaffRoutesNeedTheProvisionAction(): void {
		$accounts = $this->accounts();
		$accounts->expects($this->never())->method('approvePending');
		$accounts->expects($this->never())->method('voidPending');
		$controller = $this->controller(accounts: $accounts, allowed: false, user: $this->user('clerk-anna'));

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->revokeInvitation(id: 'inv-1', organisation: 'gemeente-x')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->approve(subjectRef: 'subject-1')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->refuse(subjectRef: 'subject-1', reason: 'unknown address')->getStatus());
		$anonymous = $this->controller(accounts: $this->accounts(), allowed: true, user: null);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $anonymous->approve(subjectRef: 'subject-1')->getStatus());

	}//end testTheNewStaffRoutesNeedTheProvisionAction()

	/**
	 * Approve answers active, refuse needs a reason, withdraw names why it
	 * could not.
	 *
	 * @return void
	 */
	public function testApproveRefuseAndWithdrawAnswerWhatHappened(): void {
		$accounts = $this->accounts();
		$accounts->method('approvePending')->willReturnOnConsecutiveCalls(true, false);
		$accounts->method('voidPending')->willReturn(true);
		$controller = $this->controller(accounts: $accounts, allowed: true, user: $this->user('clerk-anna'));

		$this->assertSame(['status' => 'active'], $controller->approve(subjectRef: 'subject-1')->getData());
		$this->assertSame(['error' => 'not_pending'], $controller->approve(subjectRef: 'subject-1')->getData());
		$this->assertSame(['error' => 'reason_required'], $controller->refuse(subjectRef: 'subject-1')->getData());
		$this->assertSame(['status' => 'void'], $controller->refuse(subjectRef: 'subject-1', reason: 'Not a resident')->getData());
		$this->assertSame(['state' => 'revoked'], $controller->revokeInvitation(id: 'inv-1', organisation: 'gemeente-x')->getData());
		$refused = $controller->revokeInvitation(id: 'inv-accepted', organisation: 'gemeente-x');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
		$this->assertSame(['error' => 'already_accepted'], $refused->getData());

	}//end testApproveRefuseAndWithdrawAnswerWhatHappened()

	/**
	 * The controller over doubles.
	 *
	 * @param PortalAccountService $accounts The account service double.
	 * @param bool $allowed Whether the action matrix lets this user through.
	 * @param IUser|null $user The user making the request, or null.
	 *
	 * @return PortalAccountAdminController
	 */
	private function controller(PortalAccountService $accounts, bool $allowed, ?IUser $user, ?PortalIdentityMailer $mailer = null): PortalAccountAdminController {
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
			->onlyMethods(['invite', 'sentBy', 'revoke'])
			->getMock();
		$invitations->method('invite')->willReturn(['token' => 'secret-1', 'expiresAt' => '2026-09-25T09:00:00+00:00']);
		$invitations->method('revoke')->willReturnCallback(static fn (string $id): string => $id === 'inv-accepted' ? 'already_accepted' : '');
		$invitations->method('sentBy')->willReturn([['email' => 'ans@example.org', 'state' => 'sent', 'sentAt' => '', 'expiresAt' => '']]);

		if ($mailer === null) {
			$mailer = $this->mailer();
			$mailer->method('send')->willReturn(true);
		}

		return new PortalAccountAdminController($this->createMock(IRequest::class), $accounts, $actionAuth, $session, $invitations, $mailer);
	}//end controller()

	public function testAnInvitationIsOnlySentByAClerkWithTheAction(): void {
		$refused = $this->controller(accounts: $this->accounts(), allowed: false, user: $this->user('ordinary-user'));
		$allowed = $this->controller(accounts: $this->accounts(), allowed: true, user: $this->user('clerk-anna'));

		$this->assertSame(Http::STATUS_FORBIDDEN, $refused->invite(email: 'ans@example.org', organisation: 'gemeente-x')->getStatus());
		$this->assertSame(Http::STATUS_OK, $allowed->invite(email: 'ans@example.org', organisation: 'gemeente-x')->getStatus());

	}//end testAnInvitationIsOnlySentByAClerkWithTheAction()

	/**
	 * portaliq#795, identity-staff-account-screens T01. The invitation's
	 * secret used to be answered to the clerk and mailed to nobody. It now
	 * goes to the invited address, and the clerk sees only that it was sent.
	 *
	 * @return void
	 */
	public function testTheInvitationIsMailedAndTheClerkNeverSeesItsSecret(): void {
		$mailer = $this->mailer();
		$mailer->expects($this->once())
			->method('send')
			->with(
				$this->equalTo(PortalIdentityMailer::TEMPLATE_INVITATION),
				$this->equalTo('ans@example.org'),
				$this->equalTo('secret-1'),
				$this->equalTo('gemeente-x')
			)
			->willReturn(true);
		$controller = $this->controller(accounts: $this->accounts(), allowed: true, user: $this->user('clerk-anna'), mailer: $mailer);

		$data = $controller->invite(email: 'ans@example.org', organisation: 'gemeente-x')->getData();

		$this->assertSame(['state' => 'sent', 'expiresAt' => '2026-09-25T09:00:00+00:00'], $data);
		$this->assertStringNotContainsString('secret-1', (string)json_encode($data));

	}//end testTheInvitationIsMailedAndTheClerkNeverSeesItsSecret()

	/**
	 * portaliq#795. An invitation whose mail did not leave admits nobody, so
	 * the clerk is told to send it again, and still never sees the secret.
	 *
	 * @return void
	 */
	public function testAnInvitationWhoseMailFailedSaysSoWithoutTheSecret(): void {
		$mailer = $this->mailer();
		$mailer->method('send')->willReturn(false);
		$controller = $this->controller(accounts: $this->accounts(), allowed: true, user: $this->user('clerk-anna'), mailer: $mailer);

		$response = $controller->invite(email: 'ans@example.org', organisation: 'gemeente-x');

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertSame(['error' => 'mail_not_sent'], $response->getData());

	}//end testAnInvitationWhoseMailFailedSaysSoWithoutTheSecret()

	/**
	 * A mailer double limited to the method the real one has.
	 *
	 * @return PortalIdentityMailer&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function mailer(): PortalIdentityMailer {
		return $this->getMockBuilder(PortalIdentityMailer::class)
			->disableOriginalConstructor()
			->onlyMethods(['send'])
			->getMock();
	}//end mailer()

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
			->onlyMethods(['provision', 'voidPending', 'approvePending'])
			->getMock();
	}//end accounts()

}//end class
