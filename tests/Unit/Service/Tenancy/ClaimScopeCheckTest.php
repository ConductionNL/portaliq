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
 * @uses   \OCA\Portaliq\Service\Tenancy\PortalTenantContext
 * @uses   \OCA\Portaliq\Service\ObjectServiceLookup
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
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($listener): void {
				if ($event instanceof PortalClaimScopeEvent === true && $listener !== null) {
					$listener($event);
				}
			}
		);
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($listener, $dispatcher, $objects): object {
				if ($id === IEventDispatcher::class && $listener !== null) {
					return $dispatcher;
				}

				if ($id === self::OS && $objects !== null) {
					return $objects;
				}

				throw new RuntimeException(message: 'no service: ' . $id);
			}
		);

		return $container;
	}//end container()

	/**
	 * The event carries the claim, its value and the account's organisation, unrefused.
	 *
	 * @return void
	 */
	public function testTheEventCarriesTheClaimAndItsHolder(): void {
		$event = self::event();

		$this->assertSame(
			expected: ['learniq', 'organisationRef', 'co-1', 'org-1', false],
			actual: [$event->getAppId(), $event->getClaimName(), $event->getValue(), $event->getOrganisation(), $event->isRefused()]
		);
	}//end testTheEventCarriesTheClaimAndItsHolder()

	/**
	 * A claim no listener refuses is admitted.
	 *
	 * @return void
	 */
	public function testAClaimNobodyRefusesIsAdmitted(): void {
		$check = new ClaimScopeCheck(container: $this->container(listener: static function (PortalClaimScopeEvent $event): void {
		}), logger: new NullLogger());

		$this->assertTrue(condition: $check->admits(event: self::event()));
	}//end testAClaimNobodyRefusesIsAdmitted()

	/**
	 * A claim a listener refuses is not admitted.
	 *
	 * @return void
	 */
	public function testARefusedClaimIsNotAdmitted(): void {
		$check = new ClaimScopeCheck(container: $this->container(listener: static function (PortalClaimScopeEvent $event): void {
			$event->refuse();
		}), logger: new NullLogger());

		$this->assertFalse(condition: $check->admits(event: self::event()));
	}//end testARefusedClaimIsNotAdmitted()

	/**
	 * Without an event dispatcher the claim is refused.
	 *
	 * @return void
	 */
	public function testWithoutADispatcherTheClaimIsRefused(): void {
		$check = new ClaimScopeCheck(container: $this->container(listener: null), logger: new NullLogger());

		$this->assertFalse(condition: $check->admits(event: self::event()));
	}//end testWithoutADispatcherTheClaimIsRefused()

	/**
	 * A container answer that is no dispatcher refuses the claim.
	 *
	 * @return void
	 */
	public function testAServiceThatIsNoDispatcherRefusesTheClaim(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn(new \stdClass());
		$check = new ClaimScopeCheck(container: $container, logger: new NullLogger());

		$this->assertFalse(condition: $check->admits(event: self::event()));
	}//end testAServiceThatIsNoDispatcherRefusesTheClaim()

	/**
	 * A listener that throws refuses the claim.
	 *
	 * @return void
	 */
	public function testAListenerThatFailsRefusesTheClaim(): void {
		$check = new ClaimScopeCheck(container: $this->container(listener: static function (PortalClaimScopeEvent $event): void {
			throw new RuntimeException(message: 'store down');
		}), logger: new NullLogger());

		$this->assertFalse(condition: $check->admits(event: self::event()));
	}//end testAListenerThatFailsRefusesTheClaim()

	/**
	 * Change notices skip an account whose claim its app refuses, and skip all without a dispatcher.
	 *
	 * @return void
	 */
	public function testChangeNoticesSkipAnAccountWhoseClaimItsAppRefuses(): void {
		$objects = new class {
			/**
			 * Ignored: one register.
			 *
			 * @param string $register The register.
			 *
			 * @return void
			 */
			public function setRegister(string $register): void {
			}

			/**
			 * Ignored: one schema.
			 *
			 * @param string $schema The schema.
			 *
			 * @return void
			 */
			public function setSchema(string $schema): void {
			}

			/**
			 * Two active accounts holding the same company, in org-1 and org-2.
			 *
			 * @param array<string, mixed> $config        The query.
			 * @param bool                 $_rbac         Ignored.
			 * @param bool                 $_multitenancy Ignored.
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

		$found = $accounts->find(appId: 'learniq', claim: 'organisationRef', value: 'co-1');
		$this->assertSame(expected: ['own'], actual: array_column($found, 'subjectRef'));
		$this->assertSame(expected: [['learniq', 'organisationRef', 'co-1', 'org-1'], ['learniq', 'organisationRef', 'co-1', 'org-2']], actual: $seen);

		$unchecked = new PortalAccountsByClaim(container: $this->container(listener: null, objects: $objects), logger: new NullLogger());
		$this->assertSame(expected: [], actual: $unchecked->find(appId: 'learniq', claim: 'organisationRef', value: 'co-1'));
	}//end testChangeNoticesSkipAnAccountWhoseClaimItsAppRefuses()
}//end class
