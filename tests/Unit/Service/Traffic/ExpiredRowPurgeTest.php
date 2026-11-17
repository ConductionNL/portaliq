<?php

/**
 * ExpiredRowPurge: expired traffic rows are purged in one raw call when
 * OpenRegister offers it, row by row otherwise, and a failure is logged.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service\Traffic
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Traffic;

use OCA\Portaliq\Service\Traffic\ExpiredRowPurge;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The expired row purge.
 */
class ExpiredRowPurgeTest extends TestCase {
	/**
	 * A class that uses the trait with a given object service and logger.
	 *
	 * @param object|null $objectService The object service, or null when OpenRegister is absent.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return object
	 */
	private function host(?object $objectService, LoggerInterface $logger): object {
		return new class($objectService, $logger) {
			use ExpiredRowPurge;

			private const REGISTER = 'portaliq';

			/**
			 * How many rows the fallback deleted.
			 *
			 * @var int
			 */
			public int $fallbackCalls = 0;

			/**
			 * Hold the collaborators.
			 *
			 * @param object|null $service The object service.
			 * @param LoggerInterface $logger The logger.
			 */
			public function __construct(private ?object $service, public LoggerInterface $logger) {
			}//end __construct()

			/**
			 * The object service.
			 *
			 * @return object|null
			 */
			private function objectService(): ?object {
				return $this->service;
			}//end objectService()

			/**
			 * The row-by-row fallback.
			 *
			 * @param object $objectService The object service.
			 *
			 * @return int
			 */
			protected function deleteExpired(object $objectService): int {
				$this->fallbackCalls++;
				return 3;
			}//end deleteExpired()

			/**
			 * Purge one schema.
			 *
			 * @param string $schema The schema.
			 *
			 * @return int
			 */
			public function purge(string $schema): int {
				return $this->purgeExpiredOf(schema: $schema, failure: 'purge failed');
			}//end purge()
		};
	}//end host()

	/**
	 * The raw purge is used when the object service has it, for the app's register.
	 *
	 * @return void
	 */
	public function testTheRawPurgeIsUsedWhenOffered(): void {
		$service = new class {
			/**
			 * The calls made.
			 *
			 * @var array<int, array{0: string, 1: string}>
			 */
			public array $calls = [];

			/**
			 * Purge the expired rows of a schema.
			 *
			 * @param string $register The register.
			 * @param string $schema The schema.
			 *
			 * @return string
			 */
			public function purgeExpiredObjectsRaw(string $register, string $schema): string {
				$this->calls[] = [$register, $schema];
				return '12';
			}//end purgeExpiredObjectsRaw()
		};
		$host    = $this->host(objectService: $service, logger: $this->createMock(originalClassName: LoggerInterface::class));
		$this->assertSame(expected: 12, actual: $host->purge(schema: 'portalTrafficEvent'));
		$this->assertSame(expected: [['portaliq', 'portalTrafficEvent']], actual: $service->calls);
		$this->assertSame(expected: 0, actual: $host->fallbackCalls);
	}//end testTheRawPurgeIsUsedWhenOffered()

	/**
	 * Without the raw purge the rows are deleted one by one.
	 *
	 * @return void
	 */
	public function testTheFallbackDeletesRowByRow(): void {
		$host = $this->host(objectService: new class {
		}, logger: $this->createMock(originalClassName: LoggerInterface::class));
		$this->assertSame(expected: 3, actual: $host->purge(schema: 'portalTrafficEvent'));
		$this->assertSame(expected: 1, actual: $host->fallbackCalls);
	}//end testTheFallbackDeletesRowByRow()

	/**
	 * Without OpenRegister nothing is purged and nothing is logged.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsPurged(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->never())->method('error');
		$host = $this->host(objectService: null, logger: $logger);
		$this->assertSame(expected: 0, actual: $host->purge(schema: 'portalTrafficEvent'));
		$this->assertSame(expected: 0, actual: $host->fallbackCalls);
	}//end testWithoutOpenRegisterNothingIsPurged()

	/**
	 * A failing purge is logged with its reason and counts as nothing purged.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedAndCountsAsNone(): void {
		$service = new class {
			/**
			 * Fail.
			 *
			 * @param string $register The register.
			 * @param string $schema The schema.
			 *
			 * @return int
			 */
			public function purgeExpiredObjectsRaw(string $register, string $schema): int {
				throw new RuntimeException('database is gone');
			}//end purgeExpiredObjectsRaw()
		};
		$logger  = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('purge failed', ['reason' => 'database is gone']);
		$this->assertSame(expected: 0, actual: $this->host(objectService: $service, logger: $logger)->purge(schema: 'portalTrafficEvent'));
	}//end testAFailureIsLoggedAndCountsAsNone()
}//end class
