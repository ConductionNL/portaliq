<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\AuditTrailService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * consume-or-audit-trail-proof-records: a proof record is one row in
 * OpenRegister's audit trail (the real AuditTrail entity), carries no payload,
 * never fails the audited action, and the metrics count those rows per verb.
 *
 * @spec openspec/changes/archive/2026-09-30-consume-or-audit-trail-proof-records/tasks.md#T01
 * @spec openspec/changes/archive/2026-09-30-consume-or-audit-trail-proof-records/tasks.md#T03
 */
class AuditTrailServiceTest extends TestCase {
	/**
	 * The fake audit-trail mapper the service writes into.
	 *
	 * @var FakeAuditTrailMapper
	 */
	private FakeAuditTrailMapper $mapper;

	protected function setUp(): void {
		if (class_exists('OCA\\OpenRegister\\Db\\AuditTrail') === false) {
			$this->markTestSkipped('Set PORTALIQ_OPENREGISTER_LIB to an openregister lib/ to load the real AuditTrail entity.');
		}

		$this->mapper = new FakeAuditTrailMapper();
	}

	private function service(?LoggerInterface $logger = null, bool $withOpenRegister = true): AuditTrailService {
		$container = $this->createMock(ContainerInterface::class);
		if ($withOpenRegister === true) {
			$container->method('get')->with('OCA\\OpenRegister\\Db\\AuditTrailMapper')->willReturn($this->mapper);
		} else {
			$container->method('get')->willThrowException(new RuntimeException('openregister is not installed'));
		}

		return new AuditTrailService($container, $logger ?? $this->createMock(LoggerInterface::class));
	}

	public function testARecordIsOneOpenRegisterAuditRowWithNoPayload(): void {
		$target = '5b0f7c2e-1d7e-4a8f-9c3b-2f6a1e0d4c11';
		$this->service()->record('create', 'party:123', 'org-a', 'dossiq', 'cases', $target, 'jti-1', 'dossiq');

		$this->assertCount(1, $this->mapper->rows);
		$row = $this->mapper->rows[0];
		$this->assertInstanceOf('OCA\\OpenRegister\\Db\\AuditTrail', $row);
		$this->assertSame('portaliq.create', $row->getAction());
		$this->assertSame('party:123', $row->getUser());
		$this->assertSame('jti-1', $row->getSession());
		$this->assertSame('org-a', $row->getOrganisationId());
		$this->assertSame($target, $row->getObjectUuid());
		$this->assertNotEmpty($row->getUuid());
		$this->assertNotNull($row->getCreated());
		// Exactly the target, never the object's payload.
		$this->assertSame(
			['appId' => 'dossiq', 'register' => 'dossiq', 'schema' => 'cases', 'targetId' => $target],
			$row->getChanged()
		);
	}

	public function testTheDownloadHookCallShapeRecordsWithDefaults(): void {
		$this->service()->record('login', 'party:9', 'org-b', 'portaliq', 'session', '');

		$row = $this->mapper->rows[0];
		$this->assertSame('portaliq.login', $row->getAction());
		$this->assertNull($row->getSession());
		$this->assertNull($row->getObjectUuid());
		$this->assertSame('portaliq', $row->getChanged()['appId']);
	}

	public function testAFailureIsLoggedAndNeverThrown(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$this->service(logger: $logger, withOpenRegister: false)->record('create', 'party:1', 'org', 'r', 's', 'x');
		$this->assertSame([], $this->mapper->rows);
	}

	public function testTheMetricsCountThePortalRowsPerVerb(): void {
		$service = $this->service();
		$service->record('login', 'party:1', 'org', 'portaliq', 'session', '');
		$service->record('login', 'party:2', 'org', 'portaliq', 'session', '');
		$service->record('download', 'party:1', 'org', 'dossiq', 'cases', 'x');
		// A row OpenRegister wrote itself for an object create is not a portal proof record.
		$this->mapper->rows[] = $this->mapper->foreignRow('create');

		$counts = $service->countsByVerb();

		$this->assertSame(['create' => 0, 'update' => 0, 'forward' => 0, 'download' => 1, 'login' => 2, 'logout' => 0, 'refresh' => 0, 'complete' => 0], $counts);
	}

	public function testTheCountsAreZeroWithoutOpenRegister(): void {
		$counts = $this->service(withOpenRegister: false)->countsByVerb();

		$this->assertSame(array_fill_keys(AuditTrailService::VERBS, 0), $counts);
	}

	public function testTheServiceCanWriteNowhereButTheAuditTrail(): void {
		$parameters = (new ReflectionClass(AuditTrailService::class))->getConstructor()->getParameters();
		$types = array_map(static fn ($parameter): string => (string)$parameter->getType(), $parameters);

		$this->assertSame([ContainerInterface::class, LoggerInterface::class], $types);
	}
}
