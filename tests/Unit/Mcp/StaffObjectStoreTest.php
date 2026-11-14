<?php

/**
 * Unit tests for StaffObjectStore: reads and writes under the caller's RBAC.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/portaliq-mcp-surface/spec.md#requirement-every-staff-write-action-is-a-curated-tool-governed-by-scope-and-reach
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Mcp;

use OCA\Portaliq\Mcp\StaffObjectStore;
use OCA\Portaliq\Service\PortalRegisterContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A fake ObjectService that records the arguments it was called with.
 */
class StaffObjectStoreFakeObjectService {

	/**
	 * The last call, by method name.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $calls = [];

	/**
	 * What find() and saveObject() answer, or an exception to throw.
	 *
	 * @var mixed
	 */
	public mixed $answer = null;

	/**
	 * Record a find.
	 *
	 * @param string $id            The id.
	 * @param string $register      The register.
	 * @param string $schema        The schema.
	 * @param bool   $_rbac         Whether RBAC applies.
	 * @param bool   $_multitenancy Whether multitenancy applies.
	 *
	 * @return mixed
	 */
	public function find(string $id, string $register, string $schema, bool $_rbac = true, bool $_multitenancy = true): mixed {
		$this->calls['find'] = ['id' => $id, 'register' => $register, 'schema' => $schema, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
		return $this->respond();
	}//end find()

	/**
	 * Record a save.
	 *
	 * @param array<string, mixed> $object        The object.
	 * @param string               $register      The register.
	 * @param string               $schema        The schema.
	 * @param string|null          $uuid          The id to update.
	 * @param bool                 $_rbac         Whether RBAC applies.
	 * @param bool                 $_multitenancy Whether multitenancy applies.
	 *
	 * @return mixed
	 */
	public function saveObject(array $object, string $register, string $schema, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): mixed {
		$this->calls['saveObject'] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
		return $this->respond();
	}//end saveObject()

	/**
	 * The configured answer.
	 *
	 * @return mixed
	 */
	private function respond(): mixed {
		if ($this->answer instanceof \Throwable) {
			throw $this->answer;
		}

		return $this->answer;
	}//end respond()
}//end class

/**
 * The store always reads and writes with RBAC and multitenancy on.
 */
#[CoversClass(StaffObjectStore::class)]
class StaffObjectStoreTest extends TestCase {

	/** @var ContainerInterface&MockObject */
	private ContainerInterface $container;

	/** @var PortalRegisterContext&MockObject */
	private PortalRegisterContext $context;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	private StaffObjectStoreFakeObjectService $objectService;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = new StaffObjectStoreFakeObjectService();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturn($this->objectService);
		$this->context = $this->createMock(PortalRegisterContext::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The store over the collaborators.
	 *
	 * @return StaffObjectStore
	 */
	private function store(): StaffObjectStore {
		return new StaffObjectStore(container: $this->container, context: $this->context, logger: $this->logger);
	}//end store()

	/**
	 * A read runs as the caller and answers the entity as an array.
	 *
	 * @return void
	 */
	public function testAReadRunsUnderTheCallersRbac(): void {
		$this->context->expects($this->once())->method('apply')->with($this->objectService, 'page')->willReturn(true);
		$this->objectService->answer = new class {
			/**
			 * The serialised entity.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'p1', 'title' => 'Hours'];
			}//end jsonSerialize()
		};

		$this->assertSame(['id' => 'p1', 'title' => 'Hours'], $this->store()->find(schema: 'page', id: 'p1'));
		$this->assertSame(['id' => 'p1', 'register' => 'portaliq', 'schema' => 'page', 'rbac' => true, 'multitenancy' => true], $this->objectService->calls['find']);
	}//end testAReadRunsUnderTheCallersRbac()

	/**
	 * A save runs as the caller, as an update when an id is given.
	 *
	 * @return void
	 */
	public function testASaveRunsUnderTheCallersRbac(): void {
		$this->context->method('apply')->willReturn(true);
		$this->objectService->answer = ['id' => 'p1', 'status' => 'published'];

		$this->assertSame(['id' => 'p1', 'status' => 'published'], $this->store()->save(schema: 'page', object: ['status' => 'published'], id: 'p1'));
		$this->assertSame(
			['object' => ['status' => 'published'], 'register' => 'portaliq', 'schema' => 'page', 'uuid' => 'p1', 'rbac' => true, 'multitenancy' => true],
			$this->objectService->calls['saveObject']
		);
	}//end testASaveRunsUnderTheCallersRbac()

	/**
	 * An RBAC refusal (an exception from OpenRegister) is null and logged.
	 *
	 * @return void
	 */
	public function testARefusedWriteIsNull(): void {
		$this->context->method('apply')->willReturn(true);
		$this->objectService->answer = new RuntimeException('not allowed');
		$this->logger->expects($this->once())->method('warning')->with('Portaliq: staff tool write failed', ['schema' => 'menu', 'reason' => 'not allowed']);

		$this->assertNull($this->store()->save(schema: 'menu', object: ['title' => 'x']));
	}//end testARefusedWriteIsNull()

	/**
	 * An unknown schema touches nothing, and an empty id reads nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownSchemaOrEmptyIdReadsNothing(): void {
		$this->context->method('apply')->willReturnOnConsecutiveCalls(false, true);

		$this->assertNull($this->store()->find(schema: 'nope', id: 'x'));
		$this->assertNull($this->store()->find(schema: 'page', id: ''));
		$this->assertSame([], $this->objectService->calls);
	}//end testAnUnknownSchemaOrEmptyIdReadsNothing()

	/**
	 * Without OpenRegister the store answers null and logs it.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheStoreAnswersNull(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('absent'));
		$this->logger->expects($this->once())->method('warning');

		$store = new StaffObjectStore(container: $container, context: $this->context, logger: $this->logger);

		$this->assertNull($store->find(schema: 'page', id: 'p1'));
	}//end testWithoutOpenRegisterTheStoreAnswersNull()
}//end class
