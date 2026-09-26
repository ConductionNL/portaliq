<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PollController;
use OCA\Portaliq\Service\PollService;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * parent-polls: staff creates against their own uid, a caller with no
 * session creates and answers nothing, and every subject-facing call is
 * scoped by the resolved bearer, never the request.
 *
 * @spec openspec/changes/parent-polls/specs/parent-polls/spec.md
 */
class PollControllerTest extends TestCase {

	/**
	 * The doubles the controller under test is built from.
	 *
	 * @var array<string, mixed>
	 */
	private array $doubles = [];

	public function testAStaffMemberCreatesAsThemselves(): void {
		$controller = $this->controller(user: $this->user('clerk-anna'), subject: null);
		$this->doubles['polls']->expects($this->once())
			->method('create')
			->with(
				$this->equalTo(value: 'Welke datum?'),
				$this->anything(),
				$this->equalTo(value: 'parent'),
				$this->equalTo(value: 'gemeente-x'),
				$this->anything(),
				$this->equalTo(value: 'clerk-anna')
			)
			->willReturn(['poll' => ['question' => 'Welke datum?']]);

		$response = $controller->create(question: 'Welke datum?', audience: 'parent', organisation: 'gemeente-x');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());

	}//end testAStaffMemberCreatesAsThemselves()

	public function testACallerWithNoStaffSessionCreatesNothing(): void {
		$controller = $this->controller(user: null, subject: null);
		$this->doubles['polls']->expects($this->never())->method('create');

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $controller->create(question: 'Welke datum?')->getStatus());

	}//end testACallerWithNoStaffSessionCreatesNothing()

	public function testAPortalSubjectListsTheirOwnPolls(): void {
		$controller = $this->controller(user: null, subject: ['audience' => 'parent', 'organisation' => 'gemeente-x', 'subjectRef' => 'guardian-1']);
		$this->doubles['polls']->expects($this->once())
			->method('forSubject')
			->with($this->equalTo(value: ['audience' => 'parent', 'organisation' => 'gemeente-x', 'subjectRef' => 'guardian-1']))
			->willReturn([]);

		$this->assertSame(expected: Http::STATUS_OK, actual: $controller->index()->getStatus());

	}//end testAPortalSubjectListsTheirOwnPolls()

	public function testACallerWithNoSessionListsNothing(): void {
		$controller = $this->controller(user: null, subject: null);
		$this->doubles['polls']->expects($this->never())->method('forSubject');

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $controller->index()->getStatus());

	}//end testACallerWithNoSessionListsNothing()

	public function testAClosedPollAnswersForbidden(): void {
		$controller = $this->controller(user: null, subject: ['audience' => 'parent', 'organisation' => 'gemeente-x', 'subjectRef' => 'guardian-1']);
		$this->doubles['polls']->method('respond')->willReturn(['error' => 'closed']);

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $controller->respond(id: 'poll-1', optionId: 'a')->getStatus());

	}//end testAClosedPollAnswersForbidden()

	public function testAnUnknownOptionAnswersUnprocessable(): void {
		$controller = $this->controller(user: null, subject: ['audience' => 'parent', 'organisation' => 'gemeente-x', 'subjectRef' => 'guardian-1']);
		$this->doubles['polls']->method('respond')->willReturn(['error' => 'unknown_option']);

		$this->assertSame(expected: Http::STATUS_UNPROCESSABLE_ENTITY, actual: $controller->respond(id: 'poll-1', optionId: 'z')->getStatus());

	}//end testAnUnknownOptionAnswersUnprocessable()

	/**
	 * The controller over doubles, all of which can only answer methods the
	 * real classes have.
	 *
	 * @param IUser|null $user The staff user, or null.
	 * @param array<string, mixed>|null $subject The portal subject, or null.
	 *
	 * @return PollController
	 */
	private function controller(?IUser $user, ?array $subject): PollController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');

		$polls = $this->getMockBuilder(PollService::class)
			->disableOriginalConstructor()
			->onlyMethods(['create', 'forSubject', 'respond'])
			->getMock();

		$session = $this->getMockBuilder(PortalSessionService::class)
			->disableOriginalConstructor()
			->onlyMethods(['resolveFromBearer'])
			->getMock();
		$session->method('resolveFromBearer')->willReturn($subject);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$this->doubles = ['polls' => $polls];

		return new PollController($request, $polls, $session, $userSession);
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
