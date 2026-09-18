<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Reports;

use DateTimeImmutable;
use OCA\Portaliq\Event\PortalReportRevealedEvent;
use OCA\Portaliq\Service\Reports\ReportIntakeService;
use OCA\Portaliq\Service\Reports\ReportProjection;
use OCA\Portaliq\Service\Reports\ReportTermsService;
use OCA\Portaliq\Service\Reports\ReportThreadService;
use OCA\Portaliq\Service\Reports\RevealService;
use OCA\Portaliq\Tests\Unit\Service\Identity\PortalIdentityStoreTrait;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\TestCase;

/**
 * a-report-without-an-account REQ-RWA-001 to REQ-RWA-006: a report is accepted
 * from somebody who says nothing about themselves, the receipt code is the only
 * key back in and costs something to guess, whatever they did give is never in
 * an answer, and only the custodian the case type named may change that, on a
 * motivated request that is written down either way.
 *
 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
 */
class ReportWithoutAnAccountTest extends TestCase {
	use PortalIdentityStoreTrait;

	/**
	 * Failed code attempts the throttler was told about.
	 *
	 * @var array<int, string>
	 */
	private array $attempts = [];

	/**
	 * Reveal events that were raised.
	 *
	 * @var array<int, PortalReportRevealedEvent>
	 */
	private array $events = [];

	protected function setUp(): void {
		$this->rows = [];
		$this->attempts = [];
		$this->events = [];

	}//end setUp()

	public function testAReportIsAcceptedWithNoAccountAndNoContactDetail(): void {
		$accepted = $this->intake()->accept(portal: 'gemeente-x', caseType: 'misstandmelding', report: ['subject' => 'Onveilige situatie', 'body' => 'Er wordt gewerkt zonder keuring.']);

		$this->assertIsArray($accepted);
		$rows = $this->storedRows('portalReport');
		$this->assertCount(1, $rows);
		$this->assertSame('', $rows[0]['contactRef']);
		// Nothing was written that could be a person.
		$this->assertSame([], $this->storedRows('portalReporterContact'));

	}//end testAReportIsAcceptedWithNoAccountAndNoContactDetail()

	public function testTheCodeIsReturnedOnceAndStoredOnlyAsAHash(): void {
		$accepted = $this->intake()->accept(portal: 'gemeente-x', caseType: 'misstandmelding', report: ['body' => 'Melding.']);
		$row = $this->storedRows('portalReport')[0];

		$this->assertNotSame('', (string)$accepted['code']);
		// The code itself is nowhere in the stored row, in any field.
		$this->assertStringNotContainsString((string)$accepted['code'], json_encode($row, JSON_THROW_ON_ERROR));
		$this->assertSame(hash('sha256', (string)$accepted['code']), $row['codeHash']);

	}//end testTheCodeIsReturnedOnceAndStoredOnlyAsAHash()

	public function testNothingIdentifyingTheMachineIsStoredWithTheReport(): void {
		$this->intake()->accept(
			portal: 'gemeente-x',
			caseType: 'misstandmelding',
			report: ['body' => 'Melding.', 'ip' => '203.0.113.9', 'userAgent' => 'Firefox', 'address' => '203.0.113.9']
		);
		$row = $this->storedRows('portalReport')[0];

		$this->assertSame([], $row['answers']);
		$this->assertStringNotContainsString('203.0.113.9', json_encode($row, JSON_THROW_ON_ERROR));

	}//end testNothingIdentifyingTheMachineIsStoredWithTheReport()

	public function testWhatTheReporterGaveIsHeldApartAndIsNeverInAnAnswer(): void {
		$this->intake()->accept(
			portal: 'gemeente-x',
			caseType: 'misstandmelding',
			report: ['body' => 'Melding.'],
			contact: ['name' => 'Sanne Bakker', 'email' => 'sanne@example.org']
		);

		$report = $this->storedRows('portalReport')[0];
		$contact = $this->storedRows('portalReporterContact');
		$this->assertCount(1, $contact);
		$this->assertSame('Sanne Bakker', $contact[0]['name']);
		// The report itself never carries the value, only the join.
		$this->assertStringNotContainsString('sanne@example.org', json_encode($report, JSON_THROW_ON_ERROR));

		$projected = (new ReportProjection())->one(report: $report);
		$this->assertArrayNotHasKey('contactRef', $projected);
		$this->assertArrayNotHasKey('codeHash', $projected);
		$this->assertStringNotContainsString('sanne@example.org', json_encode($projected, JSON_THROW_ON_ERROR));

	}//end testWhatTheReporterGaveIsHeldApartAndIsNeverInAnAnswer()

