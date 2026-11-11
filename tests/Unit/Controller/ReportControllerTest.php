<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\ReportController;
use OCA\Portaliq\Service\CaseTypeReader;
use OCA\Portaliq\Service\Identity\PortalChallengeService;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Reports\ReportIntakeService;
use OCA\Portaliq\Service\Reports\ReportProjection;
use OCA\Portaliq\Service\Reports\ReportTermsService;
use OCA\Portaliq\Service\Reports\ReportThreadService;
use OCA\Portaliq\Service\Reports\RevealService;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The wire contract of the reporting endpoints: what each route answers, and
 * what it refuses to answer.
 *
 * The projection is the REAL one rather than a double. Every one of these
 * endpoints exists to keep a reporter's contact details off the wire, and a
 * doubled projection would return whatever the test told it to, which is the
 * one thing that must not be assumed here.
 *
 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
 */
class ReportControllerTest extends TestCase {

	/**
	 * The doubles the controller under test is built from.
	 *
	 * @var array<string, mixed>
	 */
	private array $doubles = [];

	/**
	 * The portal slug each resolve() was asked for.
	 *
	 * @var array<int, string|null>
	 */
	private array $resolvedSlugs = [];

	public function testAFilingOnNoPortalIsNotFound(): void {
		$controller = $this->controller(site: null);
		$this->doubles['intake']->expects($this->never())->method('accept');

		$response = $controller->file(caseType: 'misstand');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'portal_not_found'], $response->getData());

	}//end testAFilingOnNoPortalIsNotFound()

	/**
	 * A filing lands on the portal the site names, as its challenge did: the
	 * X-Portaliq-Portal header first, else `?portal=`.
	 *
	 * @return void
	 */
	public function testAFilingResolvesThePortalTheSiteNames(): void {
		$controller = $this->controller();
		$this->doubles['request']->method('getHeader')->willReturnCallback(
			static fn (string $name): string => $name === 'X-Portaliq-Portal' ? 'gemeente-y' : ''
		);
		$this->doubles['challenge']->method('accepts')->willReturn(false);

		$controller->file(caseType: 'misstand', report: ['body' => 'Er klopt iets niet.']);

		$this->assertSame(['gemeente-y'], $this->resolvedSlugs);

	}//end testAFilingResolvesThePortalTheSiteNames()

	/**
	 * Without the header, `?portal=` names the portal.
	 *
	 * @return void
	 */
	public function testAFilingWithoutTheHeaderResolvesThePortalParameter(): void {
		$controller = $this->controller();
		$this->doubles['request']->method('getHeader')->willReturn('');
		$this->doubles['request']->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $key === 'portal' ? 'gemeente-z' : $default
		);
		$this->doubles['challenge']->method('accepts')->willReturn(false);

		$controller->file(caseType: 'misstand', report: ['body' => 'Er klopt iets niet.']);

		$this->assertSame(['gemeente-z'], $this->resolvedSlugs);

	}//end testAFilingWithoutTheHeaderResolvesThePortalParameter()

	public function testAnUnsolvedChallengeStopsTheFilingBeforeAnythingIsAccepted(): void {
		$controller = $this->controller();
		$this->doubles['challenge']->method('accepts')->willReturn(false);
		$this->doubles['intake']->expects($this->never())->method('accept');

		$response = $controller->file(caseType: 'misstand', report: ['body' => 'Er klopt iets niet.']);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'challenge_failed'], $response->getData());

	}//end testAnUnsolvedChallengeStopsTheFilingBeforeAnythingIsAccepted()

	public function testAnEmptyReportIsRefusedWithNothingRecorded(): void {
		$controller = $this->controller();
		$this->doubles['challenge']->method('accepts')->willReturn(true);
		$this->doubles['intake']->expects($this->never())->method('accept');

		$response = $controller->file(caseType: 'misstand', report: ['body' => '   ']);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'report_empty'], $response->getData());

	}//end testAnEmptyReportIsRefusedWithNothingRecorded()

	public function testAnAcceptedFilingAnswersTheCodeOnceAndSaysItCannotBeRecovered(): void {
		$controller = $this->controller();
		$this->doubles['challenge']->method('accepts')->willReturn(true);
		$this->doubles['intake']->method('accept')->willReturn(['code' => 'MELD-7Q2X', 'id' => 'report-1']);

		$data = $controller->file(caseType: 'misstand', report: ['body' => 'Er klopt iets niet.'])->getData();

		$this->assertSame('MELD-7Q2X', $data['code']);
		$this->assertTrue($data['codeShownOnce']);
		$this->assertFalse($data['recoverable']);

	}//end testAnAcceptedFilingAnswersTheCodeOnceAndSaysItCannotBeRecovered()

	/**
	 * The challenge is checked against the report with every proof value the
	 * filing carried, the intake gets the report and contact on their case
	 * type, the whole answer is the code once, and an intake that accepts
	 * nothing is a 503.
	 *
	 * @return void
	 */
	public function testAFilingHandsEveryValueOnAndAnUnacceptedOneIs503(): void {
		$controller = $this->controller();
		$this->doubles['challenge']->expects($this->exactly(2))->method('accepts')
			->with(['slug' => 'gemeente-x'], 'report', ['body' => 'Er klopt iets niet.'], 'n-1', 's-1', 1700000000, 'sig-1')
			->willReturn(true);
		$this->doubles['intake']->expects($this->exactly(2))->method('accept')
			->with('gemeente-x', 'misstand', ['body' => 'Er klopt iets niet.'], ['email' => 'a@example.org'], 'reg', 'sch')
			->willReturnOnConsecutiveCalls(['code' => 'MELD-1', 'id' => 'report-1'], null);

		$filed = fn () => $controller->file(
			caseType: 'misstand',
			register: 'reg',
			schema: 'sch',
			report: ['body' => 'Er klopt iets niet.'],
			contact: ['email' => 'a@example.org'],
			nonce: 'n-1',
			solution: 's-1',
			expiresAt: 1700000000,
			signature: 'sig-1'
		);

		$this->assertSame(['code' => 'MELD-1', 'codeShownOnce' => true, 'recoverable' => false], $filed()->getData());
		$refused = $filed();
		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $refused->getStatus());
		$this->assertSame(['error' => 'not_accepted'], $refused->getData());

	}//end testAFilingHandsEveryValueOnAndAnUnacceptedOneIs503()

	public function testACodeThatOpensNothingIsAnsweredExactlyLikeAWrongOne(): void {
		$controller = $this->controller();
		$this->doubles['threads']->method('openByCode')->willReturn(null);
		$this->doubles['threads']->expects($this->never())->method('messagesForReporter');

		$response = $controller->thread(code: 'MELD-BESTAATNIET');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'code_not_valid'], $response->getData());

	}//end testACodeThatOpensNothingIsAnsweredExactlyLikeAWrongOne()

	public function testAnOpenedThreadCarriesNoContactDetail(): void {
		$controller = $this->controller();
		$this->doubles['threads']->method('openByCode')->willReturn($this->storedReport());
		$this->doubles['threads']->method('messagesForReporter')->willReturn([['body' => 'Wij kijken ernaar.']]);
		$this->doubles['terms']->method('forReport')->willReturn(['acknowledgement' => 'op tijd']);

		$data = $controller->thread(code: 'MELD-7Q2X')->getData();

		// The needles are the values the STORED row really carries, so the
		// assertion fails if the raw row is ever served. A needle the fixture
		// does not contain would pass whatever the endpoint returned.
		$serialised = json_encode($data, JSON_THROW_ON_ERROR);
		$this->assertSame('report-1', $data['report']['id']);
		$this->assertArrayNotHasKey('contactRef', $data['report']);
		$this->assertStringNotContainsString('contact-1', $serialised);
		$this->assertStringNotContainsString('hash-of-the-code', $serialised);
		$this->assertStringContainsString('Er klopt iets niet.', $serialised);
		$this->assertSame('op tijd', $data['terms']['acknowledgement']);

	}//end testAnOpenedThreadCarriesNoContactDetail()

	public function testAnAnswerOnAnUnopenedThreadWritesNothing(): void {
		$controller = $this->controller();
		$this->doubles['threads']->method('openByCode')->willReturn(null);
		$this->doubles['threads']->expects($this->never())->method('write');

		$response = $controller->answer(code: 'MELD-BESTAATNIET', body: 'Nog iets.');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testAnAnswerOnAnUnopenedThreadWritesNothing()

	public function testAWrittenAnswerSaysSo(): void {
		$controller = $this->controller();
		$this->doubles['threads']->method('openByCode')->willReturn($this->storedReport());
		$this->doubles['threads']->method('write')->willReturn(true);

		$this->assertSame(['written' => true], $controller->answer(code: 'MELD-7Q2X', body: 'Nog iets.')->getData());

	}//end testAWrittenAnswerSaysSo()

	public function testTheStaffReadNeedsAUser(): void {
		$controller = $this->controller(user: false);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->show(id: 'report-1')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->reply(id: 'report-1', body: 'Nota.')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->requestReveal(id: 'report-1')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->decideReveal(id: 'request-1')->getStatus());

	}//end testTheStaffReadNeedsAUser()

	public function testTheStaffReadStillCarriesNoContactDetail(): void {
		$controller = $this->controller();
		$this->doubles['reader']->method('readObjectAsUser')->willReturn($this->storedReport());
		$this->doubles['threads']->method('allMessages')->willReturn([]);

		$serialised = json_encode($controller->show(id: 'report-1')->getData(), JSON_THROW_ON_ERROR);

		$this->assertStringNotContainsString('contact-1', $serialised);
		$this->assertStringNotContainsString('hash-of-the-code', $serialised);
		$this->assertStringContainsString('Er klopt iets niet.', $serialised);

	}//end testTheStaffReadStillCarriesNoContactDetail()

	public function testAHandlerNoteIsInternalUnlessTheHandlerSaysOtherwise(): void {
		$controller = $this->controller();
		$this->doubles['reader']->method('readObjectAsUser')->willReturn($this->storedReport());
		$this->doubles['threads']
			->expects($this->once())
			->method('write')
			->with('report-1', 'handler', 'Nota.', false, 'Behandelaar B')
			->willReturn(true);

		$this->assertSame(['written' => true], $controller->reply(id: 'report-1', body: 'Nota.')->getData());

	}//end testAHandlerNoteIsInternalUnlessTheHandlerSaysOtherwise()

	public function testARevealRequestWithoutAMotivationRecordsNothing(): void {
		$controller = $this->controller();
		$this->doubles['reader']->method('readObjectAsUser')->willReturn($this->storedReport());
		$this->doubles['reveals']->method('request')->willReturn(null);

		$response = $controller->requestReveal(id: 'report-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'motivation_required'], $response->getData());

	}//end testARevealRequestWithoutAMotivationRecordsNothing()

	public function testARecordedRevealRequestIsPending(): void {
		$controller = $this->controller();
		$this->doubles['reader']->method('readObjectAsUser')->willReturn($this->storedReport());
		$this->doubles['reveals']->method('request')->willReturn(['id' => 'request-1']);

		$response = $controller->requestReveal(id: 'report-1', motivation: 'Nodig voor de aangifte.');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['state' => 'pending'], $response->getData());

	}//end testARecordedRevealRequestIsPending()

	public function testACallerWhoIsNotTheCustodianIsRefusedAndShownNothing(): void {
		$controller = $this->controller();
		$this->doubles['reader']->method('readObject')->willReturn(['reportRef' => 'report-1'] + $this->storedReport());
		$this->doubles['reader']->method('readObjectAsUser')->willReturn($this->storedReport());
		$this->doubles['reveals']->method('decide')->willReturn(['error' => 'not_custodian']);

		$response = $controller->decideReveal(id: 'request-1', allow: true);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'not_custodian'], $response->getData());

	}//end testACallerWhoIsNotTheCustodianIsRefusedAndShownNothing()

	public function testADecisionOnAnAlreadyAnsweredRequestConflicts(): void {
		$controller = $this->controller();
		$this->doubles['reader']->method('readObject')->willReturn(['reportRef' => 'report-1'] + $this->storedReport());
		$this->doubles['reader']->method('readObjectAsUser')->willReturn($this->storedReport());
		$this->doubles['reveals']->method('decide')->willReturn(['error' => 'already_decided']);

		$this->assertSame(Http::STATUS_CONFLICT, $controller->decideReveal(id: 'request-1')->getStatus());

	}//end testADecisionOnAnAlreadyAnsweredRequestConflicts()

	public function testAnUnknownRevealRequestIsNotFound(): void {
		$controller = $this->controller();
		$this->doubles['reader']->method('readObject')->willReturn(null);
		$this->doubles['reveals']->expects($this->never())->method('decide');

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->decideReveal(id: 'request-bestaatniet')->getStatus());

	}//end testAnUnknownRevealRequestIsNotFound()

	/**
	 * A decision needs a signed-in user, and the custodian's own answer
	 * reaches the reveal service with its reason.
	 *
	 * @return void
	 */
	public function testADecisionNeedsAUserAndCarriesTheAnswer(): void {
		$anonymous = $this->controller(user: false);
		$this->doubles['reveals']->expects($this->never())->method('decide');
		$response = $anonymous->decideReveal(id: 'request-1', allow: true, reason: 'x');
		$this->assertSame([Http::STATUS_UNAUTHORIZED, ['error' => 'not_authenticated']], [$response->getStatus(), $response->getData()]);

		$controller = $this->controller();
		$this->doubles['reader']->method('readObject')->willReturn(['reportRef' => 'report-1'] + $this->storedReport());
		$this->doubles['reader']->method('readObjectAsUser')->willReturn($this->storedReport());
		$this->doubles['reveals']->expects($this->once())->method('decide')
			->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), true, 'Gegronde reden.')
			->willReturn(['state' => 'allowed', 'contact' => ['email' => 'a@example.org']]);

		$allowed = $controller->decideReveal(id: 'request-1', allow: true, reason: 'Gegronde reden.');
		$this->assertSame([Http::STATUS_OK, ['state' => 'allowed', 'contact' => ['email' => 'a@example.org']]], [$allowed->getStatus(), $allowed->getData()]);

	}//end testADecisionNeedsAUserAndCarriesTheAnswer()

	/**
	 * A signed-in colleague in neither the handler nor the custodian group
	 * gets the same 404 an unknown report gets, on all three staff routes, and
	 * nothing is written (portaliq#799, REQ-IRP-005).
	 */
	public function testAColleagueOutsideTheGroupsCannotReadAnswerOrAskAboutAReport(): void {
		$controller = $this->controller(groups: $this->groupManager(member: []));
		$this->doubles['reader']->method('readObject')->willReturn($this->storedReport());
		$this->doubles['reader']->method('readObjectAsUser')->willReturn($this->storedReport());
		$this->doubles['threads']->method('allMessages')->willReturn([['body' => 'Interne notitie.']]);
		$this->doubles['threads']->expects($this->never())->method('write');
		$this->doubles['writer']->expects($this->never())->method('createObject');

		$show = $controller->show(id: 'report-1');
		$reply = $controller->reply(id: 'report-1', body: 'Ik ben geen behandelaar.', visibleToReporter: true);
		$reveal = $controller->requestReveal(id: 'report-1', motivation: 'Nieuwsgierig.');

		foreach ([$show, $reply, $reveal] as $response) {
			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			$this->assertSame(['error' => 'report_not_found'], $response->getData());
		}

		$this->assertStringNotContainsString('Interne notitie.', json_encode($show->getData(), JSON_THROW_ON_ERROR));

	}//end testAColleagueOutsideTheGroupsCannotReadAnswerOrAskAboutAReport()

	/**
	 * A member of the declared handler group reads the report.
	 */
	public function testAMemberOfTheHandlerGroupReadsTheReport(): void {
		$controller = $this->controller(groups: $this->groupManager(member: ['meldpunt']));
		$this->doubles['reader']->method('readObjectAsUser')->willReturn($this->storedReport());
		$this->doubles['threads']->method('allMessages')->willReturn([]);

		$this->assertSame(Http::STATUS_OK, $controller->show(id: 'report-1')->getStatus());

	}//end testAMemberOfTheHandlerGroupReadsTheReport()

	/**
	 * Without a declared handler group the custodian group handles, and a
	 * member of a group the declaration does not name does not.
	 */
	public function testTheCustodianHandlesWhenNoHandlerGroupIsDeclared(): void {
		$declaration = [ReportTermsService::DECLARATION => ['custodianGroup' => 'vertrouwenspersonen']];

		$custodian = $this->controller(groups: $this->groupManager(member: ['vertrouwenspersonen']), caseType: $declaration);
		$this->doubles['reader']->method('readObjectAsUser')->willReturn($this->storedReport());
		$this->doubles['threads']->method('allMessages')->willReturn([]);
		$this->assertSame(Http::STATUS_OK, $custodian->show(id: 'report-1')->getStatus());

		$handler = $this->controller(groups: $this->groupManager(member: ['meldpunt']), caseType: $declaration);
		$this->doubles['reader']->method('readObjectAsUser')->willReturn($this->storedReport());
		$this->assertSame(Http::STATUS_NOT_FOUND, $handler->show(id: 'report-1')->getStatus());

	}//end testTheCustodianHandlesWhenNoHandlerGroupIsDeclared()

	/**
	 * A group manager in which the staff user is a member of the named groups.
	 *
	 * @param array<int, string> $member The groups behandelaar-b is in.
	 *
	 * @return IGroupManager
	 */
	private function groupManager(array $member): IGroupManager {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => ($uid === 'behandelaar-b' && in_array($group, $member, true) === true)
		);

		return $groups;
	}//end groupManager()

	/**
	 * A stored report row, carrying the two things that must never be served.
	 *
	 * @return array<string, mixed>
	 */
	private function storedReport(): array {
		return [
			'id' => 'report-1',
			'portal' => 'gemeente-x',
			'caseType' => 'misstand',
			'subject' => 'Onveilige situatie',
			'body' => 'Er klopt iets niet.',
			'state' => 'open',
			'contactRef' => 'contact-1',
			'codeHash' => 'hash-of-the-code',
		];
	}//end storedReport()

	/**
	 * The controller over doubles, with the real projection.
	 *
	 * @param array<string, mixed>|null $site The portal the request lands on.
	 * @param bool                      $user Whether a staff user is signed in.
	 * @param IGroupManager|null        $groups When given, the REAL reveal service
	 *                                          decides who may handle, over these groups;
	 *                                          otherwise a double lets every handler through.
	 * @param array<string, mixed>|null $caseType The case type the declaration is read from.
	 *
	 * @return ReportController
	 */
	private function controller(
		?array $site = ['slug' => 'gemeente-x'],
		bool $user = true,
		?IGroupManager $groups = null,
		?array $caseType = null,
	): ReportController {
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('203.0.113.9');

		$portals = $this->double(PortalResolver::class, ['resolve']);
		$portals->method('resolve')->willReturnCallback(
			function (IRequest $request, ?string $portalSlug = null) use ($site): ?array {
				$this->resolvedSlugs[] = $portalSlug;
				return $site;
			}
		);

		$session = $this->createMock(IUserSession::class);
		if ($user === true) {
			$staff = $this->createMock(IUser::class);
			$staff->method('getUID')->willReturn('behandelaar-b');
			$staff->method('getDisplayName')->willReturn('Behandelaar B');
			$session->method('getUser')->willReturn($staff);
		} else {
			$session->method('getUser')->willReturn(null);
		}

		$caseTypes = $this->double(CaseTypeReader::class, ['readCaseType']);
		$caseTypes->method('readCaseType')->willReturn(
			$caseType ?? [ReportTermsService::DECLARATION => ['custodianGroup' => 'vertrouwenspersonen', 'handlerGroup' => 'meldpunt']]
		);

		$this->doubles = [
			'request' => $request,
			'challenge' => $this->double(PortalChallengeService::class, ['accepts']),
			'intake' => $this->double(ReportIntakeService::class, ['accept']),
			'threads' => $this->double(ReportThreadService::class, ['openByCode', 'messagesForReporter', 'allMessages', 'write']),
			'terms' => $this->double(ReportTermsService::class, ['forReport']),
			'reveals' => $this->double(RevealService::class, ['request', 'decide', 'mayHandle']),
			'reader' => $this->double(PortalObjectReader::class, ['readObject', 'readObjectAsUser']),
			'writer' => $this->double(PortalObjectWriter::class, ['createObject', 'updateObject']),
		];
		$this->doubles['reveals']->method('mayHandle')->willReturn(true);
		if ($groups !== null) {
			$this->doubles['reveals'] = new RevealService(
				$this->doubles['reader'],
				$this->doubles['writer'],
				$groups,
				$this->createMock(IEventDispatcher::class)
			);
		}

		return new ReportController(
			$request,
			$portals,
			$this->doubles['challenge'],
			$this->doubles['intake'],
			$this->doubles['threads'],
			$this->doubles['terms'],
			$this->doubles['reveals'],
			new ReportProjection(),
			$this->doubles['reader'],
			$caseTypes,
			$session
		);
	}//end controller()

	/**
	 * A double of one class, limited to the methods it really has.
	 *
	 * @param string $class The class to double.
	 * @param array<int, string> $methods The methods to stub.
	 *
	 * @return mixed
	 */
	private function double(string $class, array $methods): mixed {
		return $this->getMockBuilder($class)
			->disableOriginalConstructor()
			->onlyMethods($methods)
			->getMock();
	}//end double()

}//end class
