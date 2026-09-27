<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\ActivityFeedReader;
use OCA\Portaliq\Service\ActivityPlaces;
use OCA\Portaliq\Service\ActivitySignupService;
use OCA\Portaliq\Service\ActivityStore;
use OCA\Portaliq\Service\GuardianAudienceFixtureReader;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Places, the waiting list and promotion (extracurricular-activity-offer).
 * Runs the real feed reader and places arithmetic over an in-memory store;
 * only the guardian audience and the clock are doubles.
 *
 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-guardian-must-be-able-to-sign-up-one-of-their-own-children-with-a-waiting-list-when-full
 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-freed-place-must-go-to-the-child-who-waited-longest
 */
class ActivitySignupServiceTest extends TestCase {
	/**
	 * The fixed "now": 2026-09-27T12:00:00Z.
	 */
	private const NOW = 1790510400;

	/**
	 * Each guardian's audience, as the fixture would resolve it.
	 */
	private const AUDIENCES = [
		'guardian-anna-devries' => ['schoolRef' => 'school-de-regenboog', 'groupRefs' => ['groep-5a'], 'childRefs' => ['child-devries-lars'], 'photoConsent' => []],
		'guardian-piet-bakker' => ['schoolRef' => 'school-de-regenboog', 'groupRefs' => ['groep-3b'], 'childRefs' => ['child-bakker-eva', 'child-bakker-tim'], 'photoConsent' => []],
		'guardian-noor-yilmaz' => ['schoolRef' => 'school-oude-vestiging', 'groupRefs' => ['groep-6b'], 'childRefs' => ['child-yilmaz-can'], 'photoConsent' => []],
	];

