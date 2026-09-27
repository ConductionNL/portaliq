<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\ActivityFeedReader;
use OCA\Portaliq\Service\ActivityPlaces;
use OCA\Portaliq\Service\ActivityRoster;
use OCA\Portaliq\Service\ActivityStore;
use OCA\Portaliq\Service\GuardianAudienceFixtureReader;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The staff roster (extracurricular-activity-offer, activity-parental-consent):
 * places, holders, the waiting list in order, and photo consent where photos
 * are taken.
 *
 * @spec openspec/changes/activity-parental-consent/specs/portaliq-cms/spec.md#requirement-where-photos-are-taken-the-roster-must-show-each-childs-photo-consent
 */
class ActivityRosterTest extends TestCase {
	/**
	 * Three sign-ups on one activity.
	 */
	private const SIGNUPS = [
		['id' => 's1', 'activityRef' => 'activity-1', 'childRef' => 'child-devries-lars', 'guardianRef' => 'guardian-anna-devries', 'status' => 'confirmed'],
		['id' => 's2', 'activityRef' => 'activity-schaakclub-najaar', 'childRef' => 'child-bakker-tim', 'guardianRef' => 'guardian-piet-bakker', 'status' => 'confirmed'],
		['id' => 's3', 'activityRef' => 'activity-1', 'childRef' => 'child-bakker-eva', 'guardianRef' => 'guardian-piet-bakker', 'status' => 'waitlisted', 'signedUpAt' => '2026-09-01T10:00:00+00:00'],
		['id' => 's4', 'activityRef' => 'activity-1', 'childRef' => 'child-gone', 'guardianRef' => 'guardian-x', 'status' => 'withdrawn'],
	];

	/**
	 * The roster over one activity with the given fields.
	 *
	 * @param array<string, mixed> $activityFields Extra activity fields.
	 * @param array<int, string> $unreadable Schemas whose read fails.
	 *
	 * @return ActivityRoster
	 */
	private function roster(array $activityFields = [], array $unreadable = []): ActivityRoster {
		$store = new InMemoryActivityStore(
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
			[
				ActivityStore::OFFER => [array_merge(['id' => 'activity-1', '@self' => ['slug' => 'activity-schaakclub-najaar'], 'capacity' => 2, 'status' => 'open'], $activityFields)],
				ActivityStore::SIGNUP => self::SIGNUPS,
			]
		);
		$store->unreadable = $unreadable;

		$audience = $this->createMock(GuardianAudienceFixtureReader::class);
		$audience->method('photoConsentGranted')->willReturnCallback(
			static fn (string $subjectRef, string $childRef, string $purpose): bool => in_array($subjectRef . '|' . $childRef, ['guardian-anna-devries|child-devries-lars', 'guardian-piet-bakker|child-bakker-eva'], true)
		);

		return new ActivityRoster($store, new ActivityPlaces(), new ActivityFeedReader($store, $audience, new ActivityPlaces()));
	}//end roster()

	/**
	 * The roster lists the places, the holders and the waiting list; a
	 * withdrawn child is on neither list.
	 *
	 * @return void
	 */
	public function testTheRosterListsPlacesHoldersAndTheWaitingList(): void {
		$roster = $this->roster()->roster('activity-1');

		$this->assertSame(2, $roster['places']);
		$this->assertSame(['child-devries-lars', 'child-bakker-tim'], array_column($roster['confirmed'], 'childRef'));
		$this->assertSame(['child-bakker-eva'], array_column($roster['waitlist'], 'childRef'));
		$this->assertArrayNotHasKey('photoConsent', $roster['confirmed'][0], 'no photos, no mark');
	}//end testTheRosterListsPlacesHoldersAndTheWaitingList()

	/**
	 * Where photos are taken, each child carries the signing guardian's photo
	 * consent; an absent entry is no consent.
	 *
	 * @return void
	 */
	public function testTheRosterShowsPhotoConsentWherePhotosAreTaken(): void {
		$roster = $this->roster(['photosTaken' => true])->roster('activity-schaakclub-najaar');

		$this->assertSame([true, false], array_column($roster['confirmed'], 'photoConsent'));
		$this->assertSame([true], array_column($roster['waitlist'], 'photoConsent'));
	}//end testTheRosterShowsPhotoConsentWherePhotosAreTaken()

	/**
	 * An unknown activity, or sign-ups that cannot be read, is no roster, not
	 * an empty one.
	 *
	 * @return void
	 */
	public function testUnknownOrUnreadableIsNoRoster(): void {
		$this->assertNull($this->roster()->roster('unknown'));
		$this->assertNull($this->roster([], [ActivityStore::SIGNUP])->roster('activity-1'));
	}//end testUnknownOrUnreadableIsNoRoster()
}//end class
