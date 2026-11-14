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
			changes: [['property' => 'applicantPhone', 'proposedValue' => '0687654321']]
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testAColleagueProposesAsThemselvesOnTheStaffChannel()

	/**
	 * REQ-CPQ-002: a colleague proposes on a record they can read. The
	 * route asked only whether anybody was logged in, so any account could
	 * queue a proposal on any record id it named.
	 *
	 * @return void
	 */
	public function testAColleagueWhoCannotReadTheRecordProposesNothing(): void {
		$controller = $this->controller(subject: null, user: $this->user('colleague-bob'), mayReview: false, mayRead: false);
		$this->doubles['proposals']->expects($this->never())->method('propose');

		$response = $controller->proposeAsColleague(
			register: 'dossiq',
			schema: 'zaak',
			id: 'zaak-1',
			changes: [['property' => 'applicantPhone', 'proposedValue' => '0687654321']]
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testAColleagueWhoCannotReadTheRecordProposesNothing()

	/**
	 * REQ-CPC-003: the colleague route proposes against the server's
	 * allow-list, whatever the request carries next to the changes.
	 *
	 * @return void
	 */
	public function testAColleagueProposesAgainstTheDeclaredAllowListNotTheRequests(): void {
		$controller = $this->controller(subject: null, user: $this->user('colleague-bob'), mayReview: false);
		$this->doubles['proposals']->expects($this->once())
			->method('propose')
			->with(
				$this->equalTo(['register' => 'dossiq', 'schema' => 'zaak', 'id' => 'zaak-1']),
				$this->equalTo([['property' => 'status', 'proposedValue' => 'closed']]),
				$this->equalTo(['applicantPhone']),
				$this->equalTo([]),
				$this->equalTo('colleague-bob'),
				$this->equalTo('staff'),
				$this->equalTo('Klopt niet')
			)
			->willReturn(['error' => 'property_not_proposable', 'property' => 'status']);

		$method = new \ReflectionMethod(ProposalController::class, 'proposeAsColleague');
		$names  = array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $method->getParameters());
		$this->assertNotContains('proposable', $names, 'the allow-list is not a request parameter');

		$response = $controller->proposeAsColleague(
			register: 'dossiq',
			schema: 'zaak',
			id: 'zaak-1',
			changes: [['property' => 'status', 'proposedValue' => 'closed']],
			note: 'Klopt niet'
		);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'property_not_proposable', 'property' => 'status'], $response->getData());
	}//end testAColleagueProposesAgainstTheDeclaredAllowListNotTheRequests()

	/**
	 * REQ-CPC-003: a record no contribution declares a propose-change for
	 * takes no colleague proposal.
	 *
	 * @return void
	 */
	public function testARecordWithoutADeclarationTakesNoColleagueProposal(): void {
		$controller = $this->controller(
			subject: null,
			user: $this->user('colleague-bob'),
			mayReview: false,
			aggregate: ['contributions' => [['app' => 'dossiq', 'actions' => [['type' => 'propose-change', 'register' => 'dossiq', 'schema' => 'other', 'proposable' => ['applicantPhone']]]]]]
		);
		$this->doubles['proposals']->expects($this->never())->method('propose');

		$response = $controller->proposeAsColleague(
			register: 'dossiq',
			schema: 'zaak',
			id: 'zaak-1',
			changes: [['property' => 'applicantPhone', 'proposedValue' => '0687654321']]
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'not_proposable'], $response->getData());
	}//end testARecordWithoutADeclarationTakesNoColleagueProposal()

	/**
	 * REQ-CPC-002: only the signed-in colleague's own staff proposals on
	 * this record are listed.
	 *
	 * @return void
	 */
	public function testMineOnARecordListsOnlyTheColleaguesOwnProposalsThere(): void {
		$controller = $this->controller(subject: null, user: $this->user('colleague-bob'), mayReview: false);
		$own        = ['uuid' => 'p-1', 'proposedBy' => 'colleague-bob', 'channel' => 'staff', 'subjectRegister' => 'dossiq', 'subjectSchema' => 'zaak', 'subjectId' => 'zaak-1', 'state' => 'withdrawn'];
		$this->doubles['proposals']->expects($this->once())
			->method('mine')
			->with('colleague-bob')
			->willReturn([
				$own,
				['uuid' => 'p-2', 'proposedBy' => 'colleague-bob', 'channel' => 'staff', 'subjectRegister' => 'dossiq', 'subjectSchema' => 'zaak', 'subjectId' => 'zaak-2'],
				['uuid' => 'p-3', 'proposedBy' => 'colleague-bob', 'channel' => 'portal', 'subjectRegister' => 'dossiq', 'subjectSchema' => 'zaak', 'subjectId' => 'zaak-1'],
			]);

		$response = $controller->mineOnRecord(register: 'dossiq', schema: 'zaak', id: 'zaak-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['proposals' => [$own]], $response->getData());
	}//end testMineOnARecordListsOnlyTheColleaguesOwnProposalsThere()

	/**
	 * REQ-CPC-002: a user who may not read the record gets 403 and no read.
	 *
	 * @return void
	 */
	public function testMineOnAnUnreadableRecordIsForbiddenAndReadsNothing(): void {
		$controller = $this->controller(subject: null, user: $this->user('colleague-bob'), mayReview: false, mayRead: false);
		$this->doubles['proposals']->expects($this->never())->method('mine');

		$response = $controller->mineOnRecord(register: 'dossiq', schema: 'zaak', id: 'zaak-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'forbidden'], $response->getData());
	}//end testMineOnAnUnreadableRecordIsForbiddenAndReadsNothing()

	/**
	 * REQ-CPC-001: the widget learns whether the user reviews, and the
	 * proposable properties with their values now.
	 *
	 * @return void
	 */
	public function testTheContextNamesTheReviewRightAndTheProposableValues(): void {
		$controller = $this->controller(
			subject: null,
			user: $this->user('colleague-bob'),
			mayReview: false,
			aggregate: ['contributions' => [['app' => 'dossiq', 'actions' => [['type' => 'propose-change', 'register' => 'dossiq', 'schema' => 'zaak', 'proposable' => ['applicantPhone', 'applicantEmail']]]]]],
			subjectRow: ['applicantPhone' => '0612345678', 'status' => 'open']
		);

		$response = $controller->context(register: 'dossiq', schema: 'zaak', id: 'zaak-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([
			'mayReview' => false,
			'proposable' => [
				['property' => 'applicantPhone', 'value' => '0612345678'],
				['property' => 'applicantEmail', 'value' => null],
			],
		], $response->getData());
	}//end testTheContextNamesTheReviewRightAndTheProposableValues()

	/**
	 * REQ-CPC-001: a reviewer is told so; with no declaration nothing is
	 * proposable and the record is not read.
	 *
	 * @return void
	 */
	public function testTheContextOfAReviewerOnAnUndeclaredRecord(): void {
		$controller = $this->controller(subject: null, user: $this->user('handler-anna'), mayReview: true, aggregate: ['contributions' => []]);
		$this->doubles['reader']->expects($this->never())->method('readObject');

		$response = $controller->context(register: 'dossiq', schema: 'zaak', id: 'zaak-1');

		$this->assertSame(['mayReview' => true, 'proposable' => []], $response->getData());
	}//end testTheContextOfAReviewerOnAnUndeclaredRecord()

	/**
	 * REQ-CPC-001: a user who may not read the record learns nothing about it.
	 *
	 * @return void
	 */
	public function testTheContextIsRefusedToAUserWhoMayNotReadTheRecord(): void {
		$controller = $this->controller(subject: null, user: $this->user('colleague-bob'), mayReview: true, mayRead: false);
		$this->doubles['reader']->expects($this->never())->method('readObject');

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->context(register: 'dossiq', schema: 'zaak', id: 'zaak-1')->getStatus());
		$anonymous = $this->controller(subject: null, user: null);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $anonymous->context(register: 'dossiq', schema: 'zaak', id: 'zaak-1')->getStatus());
	}//end testTheContextIsRefusedToAUserWhoMayNotReadTheRecord()

	/**
	 * A colleague withdraws their own staff proposal as themselves.
	 *
	 * @return void
	 */
	public function testAColleagueWithdrawsTheirOwnStaffProposal(): void {
		$proposal   = ['uuid' => 'p-1', 'channel' => 'staff', 'proposedBy' => 'colleague-bob', 'state' => 'queued'];
		$controller = $this->controller(subject: null, user: $this->user('colleague-bob'), proposalRow: $proposal);
		$this->doubles['proposals']->expects($this->once())
			->method('withdraw')
			->with($proposal, 'colleague-bob')
			->willReturn(['withdrawn' => true]);

		$response = $controller->withdrawAsColleague(id: 'p-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['withdrawn' => true], $response->getData());
	}//end testAColleagueWithdrawsTheirOwnStaffProposal()

	/**
	 * A portal proposal, a refusal by the queue, a missing proposal and no
	 * session all withdraw nothing.
	 *
	 * @return void
	 */
	public function testAColleagueWithdrawsNothingElse(): void {
		$portal = $this->controller(subject: null, user: $this->user('subject-1'), proposalRow: ['uuid' => 'p-1', 'channel' => 'portal', 'proposedBy' => 'subject-1', 'state' => 'queued']);
		$this->doubles['proposals']->expects($this->never())->method('withdraw');
		$this->assertSame(Http::STATUS_FORBIDDEN, $portal->withdrawAsColleague(id: 'p-1')->getStatus());

		$other = $this->controller(subject: null, user: $this->user('colleague-carl'), proposalRow: ['uuid' => 'p-1', 'channel' => 'staff', 'proposedBy' => 'colleague-bob', 'state' => 'queued']);
		$this->doubles['proposals']->method('withdraw')->willReturn(['error' => 'forbidden']);
		$this->assertSame(Http::STATUS_FORBIDDEN, $other->withdrawAsColleague(id: 'p-1')->getStatus());

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(subject: null, user: $this->user('colleague-bob'))->withdrawAsColleague(id: '')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(subject: null, user: null)->withdrawAsColleague(id: 'p-1')->getStatus());
	}//end testAColleagueWithdrawsNothingElse()

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
		bool $mayRead = true,
		?array $proposalRow = null,
	): ProposalController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');

		$proposals = $this->getMockBuilder(ProposalService::class)
			->disableOriginalConstructor()
			->onlyMethods(['propose', 'accept', 'reject', 'withdraw', 'forSubject', 'mine'])
			->getMock();

		$registry = $this->getMockBuilder(PortalContributionRegistry::class)
			->disableOriginalConstructor()
			->onlyMethods(['aggregateFor', 'servedAudiences'])
			->getMock();
		$registry->method('servedAudiences')->willReturn(['employee']);
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
		$proposalRow ??= ['uuid' => 'proposal-1', 'subjectRegister' => 'dossiq', 'subjectSchema' => 'zaak', 'subjectId' => 'zaak-1', 'state' => 'queued', 'proposedBy' => 'subject-1', 'changes' => []];
		$reader->method('readObject')->willReturnCallback(
			static function (string $register, string $schema) use ($subjectRow, $proposalRow): ?array {
				if ($schema === 'changeProposal') {
					return $proposalRow;
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
			->onlyMethods(['mayAct', 'mayRead'])
			->getMock();
		$guard->method('mayAct')->willReturn($mayReview);
		$guard->method('mayRead')->willReturn($mayRead);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		$this->doubles = ['proposals' => $proposals, 'registry' => $registry, 'reader' => $reader];

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
