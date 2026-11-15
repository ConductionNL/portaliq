<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\AuditTrailService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The audit trail parts that need no OpenRegister entity: the lookup, the
 * per-verb counts and the never-throwing record. The entity-writing paths are
 * pinned by AuditTrailServiceTest when OpenRegister is loadable.
 */
#[CoversClass(AuditTrailService::class)]
class AuditTrailServiceReadsTest extends TestCase {

	/**
	 * A mapper double that records the filters it was asked for.
	 */
	private function mapper(callable $answer): object {
		return new class ($answer) {
			public array $asked = [];

			public function __construct(private $answer) {
			}

			public function findAll(?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $sort = [], ?string $search = null): array {
				$this->asked[] = ['limit' => $limit, 'filters' => $filters];
				return ($this->answer)($filters);
			}
		};
	}

	private function service(mixed $mapper, ?LoggerInterface $logger = null): AuditTrailService {
		$container = $this->createMock(ContainerInterface::class);
		if ($mapper instanceof \Throwable) {
			$container->method('get')->willThrowException($mapper);
		} else {
			$container->method('get')->with('OCA\OpenRegister\Db\AuditTrailMapper')->willReturn($mapper);
		}
		return new AuditTrailService($container, $logger ?? $this->createMock(LoggerInterface::class));
	}

	public function testHasLooksUpOneRowByUuid(): void {
		$mapper = $this->mapper(static fn (array $filters): array => $filters['uuid'] === 'known' ? [new \stdClass()] : []);
		$service = $this->service($mapper);

		$this->assertTrue($service->has('known'));
		$this->assertFalse($service->has('unknown'));
		$this->assertSame(['limit' => 1, 'filters' => ['uuid' => 'known']], $mapper->asked[0]);
	}

	public function testCountsArePerPortalVerbInVerbOrder(): void {
		$mapper = $this->mapper(static fn (array $filters): array => match ($filters['action']) {
			'portaliq.login' => [1, 2, 3],
			'portaliq.download' => [1],
			default => [],
		});

		$counts = $this->service($mapper)->countsByVerb();

		$this->assertSame(AuditTrailService::VERBS, array_keys($counts));
		$this->assertSame(3, $counts['login']);
		$this->assertSame(1, $counts['download']);
		$this->assertSame(0, $counts['create']);
		$this->assertSame(
			array_map(static fn (string $verb): array => ['limit' => null, 'filters' => ['action' => 'portaliq.' . $verb]], AuditTrailService::VERBS),
			$mapper->asked
		);
	}

	public function testCountsAreZeroAndLoggedWhenTheTrailCannotBeRead(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')
			->with('Portaliq: audit count read failed', ['reason' => 'no openregister']);

		$counts = $this->service(new RuntimeException('no openregister'), $logger)->countsByVerb();

		$this->assertSame(array_fill_keys(AuditTrailService::VERBS, 0), $counts);
	}

	public function testARecordThatCannotBeWrittenIsLoggedAndNeverThrown(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Portaliq: audit record failed',
			$this->callback(static fn (array $ctx): bool => $ctx['verb'] === 'download' && is_string($ctx['reason']) && $ctx['reason'] !== '')
		);

		$this->service(new RuntimeException('no mapper'), $logger)->record(
			verb: 'download',
			subjectRef: 's-1',
			organisation: 'org-1',
			register: 'reg',
			schema: 'doc',
			id: 'd-1'
		);
	}
}