	public function testAContactFieldTheFormAskedForMovesOutOfTheReport(): void {
		// A reporting form that asks for an address puts it in the answers,
		// and answers live on the report. The one place that can catch it is
		// intake, so intake is where it is caught.
		$this->intake()->accept(
			portal: 'gemeente-x',
			caseType: 'misstandmelding',
			report: ['body' => 'Melding.', 'email' => 'sanne@example.org', 'afdeling' => 'Handhaving']
		);

		$report = $this->storedRows('portalReport')[0];
		$this->assertSame(['afdeling' => 'Handhaving'], $report['answers']);
		$this->assertStringNotContainsString('sanne@example.org', json_encode($report, JSON_THROW_ON_ERROR));
		$this->assertSame('sanne@example.org', $this->storedRows('portalReporterContact')[0]['email']);

	}//end testAContactFieldTheFormAskedForMovesOutOfTheReport()

	public function testAListAndAnExportCarryNoIdentityEither(): void {
		$this->intake()->accept(portal: 'gemeente-x', caseType: 'misstandmelding', report: ['subject' => 'Een', 'body' => 'Melding.'], contact: ['email' => 'sanne@example.org']);
		$this->intake()->accept(portal: 'gemeente-x', caseType: 'misstandmelding', report: ['subject' => 'Twee', 'body' => 'Andere melding.']);

		$exported = (new ReportProjection())->many($this->storedRows('portalReport'));

		$serialised = json_encode($exported, JSON_THROW_ON_ERROR);
		$this->assertStringNotContainsString('sanne@example.org', $serialised);
		$this->assertStringNotContainsString('contactRef', $serialised);
		$this->assertStringNotContainsString('codeHash', $serialised);
		$this->assertSame(['Een', 'Twee'], array_column($exported, 'subject'));

	}//end testAListAndAnExportCarryNoIdentityEither()

	public function testAValidCodeOpensTheThreadAndAWrongOneIsThrottled(): void {
		$accepted = $this->intake()->accept(portal: 'gemeente-x', caseType: 'misstandmelding', report: ['body' => 'Melding.']);
		$threads = $this->threads();

		$opened = $threads->openByCode(code: (string)$accepted['code'], address: '203.0.113.9');
		$this->assertIsArray($opened);
		$this->assertSame([], $this->attempts);

		$refused = $threads->openByCode(code: 'NIETDEJUISTECODE', address: '203.0.113.9');
		$this->assertNull($refused);
		$this->assertSame(['203.0.113.9'], $this->attempts);

	}//end testAValidCodeOpensTheThreadAndAWrongOneIsThrottled()

	public function testAnInternalNoteNeverReachesTheReporter(): void {
		$accepted = $this->intake()->accept(portal: 'gemeente-x', caseType: 'misstandmelding', report: ['body' => 'Melding.']);
		$reportId = $this->storedRows('portalReport')[0]['uuid'];
		$threads = $this->threads();

		$threads->write(reportId: $reportId, author: 'handler', body: 'Intern: doorgezet naar de vertrouwenspersoon.', visibleToReporter: false, authorName: 'Anna');
		$threads->write(reportId: $reportId, author: 'handler', body: 'Wij hebben uw melding ontvangen.', visibleToReporter: true, authorName: 'Anna');
		$threads->write(reportId: $reportId, author: 'reporter', body: 'Dank u.');

		$forReporter = $threads->messagesForReporter(reportId: $reportId);
		$bodies = array_column($forReporter, 'body');
		$this->assertNotContains('Intern: doorgezet naar de vertrouwenspersoon.', $bodies);
		$this->assertContains('Wij hebben uw melding ontvangen.', $bodies);
		$this->assertContains('Dank u.', $bodies);
		// The handler still sees all three.
		$this->assertCount(3, $threads->allMessages(reportId: $reportId));
		// And the reporter's own message carries no name.
		$this->assertSame('', $this->storedRows('portalReportMessage')[2]['authorName']);
		$this->assertSame((string)$accepted['reportId'], $reportId);

	}//end testAnInternalNoteNeverReachesTheReporter()

