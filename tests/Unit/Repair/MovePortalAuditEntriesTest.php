<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Repair;

use OCA\Portaliq\Repair\MovePortalAuditEntries;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\PortalRegisterContext;
use OCA\Portaliq\Tests\Unit\Service\FakeAuditTrailMapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * consume-or-audit-trail-proof-records T02: the old `portalAuditEntry`
 * objects move into OpenRegister's audit trail with their uuid and time, the
 * metrics count the same afterwards, a second run writes nothing twice, and a
 * record that cannot be removed is kept, never lost.
 *
 * @spec openspec/changes/consume-or-audit-trail-proof-records/tasks.md#T02
 */
class MovePortalAuditEntriesTest extends TestCase {
	private FakeAuditTrailMapper $mapper;

	private FakeOldRecordStore $store;

	protected function setUp(): void {
		if (class_exists('OCA\\OpenRegister\\Db\\AuditTrail') === false) {
			$this->markTestSkipped('Set PORTALIQ_OPENREGISTER_LIB to an openregister lib/ to load the real AuditTrail entity.');
		}

		$this->mapper = new FakeAuditTrailMapper();
		$this->store = new FakeOldRecordStore();
	}

	private function container(): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				'OCA\\OpenRegister\\Db\\AuditTrailMapper' => $this->mapper,
				'OCA\\OpenRegister\\Service\\ObjectService' => $this->store,
			}
		);

		return $container;
	}

	private function step(bool $schemaExists = true): MovePortalAuditEntries {
		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturn($schemaExists);
		$trail = new AuditTrailService($this->container(), $this->createMock(LoggerInterface::class));

		return new MovePortalAuditEntries($this->container(), $context, $trail, $this->createMock(LoggerInterface::class));
	}

	private function old(string $uuid, string $verb, string $timestamp, string $jti = ''): array {
		return [
			'@self' => ['uuid' => $uuid, 'created' => $timestamp],
			'jti' => $jti,
			'subjectRef' => 'party:' . $uuid,
			'organisation' => 'org-a',
			'appId' => 'portaliq',
			'verb' => $verb,
			'register' => 'portaliq',
			'schema' => 'session',
			'targetId' => '',
			'timestamp' => $timestamp,
		];
	}

	/**
	 * What the portal's metrics counted before the move: the old objects per verb.
	 *
	 * @return array<string, int>
	 */
	private function oldCounts(): array {
		$counts = array_fill_keys(AuditTrailService::VERBS, 0);
		foreach ($this->store->rows as $row) {
			$counts[$row['verb']]++;
		}

		return $counts;
	}

	public function testTheMetricsCountTheSameAfterTheMove(): void {
		$this->store->rows = [
			$this->old('a1', 'login', '2026-05-01T09:00:00+00:00', 'jti-a'),
			$this->old('a2', 'login', '2026-05-01T10:00:00+00:00'),
			$this->old('a3', 'download', '2026-05-02T11:00:00+00:00'),
			$this->old('a4', 'complete', '2026-05-03T12:00:00+00:00'),
		];
		$before = $this->oldCounts();

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertSame($before, (new AuditTrailService($this->container(), $this->createMock(LoggerInterface::class)))->countsByVerb());
		$this->assertSame([], $this->store->rows, 'every old object is gone');
		$first = $this->mapper->findAll(filters: ['uuid' => 'a1'])[0];
		$this->assertSame('2026-05-01 09:00:00', $first->getCreated()->format('Y-m-d H:i:s'), 'the record keeps its time');
		$this->assertSame('jti-a', $first->getSession());
		$this->assertSame('party:a1', $first->getUser());
	}

	public function testASecondRunWritesNothingTwice(): void {
		$this->store->rows = [$this->old('b1', 'logout', '2026-06-01T08:00:00+00:00')];
		$this->store->refuseDelete = true;
		$this->step()->run($this->createMock(IOutput::class));
		$this->assertCount(1, $this->mapper->rows);
		$this->assertCount(1, $this->store->rows, 'a record that could not be removed is kept');

		$this->store->refuseDelete = false;
		$this->step()->run($this->createMock(IOutput::class));

		$this->assertCount(1, $this->mapper->rows, 'the row is not written twice');
		$this->assertSame([], $this->store->rows);
	}

	public function testAnInstallWithoutTheOldSchemaDoesNothing(): void {
		$this->store->rows = [$this->old('c1', 'login', '2026-06-01T08:00:00+00:00')];

		$this->step(schemaExists: false)->run($this->createMock(IOutput::class));

		$this->assertSame([], $this->mapper->rows);
	}
}

/**
 * A stand-in for OpenRegister's ObjectService over the old portalAuditEntry rows.
 */
class FakeOldRecordStore {
	/**
	 * @var list<array<string, mixed>>
	 */
	public array $rows = [];

	public bool $refuseDelete = false;

	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		return array_slice($this->rows, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 100));
	}

	public function deleteObject(string $uuid, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): bool {
		if ($this->refuseDelete === true) {
			throw new RuntimeException('delete refused');
		}

		$this->rows = array_values(array_filter($this->rows, static fn (array $row): bool => $row['@self']['uuid'] !== $uuid));

		return true;
	}
}
