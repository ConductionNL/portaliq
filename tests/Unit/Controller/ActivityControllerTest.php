<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\ActivityController;
use OCA\Portaliq\Service\ActivityAttendanceService;
use OCA\Portaliq\Service\ActivityContributionService;
use OCA\Portaliq\Service\ActivityDraft;
use OCA\Portaliq\Service\ActivityPlaces;
use OCA\Portaliq\Service\ActivityRoster;
use OCA\Portaliq\Service\ActivitySignupService;
use OCA\Portaliq\Service\ActivityStore;
use OCA\Portaliq\Tests\Unit\Service\InMemoryActivityStore;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Staff endpoints for activities (extracurricular-activity-offer): every
 * method guards first, opening needs a place, create sanitises what it keeps.
 *
 * @spec openspec/changes/extracurricular-activity-offer/contract.md
 */
class ActivityControllerTest extends TestCase {
	/**
	 * The controller over a store and optional service doubles.
	 *
	 * @param InMemoryActivityStore $store The store.
	 * @param array<string, mixed> $params The request parameters.
	 * @param bool $signedIn Whether a Nextcloud user is signed in.
	 * @param ActivitySignupService|null $signups The sign-up service double.
	 * @param ActivityAttendanceService|null $attendance The attendance service double.
	 * @param ActivityRoster|null $rosters The roster double.
	 * @param ActivityContributionService|null $contributions The contribution raise double.
	 *
	 * @return ActivityController
	 */
	private function controller(
		InMemoryActivityStore $store,
		array $params = [],
		bool $signedIn = true,
		?ActivitySignupService $signups = null,
		?ActivityAttendanceService $attendance = null,
		?ActivityRoster $rosters = null,
		?ActivityContributionService $contributions = null,
	): ActivityController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => ($params[$key] ?? $default));
		$request->method('getParams')->willReturn($params);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('staff-leerkracht-5a');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn ? $user : null);

		return new ActivityController(
			$request,
			$session,
			$store,
			new ActivityPlaces(),
			($signups ?? $this->createMock(ActivitySignupService::class)),
			($attendance ?? $this->createMock(ActivityAttendanceService::class)),
			new ActivityDraft(),
			($rosters ?? $this->createMock(ActivityRoster::class)),
			($contributions ?? $this->createMock(ActivityContributionService::class))
		);
	}//end controller()

	/**
	 * A store with the given activities.
	 *
	 * @param array<int, array<string, mixed>> $activities The activities.
	 *
	 * @return InMemoryActivityStore
	 */
	private function store(array $activities = []): InMemoryActivityStore {
		return new InMemoryActivityStore($this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class), [ActivityStore::OFFER => $activities]);
	}//end store()

	/**
	 * Every method refuses without a Nextcloud user, before touching data.
	 *
	 * @return void
	 */
	public function testEveryMethodGuardsFirst(): void {
		$calls = [
			fn (ActivityController $c) => $c->create('Schaakclub', 'club', ['schoolRef' => 's'], '2026-10-05', 16),
			fn (ActivityController $c) => $c->open('a'),
			fn (ActivityController $c) => $c->close('a'),
			fn (ActivityController $c) => $c->supervisors('a', ['s']),
			fn (ActivityController $c) => $c->roster('a'),
			fn (ActivityController $c) => $c->attendance('a', 'week-1', 'child', 'present'),
			fn (ActivityController $c) => $c->contributions('a'),
		];
		foreach ($calls as $index => $call) {
			$store = $this->store([['id' => 'a', 'status' => 'draft', 'capacity' => 5]]);
			try {
				$call($this->controller($store, signedIn: false));
				$this->fail('call ' . $index . ' did not refuse');
			} catch (OCSForbiddenException $e) {
				$this->assertSame([], $store->saves, 'call ' . $index . ' wrote before the guard');
			}
		}
	}//end testEveryMethodGuardsFirst()

	/**
	 * Create stores a draft by the signed-in staff member, with the optional
	 * fields sanitised; a bad body is 400 with nothing written.
	 *
	 * @return void
	 */
	public function testCreateStoresASanitisedDraft(): void {
		$store = $this->store();
		$params = [
			'termEnd' => '2026-12-14',
			'description' => 'Schaken in de aula.',
			'waitlistEnabled' => 'true',
			'paymentRequested' => 'yes-please',
			'childrenPerSupervisor' => '12',
			'supervisorRefs' => ['staff-leerkracht-5a', 'staff-leerkracht-5a', 7, ''],
			'sessions' => [['id' => 'week-1', 'start' => '2026-10-05T15:15:00+02:00', 'end' => '2026-10-05T16:15:00+02:00'], ['id' => 'week-1', 'start' => 'dup'], ['start' => 'no id'], 'junk'],
		];

		$response = $this->controller($store, $params)->create('Schaakclub', 'club', ['schoolRef' => 'school-de-regenboog'], '2026-10-05', 16);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$saved = $store->data[ActivityStore::OFFER][0];
		$this->assertSame('draft', $saved['status']);
		$this->assertSame('staff-leerkracht-5a', $saved['authorRef']);
		$this->assertTrue($saved['waitlistEnabled']);
		$this->assertFalse($saved['paymentRequested']);
		$this->assertSame(12, $saved['childrenPerSupervisor']);
		$this->assertSame(['staff-leerkracht-5a'], $saved['supervisorRefs']);
		$this->assertSame([['id' => 'week-1', 'start' => '2026-10-05T15:15:00+02:00', 'end' => '2026-10-05T16:15:00+02:00']], $saved['sessions']);
		$this->assertArrayNotHasKey('fee', $saved);

		$bad = $this->store();
		foreach ([['', 'club', ['schoolRef' => 's'], '2026-10-05', 5], ['T', 'party', ['schoolRef' => 's'], '2026-10-05', 5], ['T', 'club', [], '2026-10-05', 5], ['T', 'club', ['schoolRef' => 's'], '', 5], ['T', 'club', ['groupRefs' => ['g']], '2026-10-05', 0]] as $args) {
			$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller($bad)->create(...$args)->getStatus());
		}

		$this->assertSame([], $bad->saves);
	}//end testCreateStoresASanitisedDraft()

	/**
	 * An activity with no place does not open; one with a supervisor does;
	 * close keeps it visible; unknown ids are 404.
	 *
	 * @return void
	 */
	public function testOpenRefusesAnActivityWithNoPlace(): void {
		$store = $this->store([
			['id' => 'unsupervised', 'status' => 'draft', 'capacity' => 16, 'childrenPerSupervisor' => 8, 'supervisorRefs' => []],
			['id' => 'supervised', 'status' => 'draft', 'capacity' => 16, 'childrenPerSupervisor' => 8, 'supervisorRefs' => ['staff-a']],
		]);
		$controller = $this->controller($store);

		$refused = $controller->open('unsupervised');
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $refused->getStatus());
		$this->assertSame(['error' => 'no_places'], $refused->getData());
		$this->assertSame('draft', $store->data[ActivityStore::OFFER][0]['status']);

		$this->assertSame('open', $controller->open('supervised')->getData()['status']);
		$this->assertSame('closed', $controller->close('supervised')->getData()['status']);
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->open('nothing')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->close('nothing')->getStatus());
	}//end testOpenRefusesAnActivityWithNoPlace()

	/**
	 * An activity needing consent does not open without a consent text.
	 *
	 * @spec openspec/changes/activity-parental-consent/specs/portaliq-cms/spec.md#requirement-an-activity-must-be-able-to-require-a-guardians-consent-recorded-on-the-sign-up
	 *
	 * @return void
	 */
	public function testOpenRefusesConsentWithoutAStatement(): void {
		$store = $this->store([
			['id' => 'no-text', 'status' => 'draft', 'capacity' => 10, 'consentRequired' => true, 'consentStatement' => '  '],
			['id' => 'with-text', 'status' => 'draft', 'capacity' => 10, 'consentRequired' => true, 'consentStatement' => 'Mijn kind mag mee.'],
		]);
		$controller = $this->controller($store);

		$refused = $controller->open('no-text');
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $refused->getStatus());
		$this->assertSame(['error' => 'no_consent_statement'], $refused->getData());
		$this->assertSame('open', $controller->open('with-text')->getData()['status']);
	}//end testOpenRefusesConsentWithoutAStatement()

	/**
	 * Supervisors, roster and attendance map the services' answers to the
	 * contract's status codes; attendance is marked by the signed-in user.
	 *
	 * @return void
	 */
	public function testServiceAnswersMapToTheContract(): void {
		$signups = $this->createMock(ActivitySignupService::class);
		$signups->method('setSupervisors')->willReturnCallback(fn (string $id) => $id === 'a' ? ['activity' => ['id' => 'a'], 'promoted' => 2] : null);
		$rosters = $this->createMock(ActivityRoster::class);
		$rosters->method('roster')->willReturnCallback(fn (string $id) => $id === 'a' ? ['places' => 4, 'confirmed' => [], 'waitlist' => []] : null);

		$attendance = $this->createMock(ActivityAttendanceService::class);
		$attendance->method('mark')->willReturnCallback(
			fn (string $id, string $session, string $child, string $status, string $by) => match ($child) {
				'waiting' => ['error' => ActivityAttendanceService::REASON_NOT_CONFIRMED],
				'gone' => ['error' => ActivityAttendanceService::REASON_UNAVAILABLE],
				default => ['attendance' => ['childRef' => $child, 'markedByRef' => $by]],
			}
		);

		$controller = $this->controller($this->store(), signups: $signups, attendance: $attendance, rosters: $rosters);

		$this->assertSame(2, $controller->supervisors('a', ['s1', 's2'])->getData()['promoted']);
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->supervisors('b', [])->getStatus());
		$this->assertSame(4, $controller->roster('a')->getData()['places']);
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->roster('b')->getStatus());
		$this->assertSame('staff-leerkracht-5a', $controller->attendance('a', 'week-1', 'child-devries-lars', 'present')->getData()['markedByRef']);
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $controller->attendance('a', 'week-1', 'waiting', 'present')->getStatus());
		$this->assertSame(Http::STATUS_BAD_GATEWAY, $controller->attendance('a', 'week-1', 'gone', 'present')->getStatus());
	}//end testServiceAnswersMapToTheContract()

	/**
	 * The contribution raise (activity-offer-contract-fix) passes the request
	 * body to the service and maps each refusal to its status; no refusal
	 * writes anything here.
	 *
	 * @return void
	 */
	public function testContributionsMapsEachRefusal(): void {
		$params = ['amount' => 25, 'voluntary' => true, 'administrationId' => 'adm-school-1'];
		$expected = [
			'not_found' => Http::STATUS_NOT_FOUND,
			'payment_not_requested' => Http::STATUS_UNPROCESSABLE_ENTITY,
			'activity_unavailable' => Http::STATUS_BAD_GATEWAY,
			'invalid_charge' => Http::STATUS_BAD_REQUEST,
			'forbidden' => Http::STATUS_FORBIDDEN,
			'shillinq_unavailable' => Http::STATUS_SERVICE_UNAVAILABLE,
			'raise_failed' => Http::STATUS_BAD_GATEWAY,
		];

		foreach ($expected as $error => $status) {
			$contributions = $this->createMock(ActivityContributionService::class);
			$contributions->expects($this->once())->method('raise')->with('a', $params)->willReturn(['error' => $error]);
			$store = $this->store();

			$response = $this->controller($store, params: $params, contributions: $contributions)->contributions('a');

			$this->assertSame($status, $response->getStatus(), $error);
			$this->assertSame(['error' => $error], $response->getData());
			$this->assertSame([], $store->saves);
		}

		$contributions = $this->createMock(ActivityContributionService::class);
		$contributions->method('raise')->willReturn(['raised' => 1, 'skipped' => 0, 'failed' => 0, 'results' => []]);
		$ok = $this->controller($this->store(), params: $params, contributions: $contributions)->contributions('a');
		$this->assertSame(Http::STATUS_OK, $ok->getStatus());
		$this->assertSame(1, $ok->getData()['raised']);
	}//end testContributionsMapsEachRefusal()
}//end class