	public function testTheTermsComeFromTheDeclarationAndNeverFromAConstant(): void {
		$terms = new ReportTermsService();
		$report = ['receivedAt' => '2026-09-01T09:00:00+00:00'];
		$declared = [ReportTermsService::DECLARATION => ['acknowledgementDays' => 7, 'feedbackDays' => 90]];

		$rendered = $terms->forReport(caseType: $declared, report: $report, now: new DateTimeImmutable('2026-09-05T09:00:00+00:00'));

		$this->assertSame(['acknowledgement', 'feedback'], array_column($rendered, 'term'));
		$this->assertSame(3, $rendered[0]['daysLeft']);
		$this->assertFalse($rendered[0]['met']);
		// A case type that declares nothing yields nothing, rather than seven
		// days the organisation never promised.
		$this->assertSame([], $terms->forReport(caseType: [], report: $report));

	}//end testTheTermsComeFromTheDeclarationAndNeverFromAConstant()

	public function testARevealRequestWithoutAMotivationIsRefusedAndNothingIsWritten(): void {
		$reveals = $this->reveals();

		$this->assertNull($reveals->request(reportId: 'r-1', requestedBy: 'handler-anna', motivation: '   '));
		$this->assertSame([], $this->storedRows('portalRevealRequest'));

	}//end testARevealRequestWithoutAMotivationIsRefusedAndNothingIsWritten()

	public function testAnAdministratorWhoIsNotTheCustodianRevealsNothing(): void {
		[$reveals, $request, $report, $declaration] = $this->pendingRequest();
		// In no group at all, which is what an instance administrator who was
		// never named as custodian is here.
		$outcome = $reveals->decide(request: $request, report: $report, custodian: $this->user('admin', inGroup: false), caseType: $declaration, allow: true);

		$this->assertSame('not_custodian', $outcome['error']);
		$this->assertArrayNotHasKey('contact', $outcome);
		$this->assertSame('pending', $this->storedRows('portalRevealRequest')[0]['state']);
		$this->assertSame([], $this->events);

	}//end testAnAdministratorWhoIsNotTheCustodianRevealsNothing()

	public function testTheCustodianRevealsAndTheRecordNamesEverybody(): void {
		[$reveals, $request, $report, $declaration] = $this->pendingRequest();

		$outcome = $reveals->decide(request: $request, report: $report, custodian: $this->user('vertrouwenspersoon-lena'), caseType: $declaration, allow: true, reason: 'Nodig voor wederhoor.');

		$this->assertSame('allowed', $outcome['state']);
		$this->assertSame('sanne@example.org', $outcome['contact']['email']);

		$closed = $this->storedRows('portalRevealRequest')[0];
		$this->assertSame('allowed', $closed['state']);
		$this->assertSame('handler-anna', $closed['requestedBy']);
		$this->assertSame('Er is een tweede melding over dezelfde afdeling.', $closed['motivation']);
		$this->assertSame('vertrouwenspersoon-lena', $closed['decidedBy']);
		$this->assertSame(['name', 'email'], $closed['revealedFields']);
		$this->assertNotSame('', $closed['decidedAt']);
		// The names of the fields are on the record; the values are not.
		$this->assertStringNotContainsString('sanne@example.org', json_encode($closed, JSON_THROW_ON_ERROR));

		$this->assertCount(1, $this->events);
		$this->assertSame('vertrouwenspersoon-lena', $this->events[0]->getCustodian());
		$this->assertSame(['name', 'email'], $this->events[0]->getRevealedFields());

	}//end testTheCustodianRevealsAndTheRecordNamesEverybody()

