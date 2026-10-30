<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Portaliq\Listener\PortalAdminGroupsGuardListener;
use OCA\Portaliq\Service\Tenancy\PortalAdminScope;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Only an instance administrator changes a portal's adminGroups
 * (operate-portals-per-organisation REQ-OPO-005, "Only the hoster hands out the keys").
 *
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t11
 */
class PortalAdminGroupsGuardListenerTest extends TestCase {

	private const PORTAL_SCHEMA = 42;

	public function testAMemberAddingAGroupIsRefused(): void {
		$event = new ObjectUpdatingEvent(
			$this->portal(['zeist-beheer', 'utrecht-beheer']),
			$this->portal(['zeist-beheer'])
		);

		$this->listener(admin: false)->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('Only an administrator of this installation can change who administers a portal.', $event->getErrors()['message']);
	}//end testAMemberAddingAGroupIsRefused()

	public function testAMemberCreatingAPortalWithGroupsIsRefused(): void {
		$event = new ObjectCreatingEvent($this->portal(['zeist-beheer']));

		$this->listener(admin: false)->handle($event);

		$this->assertTrue($event->isPropagationStopped());
	}//end testAMemberCreatingAPortalWithGroupsIsRefused()

	public function testAMemberChangingTheRestPasses(): void {
		$new = $this->portal(['utrecht-beheer', 'zeist-beheer']);
		$new->setObject(['slug' => 'zeist', 'title' => 'Gemeente Zeist', 'adminGroups' => ['utrecht-beheer', 'zeist-beheer']]);
		$event = new ObjectUpdatingEvent($new, $this->portal(['zeist-beheer', 'utrecht-beheer']));

		$this->listener(admin: false)->handle($event);

		$this->assertFalse($event->isPropagationStopped(), 'same groups in another order is no change');
	}//end testAMemberChangingTheRestPasses()

	public function testAnAdministratorChangesTheGroups(): void {
		$event = new ObjectUpdatingEvent($this->portal(['utrecht-beheer']), $this->portal(['zeist-beheer']));

		$this->listener(admin: true)->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnAdministratorChangesTheGroups()

	public function testWithoutASignedInUserTheWritePasses(): void {
		$event = new ObjectCreatingEvent($this->portal(['zeist-beheer']));

		$this->listener(admin: false, signedIn: false)->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testWithoutASignedInUserTheWritePasses()

	public function testAnotherSchemasObjectIsLeftAlone(): void {
		$entity = $this->portal(['zeist-beheer']);
		$entity->setSchema('12');
		$event = new ObjectCreatingEvent($entity);

		$this->listener(admin: false)->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnotherSchemasObjectIsLeftAlone()

	/**
	 * A portal entity with these admin groups.
	 *
	 * @param array<string> $groups The adminGroups.
	 *
	 * @return ObjectEntity
	 */
	private function portal(array $groups): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('p1');
		$entity->setSchema((string)self::PORTAL_SCHEMA);
		$entity->setObject(['slug' => 'zeist', 'title' => 'Zeist', 'adminGroups' => $groups]);

		return $entity;
	}//end portal()

	/**
	 * The listener for one writer.
	 *
	 * @param bool $admin    Whether the writer administers the instance.
	 * @param bool $signedIn Whether there is a signed-in writer at all.
	 *
	 * @return PortalAdminGroupsGuardListener
	 */
	private function listener(bool $admin, bool $signedIn=true): PortalAdminGroupsGuardListener {
		$schema = new Schema();
		$schema->setId(self::PORTAL_SCHEMA);
		$schemas = $this->getMockBuilder(SchemaMapper::class)->disableOriginalConstructor()->onlyMethods(['findByApplicationAndSlug'])->getMock();
		$schemas->method('findByApplicationAndSlug')->with('portal', 'portaliq')->willReturn($schema);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($schemas);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);
		$scope = $this->getMockBuilder(PortalAdminScope::class)->disableOriginalConstructor()->onlyMethods(['mayChangeAdminGroups'])->getMock();
		$scope->method('mayChangeAdminGroups')->willReturn($admin);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => vsprintf($text, $params));

		return new PortalAdminGroupsGuardListener($container, $session, $scope, $l10n, new NullLogger());
	}//end listener()
}//end class
