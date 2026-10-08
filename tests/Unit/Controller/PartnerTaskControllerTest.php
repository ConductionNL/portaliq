<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PartnerTaskController;
use OCA\Portaliq\Service\Tasks\PartnerAskService;
use OCA\Portaliq\Service\Tasks\PortalCaseAccessGuard;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * partner-tasks-in-the-portal REQ-PTP-002: a user without write on the case is
 * refused, and nothing is raised and no account provisioned when they are. The
 * least privileged principal here is an authenticated user the guard says no
 * about, and before them an anonymous caller.
 *
 * @spec openspec/changes/partner-tasks-in-the-portal/specs/partner-tasks-in-the-portal/spec.md
 */
class PartnerTaskControllerTest extends TestCase {

	/**
	 * The ask service double.
	 *
	 * @var mixed
	 */
	private mixed $asks = null;

	public function testAUserWithoutWriteOnTheCaseIsRefusedAndNothingIsRaised(): void {
		$controller = $this->controller(mayAsk: false, user: $this->user('ordinary-user'));
		$this->asks->expects($this->never())->method('ask');

		$response = $controller->ask(register: 'dossiq', schema: 'zaak', caseId: 'zaak-1', title: 'Advies welstand');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'forbidden'], $response->getData());

	}//end testAUserWithoutWriteOnTheCaseIsRefusedAndNothingIsRaised()

	public function testAnAnonymousCallerIsRefusedBeforeTheGuardIsEvenAsked(): void {
		$controller = $this->controller(mayAsk: true, user: null);
		$this->asks->expects($this->never())->method('ask');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->ask(register: 'dossiq', schema: 'zaak', caseId: 'zaak-1', title: 'Advies welstand')->getStatus());

	}//end testAnAnonymousCallerIsRefusedBeforeTheGuardIsEvenAsked()

	public function testAHandlerWithWriteRaisesTheAskAsThemselves(): void {
		$controller = $this->controller(mayAsk: true, user: $this->user('handler-anna'));
		$this->asks->expects($this->once())
			->method('ask')
			->with(
				$this->callback(static fn (array $handler): bool => ($handler['uid'] ?? '') === 'handler-anna'),
				$this->equalTo(['kvk' => '12345678', 'email' => 'welstand@example.org']),
				$this->callback(static fn (array $ask): bool => ($ask['title'] ?? '') === 'Advies welstand' && ($ask['caseId'] ?? '') === 'zaak-1')
			)
			->willReturn(['subjectRef' => 'partner-subject', 'provisioned' => true, 'task' => ['uuid' => 'task-1']]);

		$data = $controller->ask(
			register: 'dossiq',
			schema: 'zaak',
			caseId: 'zaak-1',
			title: 'Advies welstand',
			partner: ['kvk' => '12345678', 'email' => 'welstand@example.org']
		)->getData();

		$this->assertSame('partner-subject', $data['subjectRef']);
		$this->assertTrue($data['provisioned']);

	}//end testAHandlerWithWriteRaisesTheAskAsThemselves()

	public function testAnAskThatCouldNotBeRaisedIsABadRequest(): void {
		$controller = $this->controller(mayAsk: true, user: $this->user('handler-anna'));
		$this->asks->method('ask')->willReturn(null);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->ask(register: 'dossiq', schema: 'zaak', caseId: 'zaak-1', title: 'Advies welstand')->getStatus());

	}//end testAnAskThatCouldNotBeRaisedIsABadRequest()

	/**
	 * The controller over doubles.
	 *
	 * @param bool $mayAsk What the guard says.
	 * @param IUser|null $user The user making the request.
	 *
	 * @return PartnerTaskController
	 */
	private function controller(bool $mayAsk, ?IUser $user): PartnerTaskController {
		$this->asks = $this->getMockBuilder(PartnerAskService::class)
			->disableOriginalConstructor()
			->onlyMethods(['ask'])
			->getMock();

		$guard = $this->getMockBuilder(PortalCaseAccessGuard::class)
			->disableOriginalConstructor()
			->onlyMethods(['mayAsk'])
			->getMock();
		$guard->method('mayAsk')->willReturn($mayAsk);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn('gemeente-x');

		return new PartnerTaskController($request, $this->asks, $guard, $session);
	}//end controller()

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

}//end class
