<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Repair;

use OCA\Portaliq\Repair\MovePortalAuditEntries;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\PortalRegisterContext;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * consume-or-audit-trail-proof-records T02, without OpenRegister loaded: the
 * step pages through the old records, deletes each one whose row is already in
 * the trail, keeps every record it cannot move (no uuid, a refused delete, a
 * failed append) and skips them on the next page, and does nothing when
 * OpenRegister or the old schema is absent.
 *
 * @covers \OCA\Portaliq\Repair\MovePortalAuditEntries
 * @uses   \OCA\Portaliq\Service\AuditTrailService
 *
 * @spec openspec/changes/archive/2026-09-30-consume-or-audit-trail-proof-records/tasks.md#T02
 */
class MovePortalAuditEntriesPagingTest extends TestCase {

	/**
	 * The old records, in store order.
	 *
	 * @var array<int, mixed>
	 */
	public array $rows = [];

	/**
	 * Every findAll config the step read with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $reads = [];

	/**
	 * Every delete, as its named arguments.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $deletes = [];

	/**
	 * Uuids already in the trail.
	 *
	 * @var array<int, string>
	 */
	public array $inTrail = [];

	/**
	 * Uuids whose delete is refused.
	 *
	 * @var array<int, string>
	 */
	public array $undeletable = [];

	/**
	 * Every trail insert attempted.
	 *
	 * @var int
	 */
	public int $inserts = 0;

