<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\ActivityFeedReader;
use OCA\Portaliq\Service\ActivityPlaces;
use OCA\Portaliq\Service\ActivityStore;
use OCA\Portaliq\Service\GuardianAudienceFixtureReader;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The guardian's read of activities (extracurricular-activity-offer): only
 * non-draft activities in their audience, the places left, and only their own
 * children's sign-ups.
 *
 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-guardian-must-be-able-to-sign-up-one-of-their-own-children-with-a-waiting-list-when-full
 */
class ActivityFeedReaderTest extends TestCase {
	/**
	 * The reader over a fixed set of rows.
	 *
	 * @return array{0: ActivityFeedReader, 1: InMemoryActivityStore}
	 */
	private function reader(): array {
		$store = new InMemoryActivityStore(
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
			[
				ActivityStore::OFFER => [
					['id' => 'schaak', '@self' => ['slug' => 'activity-schaakclub-najaar'], 'title' => 'Schaakclub', 'status' => 'open', 'target' => ['schoolRef' => 'school-de-regenboog'], 'capacity' => 3, 'childrenPerSupervisor' => 0],
					['id' => 'zwem', 'title' => 'Zwemmen', 'status' => 'closed', 'target' => ['groupRefs' => ['groep-5a']], 'capacity' => 2, 'photosTaken' => true],
					['id' => 'concept', 'title' => 'Nog geheim', 'status' => 'draft', 'target' => ['schoolRef' => 'school-de-regenboog'], 'capacity' => 9],
					['id' => 'elders', 'title' => 'Andere school', 'status' => 'open', 'target' => ['schoolRef' => 'school-het-kompas'], 'capacity' => 9],
				],
				ActivityStore::SIGNUP => [
					['id' => 's1', 'activityRef' => 'activity-schaakclub-najaar', 'childRef' => 'child-devries-lars', 'status' => 'confirmed', 'paymentRequestRef' => '00000000-0000-0000-0000-000000000000', 'consent' => ['statement' => 'Mag mee.', 'grantedByRef' => 'guardian-anna-devries', 'grantedAt' => '2026-09-21T19:02:00+02:00']],
					['id' => 's2', 'activityRef' => 'schaak', 'childRef' => 'child-bakker-tim', 'status' => 'confirmed'],
					['id' => 's3', 'activityRef' => 'zwem', 'childRef' => 'child-bakker-eva', 'status' => 'confirmed'],
					['id' => 's4', 'activityRef' => 'zwem', 'childRef' => 'child-devries-lars', 'status' => 'waitlisted', 'signedUpAt' => '2026-09-02T10:00:00+00:00'],
					['id' => 's5', 'activityRef' => 'zwem', 'childRef' => 'child-other', 'status' => 'waitlisted', 'signedUpAt' => '2026-09-01T10:00:00+00:00'],
				],
			]
		);
		$audience = $this->createMock(GuardianAudienceFixtureReader::class);
		$audience->method('resolveAudience')->willReturnCallback(
			static fn (string $subjectRef): array => $subjectRef === 'guardian-anna-devries'
				? ['schoolRef' => 'school-de-regenboog', 'groupRefs' => ['groep-5a'], 'childRefs' => ['child-devries-lars'], 'photoConsent' => []]
				: ['schoolRef' => '', 'groupRefs' => [], 'childRefs' => [], 'photoConsent' => []]
		);
		$audience->method('photoConsentGranted')->willReturnCallback(
			static fn (string $subjectRef, string $childRef, string $purpose): bool => $subjectRef === 'guardian-anna-devries' && $childRef === 'child-devries-lars' && $purpose === 'news'
		);

		return [new ActivityFeedReader($store, $audience, new ActivityPlaces()), $store];
	}//end reader()

	/**
	 * The feed holds the open and closed activities in reach, never a draft or
	 * another school's; places left count confirmed places; only the
	 * guardian's own child appears, with the waiting-list position.
	 *
	 * @return void
	 */
	public function testTheFeedShowsOnlyReachableActivitiesAndOwnSignups(): void {
		[$reader] = $this->reader();
		$feed = $reader->feedFor('guardian-anna-devries');

		$this->assertSame(['schaak', 'zwem'], array_column($feed, 'id'));
		$this->assertSame(1, $feed[0]['placesLeft']);
		$this->assertSame([['childRef' => 'child-devries-lars', 'status' => 'confirmed', 'paymentRequestRef' => '00000000-0000-0000-0000-000000000000', 'consentGrantedAt' => '2026-09-21T19:02:00+02:00']], $feed[0]['mySignups']);
		$this->assertSame(1, $feed[1]['placesLeft']);
		$this->assertSame([['childRef' => 'child-devries-lars', 'status' => 'waitlisted', 'position' => 2, 'photoConsent' => true]], $feed[1]['mySignups'], 'photos are taken at zwem, so photo consent shows');
		$this->assertFalse($reader->photoConsentGranted('guardian-anna-devries', ''));
		$this->assertFalse($reader->photoConsentGranted('guardian-piet-bakker', 'child-bakker-tim'));
		$this->assertSame([], $reader->feedFor('guardian-unknown'));
		$this->assertSame([], $reader->feedFor(''));
	}//end testTheFeedShowsOnlyReachableActivitiesAndOwnSignups()

	/**
	 * An activity is reached by id or slug; a draft, another school's and an
	 * unknown one are the same null. A child is the guardian's own or not.
	 *
	 * @return void
	 */
	public function testReachAndOwnChild(): void {
		[$reader] = $this->reader();

		$this->assertSame('schaak', $reader->readOwnActivity('guardian-anna-devries', 'activity-schaakclub-najaar')['id']);
		$this->assertSame('zwem', $reader->readOwnActivity('guardian-anna-devries', 'zwem')['id']);
		$this->assertNull($reader->readOwnActivity('guardian-anna-devries', 'concept'));
		$this->assertNull($reader->readOwnActivity('guardian-anna-devries', 'elders'));
		$this->assertNull($reader->readOwnActivity('guardian-anna-devries', 'nothing'));
		$this->assertNull($reader->readOwnActivity('', 'schaak'));
		$this->assertTrue($reader->isOwnChild('guardian-anna-devries', 'child-devries-lars'));
		$this->assertFalse($reader->isOwnChild('guardian-anna-devries', 'child-bakker-tim'));
		$this->assertFalse($reader->isOwnChild('guardian-anna-devries', ''));
	}//end testReachAndOwnChild()

	/**
	 * Unreadable rows give an empty feed, never an error.
	 *
	 * @return void
	 */
	public function testUnreadableRowsGiveAnEmptyFeed(): void {
		[$reader, $store] = $this->reader();
		$store->unreadable = [ActivityStore::OFFER];

		$this->assertSame([], $reader->feedFor('guardian-anna-devries'));
	}//end testUnreadableRowsGiveAnEmptyFeed()
}//end class
