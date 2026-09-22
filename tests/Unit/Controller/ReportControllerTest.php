<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\ReportController;
use OCA\Portaliq\Service\CaseTypeReader;
use OCA\Portaliq\Service\Identity\PortalChallengeService;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Reports\ReportIntakeService;
use OCA\Portaliq\Service\Reports\ReportProjection;
use OCA\Portaliq\Service\Reports\ReportTermsService;
use OCA\Portaliq\Service\Reports\ReportThreadService;
use OCA\Portaliq\Service\Reports\RevealService;
use OCP\AppFramework\Http;
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

	public function testAFilingOnNoPortalIsNotFound(): void {
		$controller = $this->controller(site: null);
		$this->doubles['intake']->expects($this->never())->method('accept');

		$response = $controller->file(caseType: 'misstand');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'portal_not_found'], $response->getData());

	}//end testAFilingOnNoPortalIsNotFound()

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
		$this->doubles['reader']->method('readObject')->willReturn($this->storedReport());
		$this->doubles['threads']->method('allMessages')->willReturn([]);

		$serialised = json_encode($controller->show(id: 'report-1')->getData(), JSON_THROW_ON_ERROR);

		$this->assertStringNotContainsString('contact-1', $serialised);
		$this->assertStringNotContainsString('hash-of-the-code', $serialised);
		$this->assertStringContainsString('Er klopt iets niet.', $serialised);

	}//end testTheStaffReadStillCarriesNoContactDetail()

	public function testAHandlerNoteIsInternalUnlessTheHandlerSaysOtherwise(): void {
		$controller = $this->controller();
		$this->doubles['reader']->method('readObject')->willReturn($this->storedReport());
		$this->doubles['threads']
			->expects($this->once())
			->method('write')
			->with('report-1', 'handler', 'Nota.', false, 'Behandelaar B')
			->willReturn(true);

		$this->assertSame(['written' => true], $controller->reply(id: 'report-1', body: 'Nota.')->getData());

	}//end testAHandlerNoteIsInternalUnlessTheHandlerSaysOtherwise()

	public function testARevealRequestWithoutAMotivationRecordsNothing(): void {
		$controller = $this->controller();
		$this->doubles['reader']->method('readObject')->willReturn($this->storedReport());
		$this->doubles['reveals']->method('request')->willReturn(null);

		$response = $controller->requestReveal(id: 'report-1');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'motivation_required'], $response->getData());

	}//end testARevealRequestWithoutAMotivationRecordsNothing()

	public function testARecordedRevealRequestIsPending(): void {
		$controller = $this->controller();
		$this->doubles['reader']->method('readObject')->willReturn($this->storedReport());
		$this->doubles['reveals']->method('request')->willReturn(['id' => 'request-1']);

		$response = $controller->requestReveal(id: 'report-1', motivation: 'Nodig voor de aangifte.');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['state' => 'pending'], $response->getData());

	}//end testARecordedRevealRequestIsPending()

	public function testACallerWhoIsNotTheCustodianIsRefusedAndShownNothing(): void {
		$controller = $this->controller();
		$this->doubles['reader']->method('readObject')->willReturn(['reportRef' => 'report-1'] + $this->storedReport());
		$this->doubles['reveals']->method('decide')->willReturn(['error' => 'not_custodian']);

		$response = $controller->decideReveal(id: 'request-1', allow: true);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'not_custodian'], $response->getData());

	}//end testACallerWhoIsNotTheCustodianIsRefusedAndShownNothing()

	public function testADecisionOnAnAlreadyAnsweredRequestConflicts(): void {
		$controller = $this->controller();
		$this->doubles['reader']->method('readObject')->willReturn(['reportRef' => 'report-1'] + $this->storedReport());
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
	 *
	 * @return ReportController
	 */
	private function controller(?array $site = ['slug' => 'gemeente-x'], bool $user = true): ReportController {
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('203.0.113.9');

		$portals = $this->double(PortalResolver::class, ['resolve']);
		$portals->method('resolve')->willReturn($site);

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
		$caseTypes->method('readCaseType')->willReturn(['custodianGroup' => 'vertrouwenspersonen']);

		$this->doubles = [
			'challenge' => $this->double(PortalChallengeService::class, ['accepts']),
			'intake' => $this->double(ReportIntakeService::class, ['accept']),
			'threads' => $this->double(ReportThreadService::class, ['openByCode', 'messagesForReporter', 'allMessages', 'write']),
			'terms' => $this->double(ReportTermsService::class, ['forReport']),
			'reveals' => $this->double(RevealService::class, ['request', 'decide']),
			'reader' => $this->double(PortalObjectReader::class, ['readObject']),
		];

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
