<?php

/**
 * Unit tests for the portal write guard on the error report owner.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/tasks.md#1-settings
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Listener\PortalReportOwnerGuardListener;
use OCA\Portaliq\Service\PortalReportOwnerCheck;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * REQ-PER-001 through the save: the real OpenRegister pre-write events.
 *
 * @covers \OCA\Portaliq\Listener\PortalReportOwnerGuardListener
 * @uses   \OCA\Portaliq\Service\PortalReportOwnerCheck
 *
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-error-reports-are-opt-in-and-need-a-named-owner-req-per-001
 */
class PortalReportOwnerGuardListenerTest extends TestCase {

	private const PORTAL_SCHEMA = 12;


	/**
	 * Skip where OpenRegister's event classes are not installed.
	 *
	 * @return void
	 */
	private function requireOpenRegister(): void {
		if (class_exists(ObjectCreatingEvent::class) === false) {
			$this->markTestSkipped(message: 'OpenRegister is not installed in this environment.');
		}
	}//end requireOpenRegister()


	/**
	 * The guard is registered on every portal create and update.
	 *
	 * @return void
	 */
	public function testTheGuardIsRegisteredOnEveryPortalWrite(): void {
		$application = (string) file_get_contents(dirname(__DIR__, 3).'/lib/Listener/ListenerRegistrar.php');

		$registration = "foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as \$event) {\n"
			."\t\t\t\$context->registerEventListener(\$event, PortalReportOwnerGuardListener::class);";

		$this->assertStringContainsString(needle: $registration, haystack: $application);
	}//end testTheGuardIsRegisteredOnEveryPortalWrite()


	/**
	 * Switching reports on without an owner is refused on create and on update, naming the missing owner.
	 *
	 * @return void
	 */
	public function testAPortalWithoutAnOwnerIsRefused(): void {
		$this->requireOpenRegister();
		$create = new ObjectCreatingEvent($this->portal(data: ['publicationErrorReports' => true]));
		$update = new ObjectUpdatingEvent($this->portal(data: ['publicationErrorReports' => true, 'publicationErrorReportOwner' => 'ghost']));

		$this->listener()->handle($create);
		$this->listener()->handle($update);

		$this->assertTrue(condition: $create->isPropagationStopped());
		$this->assertSame(expected: 'Error reports need an owner: name the user or group that receives them.', actual: $create->getErrors()['message']);
		$this->assertTrue(condition: $update->isPropagationStopped());
		$this->assertSame(expected: 'Error reports need an owner, and "ghost" is not a user or group.', actual: $update->getErrors()['message']);
	}//end testAPortalWithoutAnOwnerIsRefused()


	/**
	 * A portal with an existing owner, or with reports off, saves; another schema's object is left alone.
	 *
	 * @return void
	 */
	public function testAnOwnedOrSwitchedOffPortalSaves(): void {
		$this->requireOpenRegister();
		$owned = new ObjectCreatingEvent($this->portal(data: ['publicationErrorReports' => true, 'publicationErrorReportOwner' => 'woo-redactie']));
		$off   = new ObjectUpdatingEvent($this->portal(data: ['publicationErrorReports' => false]));
		$other = $this->portal(data: ['publicationErrorReports' => true]);
		$other->setSchema('99');
		$otherEvent = new ObjectCreatingEvent($other);

		foreach ([$owned, $off, $otherEvent] as $event) {
			$this->listener()->handle($event);
			$this->assertFalse(condition: $event->isPropagationStopped());
		}
	}//end testAnOwnedOrSwitchedOffPortalSaves()


	/**
	 * A portal entity.
	 *
	 * @param array<string, mixed> $data The portal's own fields.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function portal(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('p1');
		$entity->setSchema((string)self::PORTAL_SCHEMA);
		$entity->setObject($data + ['slug' => 'gemeente', 'title' => 'Gemeente']);

		return $entity;
	}//end portal()


	/**
	 * The listener over portaliq's portal schema, with group "woo-redactie".
	 *
	 * @return PortalReportOwnerGuardListener The listener.
	 */
	private function listener(): PortalReportOwnerGuardListener {
		$schema = new Schema();
		$schema->setId(self::PORTAL_SCHEMA);
		$schemas = $this->getMockBuilder(className: SchemaMapper::class)->disableOriginalConstructor()->onlyMethods(['findByApplicationAndSlug'])->getMock();
		$schemas->method('findByApplicationAndSlug')->with('portal', Application::APP_ID)->willReturn($schema);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($schemas);

		$users = $this->createMock(originalClassName: IUserManager::class);
		$users->method('userExists')->willReturn(false);
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('groupExists')->willReturnCallback(static fn (string $gid): bool => $gid === 'woo-redactie');
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => vsprintf($text, $params));

		return new PortalReportOwnerGuardListener(
			container: $container,
			owners: new PortalReportOwnerCheck(users: $users, groups: $groups, l10n: $l10n),
			logger: new NullLogger()
		);
	}//end listener()
}//end class