	/**
	 * An open schaakclub for school De Regenboog.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function activity(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'activity-1',
				'@self' => ['id' => 'activity-1', 'slug' => 'activity-schaakclub-najaar'],
				'title' => 'Schaakclub',
				'target' => ['schoolRef' => 'school-de-regenboog'],
				'capacity' => 16,
				'supervisorRefs' => ['staff-leerkracht-5a'],
				'childrenPerSupervisor' => 12,
				'waitlistEnabled' => true,
				'status' => 'open',
			],
			$overrides
		);
	}//end activity()

	/**
	 * The service over an in-memory store.
	 *
	 * @param InMemoryActivityStore $store The store.
	 *
	 * @return ActivitySignupService
	 */
	private function service(InMemoryActivityStore $store): ActivitySignupService {
		$audience = $this->createMock(GuardianAudienceFixtureReader::class);
		$audience->method('resolveAudience')->willReturnCallback(
			static fn (string $subjectRef): array => (self::AUDIENCES[$subjectRef] ?? ['schoolRef' => '', 'groupRefs' => [], 'childRefs' => [], 'photoConsent' => []])
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$places = new ActivityPlaces();

		return new ActivitySignupService($store, new ActivityFeedReader($store, $audience, $places), $places, $time);
	}//end service()

	/**
	 * A store holding one activity and some sign-ups.
	 *
	 * @param array<string, mixed> $activity The activity.
	 * @param array<int, array<string, mixed>> $signups The sign-ups.
	 *
	 * @return InMemoryActivityStore
	 */
	private function store(array $activity, array $signups = []): InMemoryActivityStore {
		return new InMemoryActivityStore(
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
			[ActivityStore::OFFER => [$activity], ActivityStore::SIGNUP => $signups]
		);
	}//end store()

	/**
	 * Confirmed sign-ups for filler children.
	 *
	 * @param int $count How many.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function confirmed(int $count): array {
		$rows = [];
		for ($i = 1; $i <= $count; $i++) {
			$rows[] = ['id' => 'filler-' . $i, 'activityRef' => 'activity-1', 'childRef' => 'child-filler-' . $i, 'guardianRef' => 'guardian-x', 'status' => 'confirmed', 'signedUpAt' => '2026-09-01T10:00:00+00:00'];
		}

		return $rows;
	}//end confirmed()

	/**
	 * Places are the lower of capacity and supervisors times the ratio;
	 * duplicate supervisors count once; no ratio means capacity alone.
	 *
	 * @return void
	 */
	public function testPlacesAreTheLowerOfCapacityAndSupervision(): void {
		$places = new ActivityPlaces();

		$this->assertSame(16, $places->placesFor(['capacity' => 20, 'supervisorRefs' => ['a', 'b'], 'childrenPerSupervisor' => 8]));
		$this->assertSame(20, $places->placesFor(['capacity' => 20, 'supervisorRefs' => ['a', 'b', 'c'], 'childrenPerSupervisor' => 8]));
		$this->assertSame(8, $places->placesFor(['capacity' => 20, 'supervisorRefs' => ['a', 'a', ''], 'childrenPerSupervisor' => 8]));
		$this->assertSame(20, $places->placesFor(['capacity' => 20, 'childrenPerSupervisor' => 0]));
		$this->assertSame(0, $places->placesFor(['capacity' => 20, 'supervisorRefs' => [], 'childrenPerSupervisor' => 8]));
		$this->assertSame(0, $places->placesFor([]));
	}//end testPlacesAreTheLowerOfCapacityAndSupervision()

	/**
	 * The last place is confirmed; the next child joins the list at 1, the
	 * one after at 2; with no list the activity is full.
	 *
	 * @return void
	 */
	public function testAFullActivityWaitlistsInOrder(): void {
		$store = $this->store($this->activity(['capacity' => 3]), $this->confirmed(2));
		$service = $this->service($store);

		$this->assertSame(['status' => 'confirmed'], $service->signUp('guardian-anna-devries', 'activity-1', 'child-devries-lars', 'Neemt een bord mee'));
		$this->assertSame(['status' => 'waitlisted', 'position' => 1], $service->signUp('guardian-piet-bakker', 'activity-1', 'child-bakker-eva'));
		$this->assertSame(['status' => 'waitlisted', 'position' => 2], $service->signUp('guardian-piet-bakker', 'activity-1', 'child-bakker-tim'));

		$lars = $store->where(ActivityStore::SIGNUP, 'childRef', 'child-devries-lars')[0];
		$this->assertSame('activity-1', $lars['activityRef']);
		$this->assertSame('guardian-anna-devries', $lars['guardianRef']);
		$this->assertSame(gmdate('c', self::NOW), $lars['confirmedAt']);
		$this->assertSame('Neemt een bord mee', $lars['note']);

		$noList = $this->store($this->activity(['capacity' => 2, 'waitlistEnabled' => false]), $this->confirmed(2));
		$this->assertSame(['error' => ActivitySignupService::REASON_FULL], $this->service($noList)->signUp('guardian-anna-devries', 'activity-1', 'child-devries-lars'));
		$this->assertSame([], $noList->saves);
	}//end testAFullActivityWaitlistsInOrder()

	/**
	 * Another guardian's child, an activity outside the audience and an
	 * unknown activity are the same refusal, and nothing is written.
	 *
	 * @return void
	 */
	public function testAChildWhoIsNotTheGuardiansOwnIsRefused(): void {
		$store = $this->store($this->activity());
		$service = $this->service($store);

		$this->assertSame(['error' => ActivitySignupService::REASON_NOT_FOUND], $service->signUp('guardian-anna-devries', 'activity-1', 'child-bakker-eva'));
		$this->assertSame(['error' => ActivitySignupService::REASON_NOT_FOUND], $service->signUp('guardian-noor-yilmaz', 'activity-1', 'child-yilmaz-can'));
		$this->assertSame(['error' => ActivitySignupService::REASON_NOT_FOUND], $service->signUp('guardian-anna-devries', 'no-such-activity', 'child-devries-lars'));
		$this->assertSame(['error' => ActivitySignupService::REASON_NOT_FOUND], $service->signUp('', 'activity-1', 'child-devries-lars'));

		$draft = $this->store($this->activity(['status' => 'draft']));
		$this->assertSame(['error' => ActivitySignupService::REASON_NOT_FOUND], $this->service($draft)->signUp('guardian-anna-devries', 'activity-1', 'child-devries-lars'));
		$this->assertSame([], $store->saves);
		$this->assertSame([], $draft->saves);
	}//end testAChildWhoIsNotTheGuardiansOwnIsRefused()

	/**
	 * A closed activity, a passed deadline, a second sign-up for the same
	 * child and unreadable sign-ups each refuse before any write; the slug
	 * reaches the activity too.
	 *
	 * @return void
	 */
	public function testClosedDuplicateAndUnreadableRefuseBeforeAnyWrite(): void {
		$closed = $this->store($this->activity(['status' => 'closed']));
		$this->assertSame(['error' => ActivitySignupService::REASON_CLOSED], $this->service($closed)->signUp('guardian-anna-devries', 'activity-1', 'child-devries-lars'));

		$late = $this->store($this->activity(['signupDeadline' => gmdate('c', self::NOW - 60)]));
		$this->assertSame(['error' => ActivitySignupService::REASON_CLOSED], $this->service($late)->signUp('guardian-anna-devries', 'activity-1', 'child-devries-lars'));

		$onTime = $this->store($this->activity(['signupDeadline' => gmdate('c', self::NOW + 60)]));
		$this->assertSame(['status' => 'confirmed'], $this->service($onTime)->signUp('guardian-anna-devries', 'activity-schaakclub-najaar', 'child-devries-lars'));

		$twice = $this->store($this->activity(), [['id' => 's1', 'activityRef' => 'activity-schaakclub-najaar', 'childRef' => 'child-devries-lars', 'status' => 'waitlisted']]);
		$this->assertSame(['error' => ActivitySignupService::REASON_DUPLICATE], $this->service($twice)->signUp('guardian-anna-devries', 'activity-1', 'child-devries-lars'));

		$unreadable = $this->store($this->activity());
		$unreadable->unreadable = [ActivityStore::SIGNUP];
		$this->assertSame(['error' => ActivitySignupService::REASON_UNAVAILABLE], $this->service($unreadable)->signUp('guardian-anna-devries', 'activity-1', 'child-devries-lars'));

		foreach ([$closed, $late, $twice, $unreadable] as $store) {
			$this->assertSame([], $store->saves);
		}

		$failing = $this->store($this->activity());
		$failing->failSaves = true;
		$this->assertSame(['error' => ActivitySignupService::REASON_UNAVAILABLE], $this->service($failing)->signUp('guardian-anna-devries', 'activity-1', 'child-devries-lars'));
	}//end testClosedDuplicateAndUnreadableRefuseBeforeAnyWrite()

	/**
	 * A confirmed child's withdrawal gives the place to the longest-waiting
	 * child; withdrawing a waitlisted child promotes nobody.
	 *
	 * @return void
	 */
	public function testAWithdrawalPromotesTheLongestWaitingChild(): void {
		$signups = array_merge(
			$this->confirmed(1),
			[
				['id' => 's-lars', 'activityRef' => 'activity-1', 'childRef' => 'child-devries-lars', 'guardianRef' => 'guardian-anna-devries', 'status' => 'confirmed', 'signedUpAt' => '2026-09-02T10:00:00+00:00'],
				['id' => 's-tim', 'activityRef' => 'activity-1', 'childRef' => 'child-bakker-tim', 'status' => 'waitlisted', 'signedUpAt' => '2026-09-05T10:00:00+00:00'],
				['id' => 's-eva', 'activityRef' => 'activity-schaakclub-najaar', 'childRef' => 'child-bakker-eva', 'status' => 'waitlisted', 'signedUpAt' => '2026-09-04T10:00:00+00:00'],
			]
		);
		$store = $this->store($this->activity(['capacity' => 2]), $signups);
		$service = $this->service($store);

		$this->assertNull($service->withdraw('guardian-anna-devries', 'activity-1', 'child-devries-lars'));

		$this->assertSame('withdrawn', $store->where(ActivityStore::SIGNUP, 'childRef', 'child-devries-lars')[0]['status']);
		$this->assertSame('confirmed', $store->where(ActivityStore::SIGNUP, 'childRef', 'child-bakker-eva')[0]['status'], 'Eva waited longest');
		$this->assertSame('waitlisted', $store->where(ActivityStore::SIGNUP, 'childRef', 'child-bakker-tim')[0]['status']);

		$before = count($store->saves);
		$this->assertNull($service->withdraw('guardian-piet-bakker', 'activity-1', 'child-bakker-tim'));
		$this->assertCount($before + 1, $store->saves, 'a waitlisted withdrawal writes itself and promotes nobody');

		$this->assertSame(ActivitySignupService::REASON_NOT_FOUND, $service->withdraw('guardian-anna-devries', 'activity-1', 'child-bakker-eva'), "not Anna's child");
		$this->assertSame(ActivitySignupService::REASON_NOT_FOUND, $service->withdraw('guardian-anna-devries', 'activity-1', 'child-devries-lars'), 'already withdrawn');
	}//end testAWithdrawalPromotesTheLongestWaitingChild()

	/**
	 * Two supervisors instead of one double the places and confirm two
	 * waitlisted children in sign-up order; the roster shows the result.
	 *
	 * @return void
	 */
	public function testMoreSupervisorsPromoteFromTheWaitingList(): void {
		$signups = [
			['id' => 'a', 'activityRef' => 'activity-1', 'childRef' => 'child-1', 'status' => 'confirmed', 'signedUpAt' => '2026-09-01T10:00:00+00:00'],
			['id' => 'b', 'activityRef' => 'activity-1', 'childRef' => 'child-2', 'status' => 'confirmed', 'signedUpAt' => '2026-09-01T11:00:00+00:00'],
			['id' => 'c', 'activityRef' => 'activity-1', 'childRef' => 'child-3', 'status' => 'waitlisted', 'signedUpAt' => '2026-09-03T10:00:00+00:00'],
			['id' => 'd', 'activityRef' => 'activity-1', 'childRef' => 'child-4', 'status' => 'waitlisted', 'signedUpAt' => '2026-09-02T10:00:00+00:00'],
			['id' => 'e', 'activityRef' => 'activity-1', 'childRef' => 'child-5', 'status' => 'waitlisted', 'signedUpAt' => '2026-09-04T10:00:00+00:00'],
		];
		$store = $this->store($this->activity(['capacity' => 10, 'childrenPerSupervisor' => 2, 'supervisorRefs' => ['staff-a']]), $signups);
		$service = $this->service($store);

		$result = $service->setSupervisors('activity-1', ['staff-a', 'staff-b', 'staff-b', '']);

		$this->assertSame(2, $result['promoted']);
		$this->assertSame(['staff-a', 'staff-b'], $result['activity']['supervisorRefs']);
		$this->assertSame('confirmed', $store->where(ActivityStore::SIGNUP, 'childRef', 'child-4')[0]['status']);
		$this->assertSame('confirmed', $store->where(ActivityStore::SIGNUP, 'childRef', 'child-3')[0]['status']);
		$this->assertSame('waitlisted', $store->where(ActivityStore::SIGNUP, 'childRef', 'child-5')[0]['status']);

		$roster = $service->roster('activity-1');
		$this->assertSame(4, $roster['places']);
		$this->assertCount(4, $roster['confirmed']);
		$this->assertSame(['child-5'], array_column($roster['waitlist'], 'childRef'));
		$this->assertNull($service->roster('unknown'));
		$this->assertNull($service->setSupervisors('unknown', ['staff-a']));
	}//end testMoreSupervisorsPromoteFromTheWaitingList()
}//end class
