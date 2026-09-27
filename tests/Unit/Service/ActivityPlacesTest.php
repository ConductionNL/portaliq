<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\ActivityPlaces;
use PHPUnit\Framework\TestCase;

/**
 * The places arithmetic on its own (extracurricular-activity-offer): which
 * sign-ups belong to an activity, who holds a place, the waiting-list order
 * and a child's sign-up that still counts.
 *
 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-freed-place-must-go-to-the-child-who-waited-longest
 */
class ActivityPlacesTest extends TestCase {
	/**
	 * Sign-ups over two activities, one referenced by slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function signups(): array {
		return [
			['id' => 'b', 'activityRef' => 'schaak', 'childRef' => 'child-2', 'status' => 'waitlisted', 'signedUpAt' => '2026-09-02T10:00:00+00:00'],
			['id' => 'a', 'activityRef' => 'activity-schaakclub-najaar', 'childRef' => 'child-1', 'status' => 'waitlisted', 'signedUpAt' => '2026-09-02T10:00:00+00:00'],
			['id' => 'c', 'activityRef' => 'schaak', 'childRef' => 'child-3', 'status' => 'waitlisted', 'signedUpAt' => '2026-09-01T09:00:00+00:00'],
			['id' => 'd', 'activityRef' => 'schaak', 'childRef' => 'child-4', 'status' => 'confirmed'],
			['id' => 'e', 'activityRef' => 'schaak', 'childRef' => 'child-5', 'status' => 'withdrawn'],
			['id' => 'f', 'activityRef' => 'zwem', 'childRef' => 'child-6', 'status' => 'confirmed'],
		];
	}//end signups()

	/**
	 * Sign-ups belong to an activity by any of its names.
	 *
	 * @return void
	 */
	public function testSignupsBelongByIdOrSlug(): void {
		$own = (new ActivityPlaces())->forActivity($this->signups(), ['schaak', 'activity-schaakclub-najaar']);

		$this->assertSame(['b', 'a', 'c', 'd', 'e'], array_column($own, 'id'));
	}//end testSignupsBelongByIdOrSlug()

	/**
	 * The waiting list runs by sign-up time, then by id for a tie; positions
	 * are 1-based; a confirmed or unknown child has none.
	 *
	 * @return void
	 */
	public function testTheWaitingListOrderAndPositions(): void {
		$places = new ActivityPlaces();
		$own = $places->forActivity($this->signups(), ['schaak', 'activity-schaakclub-najaar']);

		$this->assertSame(['c', 'a', 'b'], array_column($places->waitlist($own), 'id'));
		$this->assertSame(1, $places->position($own, 'child-3'));
		$this->assertSame(2, $places->position($own, 'child-1'));
		$this->assertNull($places->position($own, 'child-4'));
		$this->assertNull($places->position($own, 'child-9'));
		$this->assertSame(['d'], array_column($places->confirmed($own), 'id'));
	}//end testTheWaitingListOrderAndPositions()

	/**
	 * A withdrawn sign-up no longer counts; a confirmed or waitlisted one does.
	 *
	 * @return void
	 */
	public function testOnlyConfirmedOrWaitlistedSignupsCount(): void {
		$places = new ActivityPlaces();
		$own = $places->forActivity($this->signups(), ['schaak']);

		$this->assertNull($places->activeFor($own, 'child-5'));
		$this->assertSame('d', $places->activeFor($own, 'child-4')['id']);
		$this->assertSame('b', $places->activeFor($own, 'child-2')['id']);
		$this->assertNull($places->activeFor($own, 'child-6'), 'another activity');
	}//end testOnlyConfirmedOrWaitlistedSignupsCount()
}//end class
