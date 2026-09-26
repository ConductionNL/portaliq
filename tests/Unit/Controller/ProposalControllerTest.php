<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\ProposalController;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\Proposals\ProposalService;
use OCA\Portaliq\Service\Tasks\PortalCaseAccessGuard;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * change-proposal-queue REQ-CPQ-002 and REQ-CPQ-003: a portal subject proposes
 * only against the properties their contribution lists, a caller with no
 * session proposes nothing, and a user without write rights on the record can
 * neither accept nor reject, whatever the proposal says.
 *
 * @spec openspec/changes/change-proposal-queue/specs/change-proposal-queue/spec.md
 */
class ProposalControllerTest extends TestCase {

	/**
	 * The doubles the controller under test is built from.
	 *
	 * @var array<string, mixed>
	 */
	private array $doubles = [];

	public function testAPortalSubjectProposesAgainstTheContributionsAllowList(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'subject-1', 'organisation' => 'gemeente-x', 'audience' => 'client']);
		$this->doubles['proposals']->expects($this->once())
			->method('propose')
			->with(
				$this->equalTo(['register' => 'dossiq', 'schema' => 'zaak', 'id' => 'zaak-1']),
				$this->anything(),
				$this->equalTo(['applicantPhone']),
				$this->equalTo(['applicantPhone' => '0612345678']),
				$this->equalTo('subject-1'),
				$this->equalTo('portal'),
				$this->anything()
			)
			->willReturn(['proposal' => ['state' => 'queued']]);

		$response = $controller->proposeFromPortal(
			register: 'dossiq',
			schema: 'zaak',
			id: 'zaak-1',
			changes: [['property' => 'applicantPhone', 'proposedValue' => '0687654321']]
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testAPortalSubjectProposesAgainstTheContributionsAllowList()

	public function testACallerWithNoSessionProposesNothing(): void {
		$controller = $this->controller(subject: null);
		$this->doubles['proposals']->expects($this->never())->method('propose');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->proposeFromPortal(register: 'dossiq', schema: 'zaak', id: 'zaak-1')->getStatus());

	}//end testACallerWithNoSessionProposesNothing()

	public function testARecordWithNoProposeActionProposesNothing(): void {
		$controller = $this->controller(
			subject: ['subjectRef' => 'subject-1', 'organisation' => 'gemeente-x'],
			aggregate: ['contributions' => [['app' => 'dossiq', 'actions' => []]]]
		);
		$this->doubles['proposals']->expects($this->never())->method('propose');

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->proposeFromPortal(register: 'dossiq', schema: 'zaak', id: 'zaak-1')->getStatus());

	}//end testARecordWithNoProposeActionProposesNothing()

	public function testAnEmptyAllowListIsNotPermission(): void {
		$controller = $this->controller(
			subject: ['subjectRef' => 'subject-1', 'organisation' => 'gemeente-x'],
			aggregate: ['contributions' => [['app' => 'dossiq', 'actions' => [['type' => 'propose-change', 'register' => 'dossiq', 'schema' => 'zaak', 'proposable' => []]]]]]
		);
		$this->doubles['proposals']->expects($this->never())->method('propose');

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->proposeFromPortal(register: 'dossiq', schema: 'zaak', id: 'zaak-1')->getStatus());

	}//end testAnEmptyAllowListIsNotPermission()

	public function testARecordThatIsNotTheSubjectsProposesNothing(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'subject-1', 'organisation' => 'gemeente-x'], subjectRow: null);
		$this->doubles['proposals']->expects($this->never())->method('propose');

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->proposeFromPortal(register: 'dossiq', schema: 'zaak', id: 'zaak-1')->getStatus());

	}//end testARecordThatIsNotTheSubjectsProposesNothing()

	public function testAReviewerWithoutWriteRightsCanNeitherAcceptNorReject(): void {
		$controller = $this->controller(subject: null, user: $this->user('ordinary-user'), mayReview: false);
		$this->doubles['proposals']->expects($this->never())->method('accept');
		$this->doubles['proposals']->expects($this->never())->method('reject');

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->accept(id: 'proposal-1')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->reject(id: 'proposal-1', reason: 'Nee.')->getStatus());

	}//end testAReviewerWithoutWriteRightsCanNeitherAcceptNorReject()

	public function testAnAnonymousCallerDecidesNothing(): void {
		$controller = $this->controller(subject: null, user: null, mayReview: true);
		$this->doubles['proposals']->expects($this->never())->method('accept');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->accept(id: 'proposal-1')->getStatus());

	}//end testAnAnonymousCallerDecidesNothing()

	public function testADriftedAcceptAnswersAConflictWithBothValues(): void {
		$controller = $this->controller(subject: null, user: $this->user('handler-anna'), mayReview: true);
		$this->doubles['proposals']->method('accept')->willReturn(['error' => 'drifted', 'drift' => [['property' => 'applicantPhone', 'snapshot' => 'a', 'current' => 'b']]]);

		$response = $controller->accept(id: 'proposal-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('b', $response->getData()['drift'][0]['current']);

	}//end testADriftedAcceptAnswersAConflictWithBothValues()

	public function testARefusedPropertyIsUnprocessable(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'subject-1', 'organisation' => 'gemeente-x']);
		$this->doubles['proposals']->method('propose')->willReturn(['error' => 'property_not_proposable', 'property' => 'status']);

		$response = $controller->proposeFromPortal(register: 'dossiq', schema: 'zaak', id: 'zaak-1', changes: [['property' => 'status']]);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());

	}//end testARefusedPropertyIsUnprocessable()

	public function testAColleagueProposesAsThemselvesOnTheStaffChannel(): void {
		$controller = $this->controller(subject: null, user: $this->user('colleague-bob'), mayReview: false);
		$this->doubles['proposals']->expects($this->once())
			->method('propose')
			->with(
				$this->anything(),
				$this->anything(),
				$this->equalTo(['applicantPhone']),
				$this->anything(),
				$this->equalTo('colleague-bob'),
				$this->equalTo('staff'),
				$this->anything()
			)
			->willReturn(['proposal' => ['state' => 'queued']]);

		$response = $controller->proposeAsColleague(
			register: 'dossiq',
			schema: 'zaak',
			id: 'zaak-1',
			changes: [['property' => 'applicantPhone', 'proposedValue' => '0687654321']],
			proposable: ['applicantPhone']
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testAColleagueProposesAsThemselvesOnTheStaffChannel()

	/**
	 * REQ-CPQ-003, on the read side. The staff routes are gated by
	 * `portal.review-proposal`, and this one was not: it asked only whether
	 * anybody was logged in, then listed the proposals on whatever register,
	 * schema and id the caller named. Notes and proposed values on any
	 * record on the instance were readable by any account.
	 *
	 * @return void
	 */
	public function testTheQueueIsRefusedToAUserWhoMayNotReviewTheRecord(): void {
		$controller = $this->controller(subject: null, user: $this->user('clerk-1'), mayReview: false);
		$this->doubles['proposals']->expects($this->never())->method('forSubject');

		$response = $controller->index(register: 'dossiq', schema: 'zaak', id: 'zaak-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testTheQueueIsRefusedToAUserWhoMayNotReviewTheRecord()

	/**
	 * The same guard, the other way: a reviewer the guard admits gets the
	 * queue, so the refusal above is the guard and not a broken endpoint.
	 *
	 * @return void
	 */
	public function testTheQueueIsServedToAUserWhoMayReviewTheRecord(): void {
		$controller = $this->controller(subject: null, user: $this->user('reviewer-1'), mayReview: true);
		$this->doubles['proposals']->expects($this->once())
			->method('forSubject')
			->with(
				$this->equalTo(['register' => 'dossiq', 'schema' => 'zaak', 'id' => 'zaak-1']),
				$this->equalTo('queued')
			)
			->willReturn([]);

		$response = $controller->index(register: 'dossiq', schema: 'zaak', id: 'zaak-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testTheQueueIsServedToAUserWhoMayReviewTheRecord()

	/**
	 * A caller with no session at all is refused before the guard is asked.
	 *
	 * @return void
	 */
	public function testTheQueueIsRefusedToACallerWithNoSession(): void {
		$controller = $this->controller(subject: null, user: null);
		$this->doubles['proposals']->expects($this->never())->method('forSubject');

		$response = $controller->index(register: 'dossiq', schema: 'zaak', id: 'zaak-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testTheQueueIsRefusedToACallerWithNoSession()

	/**
	 * `mine()` is filtered by the bearer's own subjectRef, never a
	 * client-supplied value — there is no parameter on the route to widen it.
	 *
	 * @return void
	 */
	public function testMineIsFilteredByTheBearersOwnSubjectRefNeverAClientValue(): void {
		$controller = $this->controller(subject: ['subjectRef' => 'guardian-1', 'organisation' => 'gemeente-x', 'audience' => 'parent']);
		$this->doubles['proposals']->expects($this->once())
			->method('mine')
			->with($this->equalTo(value: 'guardian-1'))
			->willReturn([['uuid' => 'proposal-1', 'state' => 'queued']]);

		$response = $controller->mine();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['proposals' => [['uuid' => 'proposal-1', 'state' => 'queued']]], actual: $response->getData());

	}//end testMineIsFilteredByTheBearersOwnSubjectRefNeverAClientValue()

	/**
	 * No bearer, no subject, no read — refused before `mine()` is ever called.
	 *
	 * @return void
	 */
	public function testMineIsRefusedWithNoBearerAndIssuesNoRead(): void {
		$controller = $this->controller(subject: null);
		$this->doubles['proposals']->expects($this->never())->method('mine');

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $controller->mine()->getStatus());

	}//end testMineIsRefusedWithNoBearerAndIssuesNoRead()


	/**
	 * The controller over doubles.
	 *
	 * @param array<string, mixed>|null $subject The portal subject, or null.
	 * @param IUser|null $user The staff user, or null.
	 * @param bool $mayReview What the review guard says.
	 * @param array<string, mixed>|null $aggregate The contribution aggregate.
	 * @param array<string, mixed>|null $subjectRow The record the reader answers.
	 *
	 * @return ProposalController
	 */
	private function controller(
		?array $subject,
		?IUser $user = null,
		bool $mayReview = true,
		?array $aggregate = null,
		?array $subjectRow = ['applicantPhone' => '0612345678'],
	): ProposalController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');

		$proposals = $this->getMockBuilder(ProposalService::class)
			->disableOriginalConstructor()
			->onlyMethods(['propose', 'accept', 'reject', 'withdraw', 'forSubject', 'mine'])
			->getMock();

		$registry = $this->getMockBuilder(PortalContributionRegistry::class)
			->disableOriginalConstructor()
			->onlyMethods(['aggregateFor'])
			->getMock();
		$registry->method('aggregateFor')->willReturn(
			($aggregate ?? ['contributions' => [['app' => 'dossiq', 'actions' => [[
				'id' => 'propose-change',
				'type' => 'propose-change',
				'register' => 'dossiq',
				'schema' => 'zaak',
				'scopeField' => 'subjectRef',
				'proposable' => ['applicantPhone'],
			]]]]])
		);

		$reader = $this->getMockBuilder(PortalObjectReader::class)
			->disableOriginalConstructor()
			->onlyMethods(['readObject'])
			->getMock();
		$reader->method('readObject')->willReturnCallback(
			static function (string $register, string $schema) use ($subjectRow): ?array {
				if ($schema === 'changeProposal') {
					return ['uuid' => 'proposal-1', 'subjectRegister' => 'dossiq', 'subjectSchema' => 'zaak', 'subjectId' => 'zaak-1', 'state' => 'queued', 'proposedBy' => 'subject-1', 'changes' => []];
				}

				return $subjectRow;
			}
		);

		$session = $this->getMockBuilder(PortalSessionService::class)
			->disableOriginalConstructor()
			->onlyMethods(['resolveFromBearer'])
			->getMock();
		$session->method('resolveFromBearer')->willReturn($subject);

		$guard = $this->getMockBuilder(PortalCaseAccessGuard::class)
			->disableOriginalConstructor()
			->onlyMethods(['mayAct'])
			->getMock();
		$guard->method('mayAct')->willReturn($mayReview);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$this->doubles = ['proposals' => $proposals];

		return new ProposalController($request, $proposals, $registry, $reader, $session, $guard, $userSession);
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
