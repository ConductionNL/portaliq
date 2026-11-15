<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Actions;

use OCA\Portaliq\Service\Actions\ActionAccess;
use OCA\Portaliq\Service\Identity\PortalContactService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What a viewer may do with one action.
 */
#[CoversClass(ActionAccess::class)]
class ActionAccessTest extends TestCase {
	/**
	 * Every viewer, action and plan membership reads exactly as recorded.
	 *
	 * @return void
	 */
	public function testEveryViewerIsAsRecorded(): void {
		$access = $this->access();
		$anna   = ['subjectRef' => 'anna', 'organisation' => 'org'];
		$plan   = ['owner' => 'piet', 'participants' => ['anna', 7, 'kees'], 'status' => 'done', 'title' => 'Verhuizen'];

		$this->assertSame(
			['role' => 'member', 'scopeField' => 'plan', 'scopeValue' => 'p-1', 'members' => ['piet', 'anna', 'kees'], 'mayDelete' => true, 'readOnly' => true, 'planTitle' => 'Verhuizen'],
			$access->forAction(subject: $anna, row: ['owner' => 'anna', 'plan' => 'p-1'], membership: ['plan' => $plan, 'isOwner' => false])
		);
		$this->assertSame(
			['role' => 'member', 'scopeField' => 'plan', 'scopeValue' => '', 'members' => [''], 'mayDelete' => true, 'readOnly' => false, 'planTitle' => ''],
			$access->forAction(subject: [], row: [], membership: ['plan' => [], 'isOwner' => true])
		);
		$this->assertSame(
			['role' => 'member', 'scopeField' => 'plan', 'scopeValue' => 'p-2', 'members' => ['piet', 'x'], 'mayDelete' => false, 'readOnly' => false, 'planTitle' => ''],
			$access->forAction(subject: $anna, row: ['owner' => 'piet', 'plan' => 'p-2'], membership: ['plan' => ['owner' => 'piet', 'participants' => 'x', 'status' => 'running'], 'isOwner' => false])
		);
		$this->assertSame(
			['role' => 'owner', 'scopeField' => 'owner', 'members' => ['anna', 'kees'], 'mayDelete' => true, 'scopeValue' => 'anna', 'readOnly' => false, 'planTitle' => ''],
			$access->forAction(subject: $anna, row: ['owner' => 'anna', 'assignee' => 'anna'], membership: null)
		);
		$this->assertSame(
			['role' => 'assignee', 'scopeField' => 'assignee', 'members' => ['anna'], 'mayDelete' => false, 'scopeValue' => 'anna', 'readOnly' => false, 'planTitle' => ''],
			$access->forAction(subject: $anna, row: ['owner' => 'piet', 'assignee' => 'anna'], membership: null)
		);
		$this->assertNull($access->forAction(subject: $anna, row: ['owner' => 'piet', 'assignee' => 'kees'], membership: null));
		$this->assertNull($access->forAction(subject: ['subjectRef' => ''], row: ['owner' => '', 'assignee' => ''], membership: null));
		$this->assertNull($access->forAction(subject: [], row: [], membership: null));
	}//end testEveryViewerIsAsRecorded()

	/**
	 * A resident's own action may go to the resident and their approved contacts, each once.
	 *
	 * @return void
	 */
	public function testOwnMembersAreTheResidentAndTheirContacts(): void {
		$access = $this->access();

		$this->assertSame(['anna', 'kees'], $access->ownMembers(subject: ['subjectRef' => 'anna', 'organisation' => 'org']));
		$this->assertSame([], $access->ownMembers(subject: []));
	}//end testOwnMembersAreTheResidentAndTheirContacts()

	/**
	 * The access over a contact list: anna's approved contacts in org.
	 *
	 * @return ActionAccess
	 */
	private function access(): ActionAccess {
		$contacts = $this->createMock(PortalContactService::class);
		$contacts->method('approvedContacts')->willReturnCallback(
			static fn (string $owner, string $organisation): array => ($owner === 'anna' && $organisation === 'org' ? [['ref' => 'kees'], ['ref' => 'anna'], ['ref' => 5]] : [])
		);

		return new ActionAccess($contacts);
	}//end access()
}//end class
