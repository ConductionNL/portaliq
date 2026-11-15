<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\ActivityAttendanceService;
use OCA\Portaliq\Service\ActivityPlaces;
use OCA\Portaliq\Service\ActivityStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Attendance per session (extracurricular-activity-offer): only a confirmed
 * child in a declared session, one row per activity, session and child.
 *
 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-staff-must-be-able-to-mark-attendance-per-session
 */
class ActivityAttendanceServiceTest extends TestCase {
	/**
	 * The fixed "now": 2026-09-27T12:00:00Z.
	 */
	private const NOW = 1790510400;

	/**
	 * A store with one activity, a confirmed and a waitlisted child.
	 *
	 * @return InMemoryActivityStore
	 */
	private function store(): InMemoryActivityStore {
		return new InMemoryActivityStore(
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
			[
				ActivityStore::OFFER => [
					['id' => 'schaak', '@self' => ['slug' => 'activity-schaakclub-najaar'], 'status' => 'open', 'sessions' => [['id' => 'week-1', 'start' => '2026-10-05T15:15:00+02:00'], ['id' => 'week-3', 'start' => '2026-10-19T15:15:00+02:00']]],
				],
				ActivityStore::SIGNUP => [
					['id' => 's1', 'activityRef' => 'activity-schaakclub-najaar', 'childRef' => 'child-devries-lars', 'status' => 'confirmed'],
					['id' => 's2', 'activityRef' => 'schaak', 'childRef' => 'child-bakker-tim', 'status' => 'waitlisted'],
				],
				ActivityStore::ATTENDANCE => [],
			]
		);
	}//end store()

	/**
	 * The service over a store.
	 *
	 * @param InMemoryActivityStore $store The store.
	 *
	 * @return ActivityAttendanceService
	 */
	private function service(InMemoryActivityStore $store): ActivityAttendanceService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		return new ActivityAttendanceService($store, new ActivityPlaces(), $time);
	}//end service()

	/**
	 * A second mark for the same session and child updates the first.
	 *
	 * @return void
	 */
	public function testASecondMarkUpdatesTheFirst(): void {
		$store = $this->store();
		$service = $this->service($store);

		$first = $service->mark('schaak', 'week-3', 'child-devries-lars', 'absent', 'staff-leerkracht-5a');
		$second = $service->mark('activity-schaakclub-najaar', 'week-3', 'child-devries-lars', 'present', 'staff-leerkracht-5a');

		$this->assertSame('absent', $first['attendance']['status']);
		$this->assertSame('present', $second['attendance']['status']);
		$this->assertCount(1, $store->data[ActivityStore::ATTENDANCE]);
		$this->assertSame('present', $store->data[ActivityStore::ATTENDANCE][0]['status']);
		$this->assertSame('schaak', $store->data[ActivityStore::ATTENDANCE][0]['activityRef']);
		$this->assertSame(gmdate('c', self::NOW), $store->data[ActivityStore::ATTENDANCE][0]['markedAt']);
		$this->assertSame('staff-leerkracht-5a', $store->data[ActivityStore::ATTENDANCE][0]['markedByRef']);
	}//end testASecondMarkUpdatesTheFirst()

	/**
	 * A waitlisted child, an unknown session, an unknown status and an unknown
	 * activity are refused with nothing written.
	 *
	 * @return void
	 */
	public function testOnlyAConfirmedChildInADeclaredSessionIsMarked(): void {
		$store = $this->store();
		$service = $this->service($store);

		$this->assertSame(['error' => ActivityAttendanceService::REASON_NOT_CONFIRMED], $service->mark('schaak', 'week-1', 'child-bakker-tim', 'present', 'staff'));
		$this->assertSame(['error' => ActivityAttendanceService::REASON_NOT_CONFIRMED], $service->mark('schaak', 'week-1', 'child-stranger', 'present', 'staff'));
		$this->assertSame(['error' => ActivityAttendanceService::REASON_UNKNOWN_SESSION], $service->mark('schaak', 'week-2', 'child-devries-lars', 'present', 'staff'));
		$this->assertSame(['error' => ActivityAttendanceService::REASON_UNKNOWN_SESSION], $service->mark('schaak', '', 'child-devries-lars', 'present', 'staff'));
		$this->assertSame(['error' => ActivityAttendanceService::REASON_INVALID_STATUS], $service->mark('schaak', 'week-1', 'child-devries-lars', 'late', 'staff'));
		$this->assertSame(['error' => ActivityAttendanceService::REASON_NOT_FOUND], $service->mark('nothing', 'week-1', 'child-devries-lars', 'present', 'staff'));
		$this->assertSame([], $store->saves);
	}//end testOnlyAConfirmedChildInADeclaredSessionIsMarked()

	/**
	 * Unreadable rows and a failed write are unavailable, not a guess.
	 *
	 * @return void
	 */
	public function testUnreadableOrUnwritableIsUnavailable(): void {
		$signups = $this->store();
		$signups->unreadable = [ActivityStore::SIGNUP];
		$this->assertSame(['error' => ActivityAttendanceService::REASON_UNAVAILABLE], $this->service($signups)->mark('schaak', 'week-1', 'child-devries-lars', 'present', 'staff'));

		$marks = $this->store();
		$marks->unreadable = [ActivityStore::ATTENDANCE];
		$this->assertSame(['error' => ActivityAttendanceService::REASON_UNAVAILABLE], $this->service($marks)->mark('schaak', 'week-1', 'child-devries-lars', 'present', 'staff'));

		$failing = $this->store();
		$failing->failSaves = true;
		$this->assertSame(['error' => ActivityAttendanceService::REASON_UNAVAILABLE], $this->service($failing)->mark('schaak', 'week-1', 'child-devries-lars', 'present', 'staff'));
	}//end testUnreadableOrUnwritableIsUnavailable()
}//end class
