<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\ActivityDraft;
use PHPUnit\Framework\TestCase;

/**
 * A new activity's validation and sanitising (extracurricular-activity-offer).
 *
 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-term-long-activity-must-be-offered-with-places-set-by-capacity-and-supervision
 */
class ActivityDraftTest extends TestCase {
	/**
	 * A sound set of required fields.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array{title: string, kind: string, target: array<string, mixed>, termStart: string, capacity: int}
	 */
	private function required(array $overrides = []): array {
		return array_merge(['title' => 'Schaakclub', 'kind' => 'club', 'target' => ['groupRefs' => ['groep-5a']], 'termStart' => '2026-10-05', 'capacity' => 16], $overrides);
	}//end required()

	/**
	 * The status is always draft and the author the signed-in staff member,
	 * whatever the parameters say.
	 *
	 * @return void
	 */
	public function testStatusAndAuthorAreNeverTakenFromTheParameters(): void {
		$draft = (new ActivityDraft())->build($this->required(), ['status' => 'open', 'authorRef' => 'someone-else', 'location' => 'Aula'], 'staff-leerkracht-5a');

		$this->assertSame('draft', $draft['status']);
		$this->assertSame('staff-leerkracht-5a', $draft['authorRef']);
		$this->assertSame('Aula', $draft['location']);
		$this->assertArrayNotHasKey('fee', $draft);
	}//end testStatusAndAuthorAreNeverTakenFromTheParameters()

	/**
	 * An unsound required field refuses the whole draft.
	 *
	 * @return void
	 */
	public function testAnUnsoundRequiredFieldRefuses(): void {
		$drafts = new ActivityDraft();
		foreach ([['title' => ''], ['kind' => 'party'], ['target' => ['groupRefs' => []]], ['target' => ['schoolRef' => '']], ['termStart' => ''], ['capacity' => 0]] as $override) {
			$this->assertNull($drafts->build($this->required($override), [], 'staff'), json_encode($override));
		}

		$this->assertNotNull($drafts->build($this->required(['target' => ['childRefs' => ['child-devries-lars']]]), [], 'staff'));
	}//end testAnUnsoundRequiredFieldRefuses()

	/**
	 * Optional fields are kept only when well formed.
	 *
	 * @return void
	 */
	public function testOptionalFieldsAreSanitised(): void {
		$draft = (new ActivityDraft())->build(
			$this->required(),
			[
				'termEnd' => '2026-12-14',
				'description' => 7,
				'waitlistEnabled' => '1',
				'paymentRequested' => 'maybe',
				'childrenPerSupervisor' => -3,
				'supervisorRefs' => 'not-a-list',
				'sessions' => [['id' => 'week-1', 'start' => '2026-10-05T15:15:00+02:00', 'location' => 'Gym', 'extra' => 'dropped'], ['id' => '', 'start' => 'x']],
			],
			'staff'
		);

		$this->assertSame('2026-12-14', $draft['termEnd']);
		$this->assertArrayNotHasKey('description', $draft);
		$this->assertTrue($draft['waitlistEnabled']);
		$this->assertFalse($draft['paymentRequested']);
		$this->assertSame(0, $draft['childrenPerSupervisor']);
		$this->assertSame(['not-a-list'], $draft['supervisorRefs']);
		$this->assertSame([['id' => 'week-1', 'start' => '2026-10-05T15:15:00+02:00', 'location' => 'Gym']], $draft['sessions']);
	}//end testOptionalFieldsAreSanitised()
}//end class
