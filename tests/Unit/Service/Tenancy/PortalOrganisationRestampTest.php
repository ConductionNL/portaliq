<?php

/**
 * The organisation restamp reports first and writes only when applied, and
 * only for rows whose organisation slug resolves.
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
 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Tenancy;

use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCA\Portaliq\Service\Tenancy\PortalOrganisationRestamp;
use OCA\Portaliq\Tests\Doubles\ActingObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Portaliq\Service\Tenancy\PortalOrganisationRestamp
 * @covers \OCA\Portaliq\Service\Tenancy\RestampTally
 * @uses   \OCA\Portaliq\Service\Tenancy\SchemaTenancy
 */
class PortalOrganisationRestampTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	private const MAPPER = 'OCA\\OpenRegister\\Db\\MagicMapper';

	private ActingObjectService $objectService;

	/**
	 * Entities the restamp found again, per uuid.
	 *
	 * @var array<string, object>
	 */
	private array $entities = [];

	/**
	 * Every entity the mapper wrote: [uuid, organisation].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $updates = [];

	/**
	 * Uuids whose write fails.
	 *
	 * @var array<int, string>
	 */
	private array $failing = [];

	protected function setUp(): void {
		$this->objectService = new ActingObjectService();
		$this->objectService->rows = [
			'portalAccount' => [
				$this->row(uuid: 'acc-1', slug: 'gemeente-a', stored: 'default-org'),
				$this->row(uuid: 'acc-2', slug: 'gemeente-a', stored: 'uuid-a'),
				$this->row(uuid: 'acc-3', slug: '', stored: 'default-org'),
				$this->row(uuid: 'acc-4', slug: 'onbekend', stored: 'default-org'),
			],
			'portalMessage' => [
				$this->row(uuid: 'msg-1', slug: 'gemeente-b', stored: 'default-org'),
				['@self' => ['id' => 'msg-2', 'organisation' => 'default-org'], 'organisation' => ['gemeente-a']],
				['@self' => ['id' => 'msg-3', 'organisation' => 'default-org']],
			],
			// A portal-scoped schema is not restamped, whatever its rows say.
			'page' => [
				$this->row(uuid: 'page-1', slug: 'gemeente-a', stored: 'default-org'),
			],
		];
		foreach (['acc-1', 'acc-2', 'acc-3', 'acc-4', 'msg-1', 'msg-2', 'msg-3', 'page-1'] as $uuid) {
			$this->entities[$uuid] = $this->entity(uuid: $uuid);
		}

		$this->objectService->found = $this->entities;
	}//end setUp()

	public function testTheDryRunCountsPerOrganisationAndWritesNothing(): void {
		$report = $this->restamp()->report();

		$this->assertFalse($report['apply']);
		$this->assertSame(
			[
				'gemeente-a' => ['uuid' => 'uuid-a', 'already' => 1, 'toRestamp' => 1, 'restamped' => 0, 'failed' => 0],
				'gemeente-b' => ['uuid' => 'uuid-b', 'already' => 0, 'toRestamp' => 1, 'restamped' => 0, 'failed' => 0],
			],
			$report['organisations']
		);
		$this->assertSame([], $this->updates);
		$this->assertSame([], $this->objectService->actedFor(method: 'find'));
	}//end testTheDryRunCountsPerOrganisationAndWritesNothing()

	public function testTheDryRunListsEveryRowItCannotResolve(): void {
		$report = $this->restamp()->report();

		$this->assertSame(
			[
				['schema' => 'portalAccount', 'uuid' => 'acc-3', 'organisation' => ''],
				['schema' => 'portalAccount', 'uuid' => 'acc-4', 'organisation' => 'onbekend'],
				['schema' => 'portalMessage', 'uuid' => 'msg-2', 'organisation' => '(array)'],
				['schema' => 'portalMessage', 'uuid' => 'msg-3', 'organisation' => '(none)'],
			],
			$report['unresolved']
		);
	}//end testTheDryRunListsEveryRowItCannotResolve()

	public function testTheApplyRestampsOnlyTheRowsThatResolveAndDiffer(): void {
		$report = $this->restamp()->apply();

		$this->assertTrue($report['apply']);
		$this->assertSame([['acc-1', 'uuid-a'], ['msg-1', 'uuid-b']], $this->updates);
		$this->assertSame(1, $report['organisations']['gemeente-a']['restamped']);
		$this->assertSame(1, $report['organisations']['gemeente-a']['already']);
		$this->assertSame(1, $report['organisations']['gemeente-b']['restamped']);
		$this->assertCount(4, $report['unresolved']);
	}//end testTheApplyRestampsOnlyTheRowsThatResolveAndDiffer()

	public function testAFailedWriteIsCountedAndTheRunGoesOn(): void {
		$this->failing = ['acc-1'];

		$report = $this->restamp()->apply();

		$this->assertSame(1, $report['organisations']['gemeente-a']['failed']);
		$this->assertSame(0, $report['organisations']['gemeente-a']['restamped']);
		$this->assertSame([['msg-1', 'uuid-b']], $this->updates);
	}//end testAFailedWriteIsCountedAndTheRunGoesOn()

	public function testARowThatIsGoneWhenWrittenCountsAsFailed(): void {
		unset($this->objectService->found['acc-1']);

		$report = $this->restamp()->apply();

		$this->assertSame(1, $report['organisations']['gemeente-a']['failed']);
	}//end testARowThatIsGoneWhenWrittenCountsAsFailed()

	public function testASchemaThatCannotBeReadIsReportedAndTheOthersAreDone(): void {
		$objectService = new class extends ActingObjectService {
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->schema === 'portalAccount') {
					throw new RuntimeException('no such schema');
				}

				return parent::findAll(config: $config, _rbac: $_rbac, _multitenancy: $_multitenancy);
			}
		};
		$objectService->rows = $this->objectService->rows;
		$this->objectService = $objectService;

		$report = $this->restamp()->report();

		$this->assertSame(['portalAccount' => 'no such schema'], $report['unreadable']);
		$this->assertSame(1, $report['organisations']['gemeente-b']['toRestamp']);
	}//end testASchemaThatCannotBeReadIsReportedAndTheOthersAreDone()

	public function testItListsAsTheSystemPageByPage(): void {
		$objectService = new class extends ActingObjectService {
			/**
			 * @var array<int, array{config: array<string, mixed>, rbac: bool, multitenancy: bool}>
			 */
			public array $queries = [];

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->queries[] = ['config' => $config, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				if ($this->schema !== 'portalAccount') {
					return [];
				}

				$rows = [];
				$start = (int)$config['offset'];
				$end = min(($start + (int)$config['limit']), 501);
				for ($i = $start; $i < $end; $i++) {
					$rows[] = ['@self' => ['id' => 'acc-'.$i, 'organisation' => 'uuid-a'], 'organisation' => 'gemeente-a'];
				}

				return $rows;
			}
		};
		$this->objectService = $objectService;

		$report = $this->restamp()->report();

		$this->assertSame(501, $report['organisations']['gemeente-a']['already']);
		$accountQueries = array_slice($objectService->queries, 0, 2);
		$this->assertSame(
			[
				['config' => ['limit' => 500, 'offset' => 0], 'rbac' => false, 'multitenancy' => false],
				['config' => ['limit' => 500, 'offset' => 500], 'rbac' => false, 'multitenancy' => false],
			],
			$accountQueries
		);
	}//end testItListsAsTheSystemPageByPage()

	public function testWithoutOpenRegisterNothingIsReadOrWritten(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no openregister'));
		$restamp = new PortalOrganisationRestamp(container: $container, organisations: $this->organisations(), logger: new NullLogger());

		$this->expectException(RuntimeException::class);
		$restamp->apply();
	}//end testWithoutOpenRegisterNothingIsReadOrWritten()

	public function testWithoutTheMapperNothingIsReadOrWritten(): void {
		$objectService = $this->objectService;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService): object {
				if ($id === self::OS) {
					return $objectService;
				}

				throw new RuntimeException('no service: '.$id);
			}
		);
		$restamp = new PortalOrganisationRestamp(container: $container, organisations: $this->organisations(), logger: new NullLogger());

		try {
			$restamp->report();
			$this->fail('the run must refuse');
		} catch (RuntimeException $e) {
			$this->assertSame([], $objectService->calls);
		}
	}//end testWithoutTheMapperNothingIsReadOrWritten()

	/**
	 * The restamp over the object service, a recording mapper and the lookup.
	 *
	 * @return PortalOrganisationRestamp
	 */
	private function restamp(): PortalOrganisationRestamp {
		$test = $this;
		$mapper = new class ($test) {
			/**
			 * @param PortalOrganisationRestampTest $test The test.
			 */
			public function __construct(private readonly PortalOrganisationRestampTest $test) {
			}

			/**
			 * @param object $entity The entity.
			 */
			public function update(object $entity): object {
				$this->test->recordUpdate(entity: $entity);
				return $entity;
			}
		};

		$objectService = $this->objectService;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService, $mapper): object {
				return match ($id) {
					self::OS => $objectService,
					self::MAPPER => $mapper,
				};
			}
		);

		return new PortalOrganisationRestamp(container: $container, organisations: $this->organisations(), logger: new NullLogger());
	}//end restamp()

	/**
	 * Note a write by the mapper, or fail it.
	 *
	 * @param object $entity The entity.
	 *
	 * @return void
	 */
	public function recordUpdate(object $entity): void {
		if (in_array($entity->uuid, $this->failing, true) === true) {
			throw new RuntimeException('write refused');
		}

		$this->updates[] = [$entity->uuid, $entity->organisation];
	}//end recordUpdate()

	/**
	 * The lookup: gemeente-a is uuid-a and gemeente-b is uuid-b.
	 *
	 * @return PortalOrganisationConfigService
	 */
	private function organisations(): PortalOrganisationConfigService {
		$organisations = $this->createMock(PortalOrganisationConfigService::class);
		$organisations->method('findOrganisationBySlug')->willReturnCallback(
			static function (string $slug): ?array {
				$known = ['gemeente-a' => 'uuid-a', 'gemeente-b' => 'uuid-b'];
				return isset($known[$slug]) === true ? ['uuid' => $known[$slug], 'name' => $slug] : null;
			}
		);

		return $organisations;
	}//end organisations()

	/**
	 * A listed row as OpenRegister renders it.
	 *
	 * @param string $uuid   The row uuid.
	 * @param string $slug   The data organisation slug.
	 * @param string $stored The stored organisation uuid.
	 *
	 * @return array<string, mixed>
	 */
	private function row(string $uuid, string $slug, string $stored): array {
		return ['@self' => ['id' => $uuid, 'organisation' => $stored], 'organisation' => $slug, 'subjectRef' => 's-'.$uuid];
	}//end row()

	/**
	 * An entity that holds its uuid and organisation.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return object
	 */
	private function entity(string $uuid): object {
		return new class ($uuid) {
			public string $organisation = 'default-org';

			public function __construct(public readonly string $uuid) {
			}

			public function setOrganisation(string $organisation): void {
				$this->organisation = $organisation;
			}
		};
	}//end entity()
}//end class
