<?php

/**
 * Unit tests for ExampleSiteStore.
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

namespace OCA\Portaliq\Tests\Unit\Service\ExampleSite;

use JsonSerializable;
use OCA\Portaliq\Service\ExampleSite\ExampleSiteStore;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalRegisterContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * The store's calls to OpenRegister, pinned on a recording object service.
 */
class ExampleSiteStoreTest extends TestCase {
	/**
	 * A read points the service at the schema through the context helper,
	 * hands the filters over, switches the access rules off, and turns
	 * entities into plain rows.
	 *
	 * @return void
	 */
	public function testFindReadsTheSchemaWithItsFilters(): void {
		$service = $this->objectService();
		$context = $this->createMock(PortalRegisterContext::class);
		$context->expects($this->once())->method('apply')->with($service, 'page')->willReturn(true);

		$rows = $this->store(service: $service, context: $context)->find(schema: 'page', filters: ['portal' => 'zuiddrecht']);

		$this->assertSame([['id' => 'a', 'route' => '/'], ['id' => 'b', 'route' => '/afval']], $rows);
		$this->assertSame(
			[['config' => ['filters' => ['portal' => 'zuiddrecht'], 'limit' => 500, 'offset' => 0], '_rbac' => false, '_multitenancy' => false]],
			$service->reads
		);
	}//end testFindReadsTheSchemaWithItsFilters()

	/**
	 * A schema this app does not own, a failing read and a missing
	 * OpenRegister all read as no rows.
	 *
	 * @return void
	 */
	public function testFindFailsToNoRows(): void {
		$refusing = $this->createMock(PortalRegisterContext::class);
		$refusing->method('apply')->willReturn(false);
		$this->assertSame([], $this->store(service: $this->objectService(), context: $refusing)->find(schema: 'nope', filters: []));

		$failing = $this->objectService();
		$failing->fail = true;
		$this->assertSame([], $this->store(service: $failing)->find(schema: 'page', filters: []));

		$absent = $this->store(service: null);
		$this->assertFalse($absent->available());
		$this->assertSame([], $absent->find(schema: 'page', filters: []));
		$this->assertFalse($absent->delete(schema: 'page', id: 'a'));
	}//end testFindFailsToNoRows()

	/**
	 * A write goes through the writer into the portaliq register and
	 * answers with the row's id; a failed write answers null.
	 *
	 * @return void
	 */
	public function testCreateAnswersWithTheId(): void {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->exactly(3))->method('createAnonymousObject')
			->with('portaliq', 'page', ['route' => '/afval'])
			->willReturnOnConsecutiveCalls(['@self' => ['id' => 'uuid-1'], 'route' => '/afval'], null, ['route' => '/afval']);
		$store = $this->store(service: $this->objectService(), writer: $writer);

		$this->assertSame('uuid-1', $store->create(schema: 'page', data: ['route' => '/afval']));
		$this->assertNull($store->create(schema: 'page', data: ['route' => '/afval']));
		// A row without an id cannot be recorded, so it does not count as written.
		$this->assertNull($store->create(schema: 'page', data: ['route' => '/afval']));
	}//end testCreateAnswersWithTheId()

	/**
	 * A delete names the row, the register and the schema.
	 *
	 * @return void
	 */
	public function testDeleteNamesTheRow(): void {
		$service = $this->objectService();
		$store = $this->store(service: $service);

		$this->assertTrue($store->available());
		$this->assertTrue($store->delete(schema: 'menu', id: 'uuid-9'));
		$this->assertFalse($store->delete(schema: 'menu', id: ''));
		$this->assertSame(
			[['uuid' => 'uuid-9', 'register' => 'portaliq', 'schema' => 'menu', '_rbac' => false, '_multitenancy' => false]],
			$service->deletes
		);

		$service->fail = true;
		$this->assertFalse($store->delete(schema: 'menu', id: 'uuid-9'));
	}//end testDeleteNamesTheRow()

	/**
	 * The id is read from wherever OpenRegister put it.
	 *
	 * @return void
	 */
	public function testIdOf(): void {
		$store = $this->store(service: null);
		$this->assertSame('a', $store->idOf(row: ['@self' => ['id' => 'a', 'uuid' => 'b'], 'id' => 'c']));
		$this->assertSame('b', $store->idOf(row: ['@self' => ['uuid' => 'b']]));
		$this->assertSame('c', $store->idOf(row: ['id' => 'c']));
		$this->assertSame('d', $store->idOf(row: ['uuid' => 'd']));
		$this->assertSame('', $store->idOf(row: ['title' => 'x']));
	}//end testIdOf()

	/**
	 * A store over the given object service.
	 *
	 * @param object|null                $service The object service, or null when OpenRegister is absent.
	 * @param PortalRegisterContext|null $context The context helper; one that accepts every schema when null.
	 * @param PortalObjectWriter|null    $writer  The writer; a mock when null.
	 *
	 * @return ExampleSiteStore
	 */
	private function store(?object $service, ?PortalRegisterContext $context = null, ?PortalObjectWriter $writer = null): ExampleSiteStore {
		$container = $this->createMock(ContainerInterface::class);
		if ($service === null) {
			$container->method('get')->willThrowException(new RuntimeException('not installed'));
		} else {
			$container->method('get')->with('OCA\\OpenRegister\\Service\\ObjectService')->willReturn($service);
		}

		if ($context === null) {
			$context = $this->createMock(PortalRegisterContext::class);
			$context->method('apply')->willReturn(true);
		}

		return new ExampleSiteStore($container, $context, ($writer ?? $this->createMock(PortalObjectWriter::class)), new NullLogger());
	}//end store()

	/**
	 * An object service that records what it is asked.
	 *
	 * @return object
	 */
	private function objectService(): object {
		return new class() {
			/** @var array<int, array<string, mixed>> */
			public array $reads = [];

			/** @var array<int, array<string, mixed>> */
			public array $deletes = [];

			public bool $fail = false;

			/**
			 * @param array<string, mixed> $config        The read's config.
			 * @param bool                 $_rbac         Access rules.
			 * @param bool                 $_multitenancy Tenant rules.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->fail === true) {
					throw new RuntimeException('read failed');
				}

				$this->reads[] = ['config' => $config, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy];

				return [
					['id' => 'a', 'route' => '/'],
					new class() implements JsonSerializable {
						/**
						 * @return array<string, string>
						 */
						public function jsonSerialize(): array {
							return ['id' => 'b', 'route' => '/afval'];
						}//end jsonSerialize()
					},
					'not a row',
				];
			}//end findAll()

			/**
			 * @param string $uuid          The row.
			 * @param string $register      The register.
			 * @param string $schema        The schema.
			 * @param bool   $_rbac         Access rules.
			 * @param bool   $_multitenancy Tenant rules.
			 *
			 * @return bool
			 */
			public function deleteObject(string $uuid, string $register, string $schema, bool $_rbac = true, bool $_multitenancy = true): bool {
				if ($this->fail === true) {
					throw new RuntimeException('delete failed');
				}

				$this->deletes[] = ['uuid' => $uuid, 'register' => $register, 'schema' => $schema, '_rbac' => $_rbac, '_multitenancy' => $_multitenancy];

				return true;
			}//end deleteObject()
		};
	}//end objectService()
}//end class