	/**
	 * Warnings the step logged, as [message, context].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * The step over the fakes.
	 *
	 * @param bool $schemaExists    Whether the old schema exists.
	 * @param bool $openRegister    Whether OpenRegister's object service resolves.
	 *
	 * @return MovePortalAuditEntries
	 */
	private function step(bool $schemaExists = true, bool $openRegister = true): MovePortalAuditEntries {
		$store = new class($this) {
			/**
			 * @param MovePortalAuditEntriesPagingTest $test The test holding the rows.
			 */
			public function __construct(private MovePortalAuditEntriesPagingTest $test) {
			}

			/**
			 * @param array<string, mixed> $config Limit and offset.
			 * @param mixed ...$flags The RBAC and multitenancy flags.
			 * @return array<int, mixed>
			 */
			public function findAll(array $config, mixed ...$flags): array {
				$this->test->reads[] = $config + $flags;

				return array_slice($this->test->rows, (int)$config['offset'], (int)$config['limit']);
			}

			/**
			 * @param mixed ...$args The uuid, register, schema and flags.
			 * @return bool
			 */
			public function deleteObject(mixed ...$args): bool {
				if (in_array($args['uuid'], $this->test->undeletable, true) === true) {
					throw new RuntimeException('delete refused');
				}

				$this->test->deletes[] = $args;
				foreach ($this->test->rows as $i => $row) {
					$row = is_object($row) === true ? $row->jsonSerialize() : $row;
					if (($row['@self']['uuid'] ?? $row['uuid'] ?? $row['id'] ?? null) === $args['uuid']) {
						unset($this->test->rows[$i]);
					}
				}

				$this->test->rows = array_values($this->test->rows);
				return true;
			}
		};
		$mapper = new class($this) {
			/**
			 * @param MovePortalAuditEntriesPagingTest $test The test holding the trail.
			 */
			public function __construct(private MovePortalAuditEntriesPagingTest $test) {
			}

			/**
			 * @param mixed ...$args Limit and filters.
			 * @return array<int, string>
			 */
			public function findAll(mixed ...$args): array {
				return array_values(array_intersect($this->test->inTrail, [$args['filters']['uuid']]));
			}

			/**
			 * @param array<int, object> $rows The rows.
			 * @return void
			 */
			public function insertAuditTrails(array $rows): void {
				$this->test->inserts++;
				throw new RuntimeException('trail is read-only here');
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($store, $mapper, $openRegister): object {
				if ($openRegister === false) {
					throw new RuntimeException('OpenRegister is not installed');
				}

				return match ($id) {
					'OCA\\OpenRegister\\Db\\AuditTrailMapper' => $mapper,
					'OCA\\OpenRegister\\Service\\ObjectService' => $store,
				};
			}
		);

		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturnCallback(
			static function (object $objectService, string $schemaSlug) use ($store, $schemaExists): bool {
				self::assertSame($store, $objectService);
				self::assertSame('portalAuditEntry', $schemaSlug);
				return $schemaExists;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new MovePortalAuditEntries($container, $context, new AuditTrailService($container, $logger), $logger);
	}//end step()

	/**
	 * An output that expects exactly this summary line.
	 *
	 * @param string $summary The line.
	 *
	 * @return IOutput
	 */
	private function summary(string $summary): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with($summary);

		return $output;
	}//end summary()

	/**
	 * Records already in the trail are deleted, unscoped, a full page at a time, until a short page.
	 *
	 * @return void
	 */
	public function testRecordsInTheTrailAreDeletedPageByPage(): void {
		for ($i = 1; $i <= 150; $i++) {
			$this->rows[] = ['@self' => ['uuid' => 'u-' . $i], 'verb' => 'login'];
			$this->inTrail[] = 'u-' . $i;
		}

		$this->step()->run($this->summary('MovePortalAuditEntries: moved 150, could not move 0.'));

		self::assertSame([], $this->rows);
		self::assertSame(0, $this->inserts, 'a record already in the trail is not written twice');
		self::assertCount(2, $this->reads);
		self::assertSame(['limit' => 100, 'offset' => 0, '_rbac' => false, '_multitenancy' => false], $this->reads[0]);
		self::assertSame(['limit' => 100, 'offset' => 0, '_rbac' => false, '_multitenancy' => false], $this->reads[1]);
		self::assertSame(
			['uuid' => 'u-1', 'register' => 'portaliq', 'schema' => 'portalAuditEntry', '_rbac' => false, '_multitenancy' => false],
			$this->deletes[0]
		);
	}//end testRecordsInTheTrailAreDeletedPageByPage()

	/**
	 * The uuid is read from @self, then uuid, then id; an entity is serialised first.
	 *
	 * @return void
	 */
	public function testTheUuidIsFoundWhereverTheRowCarriesIt(): void {
		$entity = new class {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['uuid' => 'u-entity'];
			}
		};
		$this->rows = [['uuid' => 'u-plain'], ['id' => 'u-id'], $entity, 'not a row'];
		$this->inTrail = ['u-plain', 'u-id', 'u-entity'];

		$this->step()->run($this->summary('MovePortalAuditEntries: moved 3, could not move 0.'));

		self::assertSame(['u-plain', 'u-id', 'u-entity'], array_column($this->deletes, 'uuid'));
	}//end testTheUuidIsFoundWhereverTheRowCarriesIt()

	/**
	 * A record without a uuid, one whose append fails and one whose delete is
	 * refused are kept, and the next page skips past them.
	 *
	 * @return void
	 */
	public function testARecordThatCannotMoveIsKeptAndSkipped(): void {
		$this->rows = [['verb' => 'login'], ['uuid' => 'u-new', 'verb' => 'logout', 'timestamp' => '2026-05-01T10:00:00+00:00'], ['uuid' => 'u-stuck']];
		for ($i = 1; $i <= 97; $i++) {
			$this->rows[] = ['uuid' => 'u-' . $i];
			$this->inTrail[] = 'u-' . $i;
		}

		$this->rows[] = ['uuid' => 'u-last'];
		$this->inTrail = array_merge($this->inTrail, ['u-stuck', 'u-last']);
		$this->undeletable = ['u-stuck'];

		$this->step()->run($this->summary('MovePortalAuditEntries: moved 98, could not move 3.'));

		self::assertSame([0, 3], array_column($this->reads, 'offset'), 'the second page skips the three kept records');
		self::assertCount(3, $this->rows);
		self::assertSame(['u-new', 'u-stuck'], array_column(array_column($this->warnings, 1), 'uuid'));
		self::assertSame('Portaliq: a proof record could not be moved into the audit trail', $this->warnings[0][0]);
		self::assertNotContains('u-new', array_column($this->deletes, 'uuid'), 'a record is never deleted before its row is in the trail');
	}//end testARecordThatCannotMoveIsKeptAndSkipped()

	/**
	 * Without the old schema, or without OpenRegister, nothing is read or moved.
	 *
	 * @return void
	 */
	public function testWithoutTheSchemaOrOpenRegisterNothingHappens(): void {
		$this->rows = [['uuid' => 'u-1']];
		$this->inTrail = ['u-1'];

		$this->step(schemaExists: false)->run($this->summary('MovePortalAuditEntries: moved 0, could not move 0.'));
		$this->step(openRegister: false)->run($this->summary('MovePortalAuditEntries: moved 0, could not move 0.'));

		self::assertSame([], $this->reads);
		self::assertSame([], $this->deletes);
		self::assertSame('Move the portal proof records into the OpenRegister audit trail', $this->step()->getName());
	}//end testWithoutTheSchemaOrOpenRegisterNothingHappens()
}//end class