	public function testARefusalIsRecordedAndRevealsNothing(): void {
		[$reveals, $request, $report, $declaration] = $this->pendingRequest();

		$outcome = $reveals->decide(request: $request, report: $report, custodian: $this->user('vertrouwenspersoon-lena'), caseType: $declaration, allow: false, reason: 'Niet noodzakelijk.');

		$this->assertSame(['state' => 'refused'], $outcome);
		$closed = $this->storedRows('portalRevealRequest')[0];
		$this->assertSame('refused', $closed['state']);
		$this->assertSame('Niet noodzakelijk.', $closed['decisionReason']);
		$this->assertSame([], $closed['revealedFields']);
		$this->assertSame([], $this->events);

	}//end testARefusalIsRecordedAndRevealsNothing()

	public function testACaseTypeThatNamedNoCustodianHasNobodyWhoMayReveal(): void {
		[$reveals, $request, $report] = $this->pendingRequest();

		$outcome = $reveals->decide(request: $request, report: $report, custodian: $this->user('vertrouwenspersoon-lena'), caseType: [ReportTermsService::DECLARATION => ['acknowledgementDays' => 7]], allow: true);

		$this->assertSame('not_custodian', $outcome['error']);

	}//end testACaseTypeThatNamedNoCustodianHasNobodyWhoMayReveal()

	/**
	 * A report with contact details, and a pending motivated request on it.
	 *
	 * @return array{0: RevealService, 1: array<string, mixed>, 2: array<string, mixed>, 3: array<string, mixed>}
	 */
	private function pendingRequest(): array {
		$this->intake()->accept(
			portal: 'gemeente-x',
			caseType: 'misstandmelding',
			report: ['body' => 'Melding.'],
			contact: ['name' => 'Sanne Bakker', 'email' => 'sanne@example.org']
		);
		$report = $this->storedRows('portalReport')[0];

		$reveals = $this->reveals();
		$reveals->request(reportId: (string)$report['uuid'], requestedBy: 'handler-anna', motivation: 'Er is een tweede melding over dezelfde afdeling.');

		return [
			$reveals,
			$this->storedRows('portalRevealRequest')[0],
			$report,
			[ReportTermsService::DECLARATION => ['custodianGroup' => 'vertrouwenspersonen', 'acknowledgementDays' => 7, 'feedbackDays' => 90]],
		];
	}//end pendingRequest()

	/**
	 * Intake over the fake store.
	 *
	 * @return ReportIntakeService
	 */
	private function intake(): ReportIntakeService {
		return new ReportIntakeService($this->fakeWriter(), $this->fakeRandom());
	}//end intake()

	/**
	 * The thread service over the fake store, with a recording throttler.
	 *
	 * @return ReportThreadService
	 */
	private function threads(): ReportThreadService {
		$throttler = $this->createMock(IThrottler::class);
		$throttler->method('registerAttempt')->willReturnCallback(
			function (string $action, string $ip, array $metadata = []): void {
				$this->attempts[] = $ip;
			}
		);

		return new ReportThreadService($this->fakeReader(), $this->fakeWriter(), $throttler);
	}//end threads()

	/**
	 * The reveal service over the fake store, with a recording dispatcher.
	 *
	 * @return RevealService
	 */
	private function reveals(): RevealService {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				if ($event instanceof PortalReportRevealedEvent) {
					$this->events[] = $event;
				}
			}
		);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			static function (string $uid, string $group): bool {
				return ($group === 'vertrouwenspersonen' && $uid === 'vertrouwenspersoon-lena');
			}
		);

		return new RevealService($this->fakeReader(), $this->fakeWriter(), $groups, $dispatcher);
	}//end reveals()

	/**
	 * A user double.
	 *
	 * @param string $uid The uid.
	 * @param bool $inGroup Unused marker kept for readability at the call site.
	 *
	 * @return IUser
	 */
	private function user(string $uid, bool $inGroup = true): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($uid);

		return $user;
	}//end user()
}//end class
