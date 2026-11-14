<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\ExampleResident;

use OCA\Portaliq\Service\ExampleResident\ExampleResidentStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Updating and deleting an example resident's rows: a merge over the stored
 * row, never a blind write, and a failure answers false with a warning.
 */
#[CoversClass(ExampleResidentStore::class)]
class ExampleResidentStoreTest extends TestCase {

	/**
	 * Build the store over an object service double.
	 *
	 * @param object|null          $objects The object service, or null when OpenRegister is absent.
	 * @param LoggerInterface|null $logger  The logger.
	 *
	 * @return ExampleResidentStore
	 */
	private function store(?object $objects, ?LoggerInterface $logger = null): ExampleResidentStore {
		$container = $this->createMock(ContainerInterface::class);
		if ($objects === null) {
			$container->method('get')->willThrowException(new RuntimeException('no OpenRegister'));
		} else {
			$container->method('get')->willReturn($objects);
		}

		return new ExampleResidentStore($container, $logger ?? $this->createMock(LoggerInterface::class));
	}//end store()

	/**
	 * An object service holding one stored row, recording every write.
	 *
	 * @param array<string, mixed>|null $row      The stored row.
	 * @param \Throwable|null           $fails    What a write throws.
	 * @param mixed                     $deletes  What a delete answers.
	 *
	 * @return object
	 */
	private function objects(?array $row, ?\Throwable $fails = null, mixed $deletes = true): object {
		return new class ($row, $fails, $deletes) {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $saved = [];

			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $deleted = [];

			public function __construct(private ?array $row, private ?\Throwable $fails, private mixed $deletes) {
			}

			public function find(string $id, string $register, string $schema, bool $_rbac, bool $_multitenancy): ?array {
				return $this->row;
			}

			public function saveObject(array $object, string $register, string $schema, string $uuid, bool $_rbac, bool $_multitenancy): array {
				if ($this->fails !== null) {
					throw $this->fails;
				}

				$this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, 'rbac' => $_rbac, 'tenancy' => $_multitenancy];
				return $object;
			}

			public function deleteObject(string $uuid, string $register, string $schema, bool $_rbac, bool $_multitenancy): mixed {
				if ($this->fails !== null) {
					throw $this->fails;
				}

				$this->deleted[] = ['uuid' => $uuid, 'register' => $register, 'schema' => $schema];
				return $this->deletes;
			}
		};
	}//end objects()

	/**
	 * The changes are merged over the stored row; the row's own id and
	 * `@self` are not written back.
	 *
	 * @return void
	 */
	public function testAnUpdateMergesTheChangesOverTheStoredRow(): void {
		$objects = $this->objects(['@self' => ['id' => 'r-1'], 'name' => 'Jan', 'portal' => 'demo']);

		$this->assertTrue($this->store($objects)->update('portaliq', 'resident', 'r-1', ['portal' => 'wilgenboom', 'note' => 'x']));

		$this->assertSame(
			[['object' => ['name' => 'Jan', 'portal' => 'wilgenboom', 'note' => 'x'], 'register' => 'portaliq', 'schema' => 'resident', 'uuid' => 'r-1', 'rbac' => false, 'tenancy' => false]],
			$objects->saved
		);
	}//end testAnUpdateMergesTheChangesOverTheStoredRow()

	/**
	 * A row that is not there, or one stored under another id, is not written.
	 *
	 * @return void
	 */
	public function testAnUpdateOfAMissingOrForeignRowWritesNothing(): void {
		$missing = $this->objects(null);
		$foreign = $this->objects(['@self' => ['id' => 'r-2'], 'name' => 'Piet']);

		$this->assertFalse($this->store($missing)->update('portaliq', 'resident', 'r-1', ['name' => 'X']));
		$this->assertFalse($this->store($foreign)->update('portaliq', 'resident', 'r-1', ['name' => 'X']));
		$this->assertFalse($this->store(null)->update('portaliq', 'resident', 'r-1', ['name' => 'X']));
		$this->assertSame([], $missing->saved);
		$this->assertSame([], $foreign->saved);
	}//end testAnUpdateOfAMissingOrForeignRowWritesNothing()

	/**
	 * A failing write answers false and is logged with the schema.
	 *
	 * @return void
	 */
	public function testAFailedUpdateIsLoggedAndAnswersFalse(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Portaliq: example resident update failed',
			['schema' => 'resident', 'reason' => 'locked']
		);

		$objects = $this->objects(['id' => 'r-1', 'name' => 'Jan'], new RuntimeException('locked'));
		$this->assertFalse($this->store($objects, $logger)->update('portaliq', 'resident', 'r-1', ['name' => 'X']));
	}//end testAFailedUpdateIsLoggedAndAnswersFalse()

	/**
	 * A delete reaches the object service once and reports what it answered.
	 *
	 * @return void
	 */
	public function testADeleteReportsTheObjectServicesAnswer(): void {
		$objects = $this->objects(null);
		$this->assertTrue($this->store($objects)->delete('portaliq', 'resident', 'r-1'));
		$this->assertSame([['uuid' => 'r-1', 'register' => 'portaliq', 'schema' => 'resident']], $objects->deleted);

		$this->assertFalse($this->store($this->objects(null, null, false))->delete('portaliq', 'resident', 'r-1'));
		$this->assertTrue($this->store($this->objects(null, null, null))->delete('portaliq', 'resident', 'r-1'), 'only an explicit false is a refusal');
	}//end testADeleteReportsTheObjectServicesAnswer()

	/**
	 * No id, no OpenRegister or a failing delete: false, and a failure is logged.
	 *
	 * @return void
	 */
	public function testADeleteWithoutIdServiceOrSuccessAnswersFalse(): void {
		$objects = $this->objects(null);
		$this->assertFalse($this->store($objects)->delete('portaliq', 'resident', ''));
		$this->assertSame([], $objects->deleted);
		$this->assertFalse($this->store(null)->delete('portaliq', 'resident', 'r-1'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Portaliq: example resident delete failed',
			['schema' => 'resident', 'reason' => 'gone']
		);
		$this->assertFalse($this->store($this->objects(null, new RuntimeException('gone')), $logger)->delete('portaliq', 'resident', 'r-1'));
	}//end testADeleteWithoutIdServiceOrSuccessAnswersFalse()
}//end class
