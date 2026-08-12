<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalObjectWriter;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the OR writer: it degrades to null without OpenRegister, and — the
 * security-critical part — it STAMPS the subject ref + tenant server-side,
 * overriding any client-supplied value, and writes with OR RBAC bypassed.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T06
 */
class PortalObjectWriterTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	public function testReturnsNullWhenOpenRegisterUnavailable(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('OR not installed'));

		$writer = new PortalObjectWriter($container, $this->createMock(LoggerInterface::class));
		$this->assertNull($writer->createObject('portaliq', 'exampleDocument', 'subjectRef', 's1', 'org-1', ['title' => 'X']));

	}//end testReturnsNullWhenOpenRegisterUnavailable()

	public function testStampsOwnershipOverClientInputAndBypassesRbac(): void {
		$objectService = new class {

			/**
			 * @var array<string,mixed>
			 */
			public array $saved = [];

			public mixed $register = null;

			public mixed $schema = null;

			public bool $rbac = true;

			public bool $multitenancy = true;

			/**
			 * @param array<string,mixed> $object
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(
				array $object,
				?array $extend = null,
				mixed $register = null,
				mixed $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				$this->saved = $object;
				$this->register = $register;
				$this->schema = $schema;
				$this->rbac = $_rbac;
				$this->multitenancy = $_multitenancy;
				return array_merge($object, ['id' => 'new-id']);
			}//end saveObject()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService) {
				if ($id === self::OS) {
					return $objectService;
				}

				throw new RuntimeException('no service: ' . $id);
			}
		);

		$writer = new PortalObjectWriter($container, $this->createMock(LoggerInterface::class));
		// The client tries to smuggle a foreign subjectRef — it must be overwritten.
		$result = $writer->createObject('portaliq', 'exampleDocument', 'subjectRef', 's1', 'org-1', ['title' => 'X', 'subjectRef' => 'HACKER']);

		$this->assertSame('s1', $objectService->saved['subjectRef']);
		$this->assertSame('org-1', $objectService->saved['organisation']);
		$this->assertSame('X', $objectService->saved['title']);
		$this->assertSame('portaliq', $objectService->register);
		$this->assertSame('exampleDocument', $objectService->schema);
		$this->assertFalse($objectService->rbac);
		$this->assertFalse($objectService->multitenancy);
		$this->assertSame('new-id', $result['id']);

	}//end testStampsOwnershipOverClientInputAndBypassesRbac()

	public function testUpdateReturnsNullWhenOpenRegisterUnavailable(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('OR not installed'));

		$writer = new PortalObjectWriter($container, $this->createMock(LoggerInterface::class));
		$this->assertNull($writer->updateObject('portaliq', 'exampleDocument', 'subjectRef', 's1', 'org-1', 'd-1', ['title' => 'X']));

	}//end testUpdateReturnsNullWhenOpenRegisterUnavailable()

	/**
	 * Happy path: the subject patches its OWN object — only the whitelisted
	 * field changes, unrelated fields are preserved, the scope field is
	 * re-stamped, and the save targets the id (`uuid`) so OR UPDATES.
	 */
	public function testUpdatePatchesTheSubjectsOwnObjectAndReStampsScope(): void {
		$objectService = $this->updatableObjectService(
			[
				'exampleDocument' => [
					['id' => 'd-1', 'subjectRef' => 's1', 'organisation' => 'org-1', 'title' => 'Old', 'status' => 'open', 'internalNotes' => 'keep me'],
				],
			]
		);

		$writer = new PortalObjectWriter($this->container($objectService), $this->createMock(LoggerInterface::class));
		$result = $writer->updateObject('portaliq', 'exampleDocument', 'subjectRef', 's1', 'org-1', 'd-1', ['title' => 'New']);

		$this->assertTrue($objectService->saveCalled);
		// Whitelisted field changed; unrelated field preserved (a PATCH).
		$this->assertSame('New', $objectService->saved['title']);
		$this->assertSame('keep me', $objectService->saved['internalNotes']);
		// Scope re-stamped; the id preserved so OR updates, not creates.
		$this->assertSame('s1', $objectService->saved['subjectRef']);
		$this->assertSame('org-1', $objectService->saved['organisation']);
		$this->assertSame('d-1', $objectService->savedUuid);
		// The OR-managed @self envelope never round-trips into the save data.
		$this->assertArrayNotHasKey('@self', $objectService->saved);
		$this->assertFalse($objectService->rbac);
		$this->assertSame('New', $result['title']);

	}//end testUpdatePatchesTheSubjectsOwnObjectAndReStampsScope()

	/**
	 * ISOLATION / IDOR (closes #16): the subject supplies an id it does NOT
	 * own. Ownership is re-verified against OpenRegister FIRST, so the write is
	 * REFUSED — saveObject is never called — and null is returned (→ 404).
	 */
	public function testUpdateRefusesToPatchAForeignOwnedObjectAndNeverWrites(): void {
		$objectService = $this->updatableObjectService(
			[
				'exampleDocument' => [
					// The row exists, but belongs to a DIFFERENT subject.
					['id' => 'd-2', 'subjectRef' => 's2', 'organisation' => 'org-1', 'title' => 'Victim'],
				],
			]
		);

		$writer = new PortalObjectWriter($this->container($objectService), $this->createMock(LoggerInterface::class));
		$result = $writer->updateObject('portaliq', 'exampleDocument', 'subjectRef', 's1', 'org-1', 'd-2', ['title' => 'HACKED']);

		$this->assertNull($result);
		// THE pin: the write NEVER happened — ownership is verified before any save.
		$this->assertFalse($objectService->saveCalled);

	}//end testUpdateRefusesToPatchAForeignOwnedObjectAndNeverWrites()

	/**
	 * ISOLATION: a foreign tenant's row (same scope value) is also refused
	 * before any write.
	 */
	public function testUpdateRefusesAForeignTenantBeforeAnyWrite(): void {
		$objectService = $this->updatableObjectService(
			[
				'exampleDocument' => [
					['id' => 'd-3', 'subjectRef' => 's1', 'organisation' => 'org-2', 'title' => 'Other tenant'],
				],
			]
		);

		$writer = new PortalObjectWriter($this->container($objectService), $this->createMock(LoggerInterface::class));
		$this->assertNull($writer->updateObject('portaliq', 'exampleDocument', 'subjectRef', 's1', 'org-1', 'd-3', ['title' => 'X']));
		$this->assertFalse($objectService->saveCalled);

	}//end testUpdateRefusesAForeignTenantBeforeAnyWrite()

	public function testUpdateReturnsNullForANonExistentIdWithoutWriting(): void {
		$objectService = $this->updatableObjectService(
			[
				'exampleDocument' => [
					['id' => 'd-1', 'subjectRef' => 's1', 'title' => 'Mine'],
				],
			]
		);

		$writer = new PortalObjectWriter($this->container($objectService), $this->createMock(LoggerInterface::class));
		$this->assertNull($writer->updateObject('portaliq', 'exampleDocument', 'subjectRef', 's1', '', 'nope', ['title' => 'X']));
		$this->assertFalse($objectService->saveCalled);

	}//end testUpdateReturnsNullForANonExistentIdWithoutWriting()

	/**
	 * Defense in depth: even if a caller sneaks the scope field into `$data`
	 * (it should never be whitelisted), it is re-stamped to the subject's ref
	 * — a patch can never move a row out of the subject's scope.
	 */
	public function testUpdateReStampsScopeEvenWhenClientSneaksItIn(): void {
		$objectService = $this->updatableObjectService(
			[
				'exampleDocument' => [
					['id' => 'd-1', 'subjectRef' => 's1', 'organisation' => 'org-1', 'title' => 'Mine'],
				],
			]
		);

		$writer = new PortalObjectWriter($this->container($objectService), $this->createMock(LoggerInterface::class));
		// Client tries to smuggle a foreign subjectRef into the patch body.
		$writer->updateObject('portaliq', 'exampleDocument', 'subjectRef', 's1', 'org-1', 'd-1', ['title' => 'New', 'subjectRef' => 'HACKER']);

		$this->assertTrue($objectService->saveCalled);
		$this->assertSame('s1', $objectService->saved['subjectRef']);

	}//end testUpdateReStampsScopeEvenWhenClientSneaksItIn()

	public function testUpdateFailsClosedOnOpenRegisterWriteError(): void {
		$objectService = $this->updatableObjectService(
			[
				'exampleDocument' => [
					['id' => 'd-1', 'subjectRef' => 's1', 'organisation' => 'org-1', 'title' => 'Mine'],
				],
			],
			throwOnSave: true
		);

		$writer = new PortalObjectWriter($this->container($objectService), $this->createMock(LoggerInterface::class));
		$this->assertNull($writer->updateObject('portaliq', 'exampleDocument', 'subjectRef', 's1', 'org-1', 'd-1', ['title' => 'X']));

	}//end testUpdateFailsClosedOnOpenRegisterWriteError()

	/**
	 * A container resolving OpenRegister's ObjectService to the given stub.
	 */
	private function container(object $objectService): ContainerInterface {
		$mock = $this->createMock(ContainerInterface::class);
		$mock->method('get')->willReturnCallback(
			function (string $id) use ($objectService) {
				if ($id === self::OS) {
					return $objectService;
				}

				throw new RuntimeException('no service: ' . $id);
			}
		);
		return $mock;
	}//end container()

	/**
	 * An ObjectService stand-in for the update path: findAll serves canned
	 * rows per schema (the ownership re-read), saveObject records the write and
	 * whether it was called at all (the IDOR pin).
	 */
	private function updatableObjectService(array $rowsPerSchema, bool $throwOnSave = false): object {
		return new class($rowsPerSchema, $throwOnSave) {
			/**
			 * @var array<string,mixed>
			 */
			public array $saved = [];

			public mixed $savedUuid = null;

			public bool $saveCalled = false;

			public bool $rbac = true;

			private string $schema = '';

			public function __construct(
				private array $rows,
				private bool $throwOnSave,
			) {
			}//end __construct()

			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string,mixed> $config
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				return ($this->rows[$this->schema] ?? []);
			}//end findAll()

			/**
			 * Fetch a single canned row by id/uuid (OR's by-identifier read).
			 *
			 * @return array<string,mixed>|null
			 */
			public function find(string $id, string $register = '', string $schema = '', bool $_rbac = true, bool $_multitenancy = true): ?array {
				foreach (($this->rows[$schema] ?? []) as $row) {
					if (in_array($id, [($row['id'] ?? null), ($row['uuid'] ?? null)], true) === true) {
						return $row;
					}
				}

				return null;
			}//end find()

			/**
			 * @param array<string,mixed> $object
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(
				array $object,
				?array $extend = null,
				mixed $register = null,
				mixed $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				if ($this->throwOnSave === true) {
					throw new RuntimeException('OR write failed');
				}

				$this->saveCalled = true;
				$this->saved = $object;
				$this->savedUuid = $uuid;
				$this->rbac = $_rbac;
				return $object;
			}//end saveObject()
		};

	}//end updatableObjectService()
}//end class
