<?php

/**
 * The app that owns an account claim decides whether it scopes for the account's organisation.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Tenancy
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
 * @spec openspec/changes/operate-portals-per-organisation/specs/portal-tenancy/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Tenancy;

use OCA\Portaliq\Event\PortalClaimScopeEvent;
use OCA\Portaliq\Service\Identity\PortalAccountsByClaim;
use OCA\Portaliq\Service\Tenancy\ClaimScopeCheck;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Portaliq\Service\Tenancy\ClaimScopeCheck
 * @covers \OCA\Portaliq\Event\PortalClaimScopeEvent
 * @covers \OCA\Portaliq\Service\Identity\PortalAccountsByClaim
 */
class ClaimScopeCheckTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * A claim check the listener sees, for one account of org-1.
	 *
	 * @return PortalClaimScopeEvent
	 */
	private static function event(): PortalClaimScopeEvent {
		return new PortalClaimScopeEvent(
			claim: ['appId' => 'learniq', 'claimName' => 'organisationRef'],
			holder: ['value' => 'co-1', 'organisation' => 'org-1']
		);
	}//end event()

	/**
	 * A container answering the dispatcher with this listener, and the object service.
	 *
	 * @param \Closure(PortalClaimScopeEvent): void|null $listener The owning app's answer; null leaves no dispatcher.
	 * @param object|null                                $objects  The object service.
	 *
	 * @return ContainerInterface
	 */
	private function container(?\Closure $listener, ?object $objects=null): ContainerInterface {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($listener): void {
				if ($event instanceof PortalClaimScopeEvent === true && $listener !== null) {
					$listener($event);
				}
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($listener, $dispatcher, $objects): object {
				if ($id === IEventDispatcher::class && $listener !== null) {
					return $dispatcher;
				}

				if ($id === self::OS && $objects !== null) {
					return $objects;
				}

				throw new RuntimeException('no service: ' . $id);
			}
		);

		return $container;
	}//end container()

	public function testTheEventCarriesTheClaimAndItsHolder(): void {
		$event = self::event();

		$this->assertSame(
			['learniq', 'organisationRef', 'co-1', 'org-1', false],
			[$event->getAppId(), $event->getClaimName(), $event->getValue(), $event->getOrganisation(), $event->isRefused()]
		);
	}//end testTheEventCarriesTheClaimAndItsHolder()

	public function testAClaimNobodyRefusesIsAdmitted(): void {
		$check = new ClaimScopeCheck(container: $this->container(listener: static function (PortalClaimScopeEvent $event): void {
		}), logger: new NullLogger());

		$this->assertTrue($check->admits(event: self::event()));
	}//end testAClaimNobodyRefusesIsAdmitted()

	public function testARefusedClaimIsNotAdmitted(): void {
		$check = new ClaimScopeCheck(container: $this->container(listener: static function (PortalClaimScopeEvent $event): void {
			$event->refuse();
		}), logger: new NullLogger());

		$this->assertFalse($check->admits(event: self::event()));
	}//end testARefusedClaimIsNotAdmitted()

	public function testWithoutADispatcherTheClaimIsRefused(): void {
		$check = new ClaimScopeCheck(container: $this->container(listener: null), logger: new NullLogger());

		$this->assertFalse($check->admits(event: self::event()));
	}//end testWithoutADispatcherTheClaimIsRefused()

	public function testAServiceThatIsNoDispatcherRefusesTheClaim(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new \stdClass());
		$check = new ClaimScopeCheck(container: $container, logger: new NullLogger());

		$this->assertFalse($check->admits(event: self::event()));
	}//end testAServiceThatIsNoDispatcherRefusesTheClaim()

	public function testAListenerThatFailsRefusesTheClaim(): void {
		$check = new ClaimScopeCheck(container: $this->container(listener: static function (PortalClaimScopeEvent $event): void {
			throw new RuntimeException('store down');
		}), logger: new NullLogger());

		$this->assertFalse($check->admits(event: self::event()));
	}//end testAListenerThatFailsRefusesTheClaim()

	public function testChangeNoticesSkipAnAccountWhoseClaimItsAppRefuses(): void {
		$objects = new class {
			public function setRegister(string $register): void {
			}

			public function setSchema(string $schema): void {
			}

			/**
			 * @param array<string, mixed> $config
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config, bool $_rbac=true, bool $_multitenancy=true): array {
				return [
					['subjectRef' => 'own', 'status' => 'active', 'organisation' => 'org-1', 'claims' => ['learniq' => ['organisationRef' => 'co-1']]],
					['subjectRef' => 'foreign', 'status' => 'active', 'organisation' => 'org-2', 'claims' => ['learniq' => ['organisationRef' => 'co-1']]],
				];
			}
		};
		$seen = [];
		$listener = static function (PortalClaimScopeEvent $event) use (&$seen): void {
			$seen[] = [$event->getAppId(), $event->getClaimName(), $event->getValue(), $event->getOrganisation()];
			if ($event->getOrganisation() !== 'org-1') {
				$event->refuse();
			}
		};

		$accounts = new PortalAccountsByClaim(container: $this->container(listener: $listener, objects: $objects), logger: new NullLogger());

		$this->assertSame(['own'], array_column($accounts->find(appId: 'learniq', claim: 'organisationRef', value: 'co-1'), 'subjectRef'));
		$this->assertSame([['learniq', 'organisationRef', 'co-1', 'org-1'], ['learniq', 'organisationRef', 'co-1', 'org-2']], $seen);

		$unchecked = new PortalAccountsByClaim(container: $this->container(listener: null, objects: $objects), logger: new NullLogger());
		$this->assertSame([], $unchecked->find(appId: 'learniq', claim: 'organisationRef', value: 'co-1'));
	}//end testChangeNoticesSkipAnAccountWhoseClaimItsAppRefuses()
}//end class
