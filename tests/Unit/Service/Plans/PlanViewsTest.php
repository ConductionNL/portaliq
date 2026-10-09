<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Plans;

use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use OCA\Portaliq\Service\Plans\PlanRules;
use OCA\Portaliq\Service\Plans\PlanViews;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The shapes a plan, its note and its actions take for the portal.
 */
#[CoversClass(PlanViews::class)]
#[UsesClass(PlanRules::class)]
class PlanViewsTest extends TestCase {
	/**
	 * Build the views over an account lookup that knows two people.
	 *
	 * @return PlanViews
	 */
	private function views(): PlanViews {
		$accounts = $this->createMock(PortalAccountLookup::class);
		$accounts->method('bySubjectRef')->willReturnCallback(
			static fn (string $ref): ?array => (['owner' => ['displayName' => 'Olga'], 'p1' => ['displayName' => 'Piet']][$ref] ?? null)
		);

		return new PlanViews($accounts);
	}//end views()

	/**
	 * A card counts actions, names people and marks the viewer's role.
	 *
	 * @return void
	 */
	public function testCardAsParticipant(): void {
		$plan    = ['id' => 'pl1', 'title' => 'T', 'goal' => 'G', 'owner' => 'owner', 'endDate' => '2026-05-20T00:00:00+00:00'];
		$actions = [['status' => 'done'], ['status' => 'todo'], ['status' => 'todo']];

		$card = $this->views()->card($plan, ['p1'], $actions, ['subjectRef' => 'p1'], '2026-05-10');

		$this->assertSame('pl1', $card['id']);
		$this->assertSame('2026-05-20', $card['endDate']);
		$this->assertSame(10, $card['daysLeft']);
		$this->assertSame('participant', $card['role']);
		$this->assertSame('Olga', $card['sharedBy']);
		$this->assertSame([1, 2, 3], [$card['doneActions'], $card['openActions'], $card['totalActions']]);
		$this->assertSame([['ref' => 'owner', 'displayName' => 'Olga', 'isOwner' => true], ['ref' => 'p1', 'displayName' => 'Piet', 'isOwner' => false]], $card['participants']);
	}//end testCardAsParticipant()

	/**
	 * The owner sees no sharedBy.
	 *
	 * @return void
	 */
	public function testCardAsOwner(): void {
		$card = $this->views()->card(['id' => 'pl1', 'owner' => 'owner', 'status' => 'done'], [], [], ['subjectRef' => 'owner'], '2026-05-10');

		$this->assertSame('owner', $card['role']);
		$this->assertSame('', $card['sharedBy']);
		$this->assertSame('done', $card['state']);
		$this->assertNull($card['daysLeft']);
	}//end testCardAsOwner()

	/**
	 * Notes name their editor; actions default sensibly.
	 *
	 * @return void
	 */
	public function testNoteAndAction(): void {
		$v = $this->views();

		$this->assertSame(['text' => 'hi', 'editedBy' => 'Piet', 'editedAt' => 'now'], $v->note(['note' => ['text' => 'hi', 'editedBy' => 'p1', 'editedAt' => 'now']]));
		$this->assertSame(['text' => '', 'editedBy' => '', 'editedAt' => ''], $v->note(['note' => 'bad']));

		$action = $v->action(['id' => 'a1', 'title' => 'T', 'assignee' => 'p1', 'endDate' => '2026-06-01T10:00']);
		$this->assertSame('once', $action['kind']);
		$this->assertSame('todo', $action['status']);
		$this->assertSame('2026-06-01', $action['endDate']);
		$this->assertSame('Piet', $action['assigneeName']);
		$this->assertSame('', $v->nameOf(''));
		$this->assertSame('', $v->nameOf('stranger'));
	}//end testNoteAndAction()
}//end class
