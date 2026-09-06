<?php

/**
 * Unit tests for TrafficEventStore.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Traffic;

use OCA\Portaliq\Service\PortalRegisterContext;
use OCA\Portaliq\Service\Traffic\TrafficEventStore;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The two write paths, and which one the store takes.
 */
class TrafficEventStoreTest extends TestCase {


	/**
	 * A store over a fake ObjectService.
	 *
	 * @param object $objectService The fake.
	 *
	 * @return TrafficEventStore The store.
	 */
	private function store(object $objectService): TrafficEventStore {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturn(true);

		return new TrafficEventStore($container, $this->createMock(LoggerInterface::class), $context);
	}//end store()


	/**
	 * An OpenRegister with the raw entry point gets the raw call, and only
	 * the raw call.
	 *
	 * @return void
	 */
	public function testTheRawPathIsUsedWhenTheServiceOffersIt(): void {
		$fake = new class {
			public array $raw = [];
			public array $saved = [];

			public function appendObjectsRaw(array $objects, string $register, string $schema): int {
				$this->raw[] = [$objects, $register, $schema];
				return count($objects);
			}

			public function saveObjects(array $objects): array {
				$this->saved[] = $objects;
				return $objects;
			}
		};

		$written = $this->store($fake)->append(records: [['name' => 'page_view'], ['name' => 'scroll']]);

		$this->assertSame(2, $written);
		$this->assertCount(1, $fake->raw);
		$this->assertSame(['portaliq', 'portalTrafficEvent'], [$fake->raw[0][1], $fake->raw[0][2]]);
		$this->assertSame([], $fake->saved, 'the ordinary path must not ALSO be taken');
	}//end testTheRawPathIsUsedWhenTheServiceOffersIt()


	/**
	 * An older OpenRegister falls back to saveObjects with everything off,
	 * and the events are still stored rather than dropped.
	 *
	 * @return void
	 */
	public function testTheFallbackPathStoresWithAuditValidationAndEventsOff(): void {
		$fake = new class {
			public array $calls = [];

			public function saveObjects(
				array $objects,
				?string $register = null,
				?string $schema = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $validation = false,
				bool $events = false,
				bool $deduplicateIds = true,
				bool $enrich = true,
				bool $_audit = true,
			): array {
				$this->calls[] = compact('register', 'schema', '_rbac', '_multitenancy', 'validation', 'events', 'enrich', '_audit');
				return $objects;
			}
		};

		$written = $this->store($fake)->append(records: [['name' => 'page_view']]);

		$this->assertSame(1, $written);
		$this->assertSame(
			[
				'register' => 'portaliq',
				'schema' => 'portalTrafficEvent',
				'_rbac' => false,
				'_multitenancy' => false,
				'validation' => false,
				'events' => false,
				'enrich' => false,
				'_audit' => false,
			],
			$fake->calls[0]
		);
	}//end testTheFallbackPathStoresWithAuditValidationAndEventsOff()


	/**
	 * A rollup is saved WITH its uuid when one exists, so the day is
	 * replaced rather than duplicated.
	 *
	 * @return void
	 */
	public function testARollupIsUpdatedInPlaceWhenItExists(): void {
		$fake = new class {
			public array $saves = [];

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				return [['@self' => ['uuid' => 'u-1'], 'portal' => 'p', 'date' => '2026-09-04', 'pageViews' => 3]];
			}

			public function saveObject(array $object, ?string $register = null, ?string $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saves[] = ['uuid' => $uuid, 'schema' => $schema, 'object' => $object];
				return $object;
			}
		};
		$store = $this->store($fake);

		$existing = $store->findDaily(portal: 'p', date: '2026-09-04');
		$this->assertSame('u-1', $existing['@self']['uuid']);

		$store->saveDaily(rollup: ['portal' => 'p', 'date' => '2026-09-04', 'pageViews' => 5], uuid: 'u-1');
		$this->assertSame('u-1', $fake->saves[0]['uuid']);
		$this->assertSame('portalTrafficDaily', $fake->saves[0]['schema']);
	}//end testARollupIsUpdatedInPlaceWhenItExists()


	/**
	 * No OpenRegister means nothing written and nothing thrown.
	 *
	 * @return void
	 */
	public function testAMissingOpenRegisterDegradesToZero(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('no such service'));
		$store = new TrafficEventStore(
			$container,
			$this->createMock(LoggerInterface::class),
			$this->createMock(PortalRegisterContext::class)
		);

		$this->assertSame(0, $store->append(records: [['name' => 'page_view']]));
		$this->assertSame([], $store->eventsBetween(portal: 'p', from: 'a', to: 'b'));
		$this->assertSame(0, $store->purgeExpired());
	}//end testAMissingOpenRegisterDegradesToZero()


}//end class
