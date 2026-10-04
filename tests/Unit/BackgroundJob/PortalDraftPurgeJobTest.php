<?php

/**
 * PortalDraftPurgeJob (site-multi-step-forms REQ-SMF-021): the cron contract
 * around the purge. A draft past its day count goes; one inside it stays.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\BackgroundJob
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

namespace OCA\Portaliq\Tests\Unit\BackgroundJob;

use OCA\Portaliq\BackgroundJob\PortalDraftPurgeJob;
use OCA\Portaliq\Service\PortalDraftStore;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The daily draft purge.
 */
class PortalDraftPurgeJobTest extends TestCase {
	private const NOW = '2026-10-05T12:00:00+00:00';

	/**
	 * An expired draft is deleted and one inside its retention is left,
	 * through the real store.
	 *
	 * @return void
	 */
	public function testAnExpiredDraftIsDeletedAndAFreshOneStays(): void {
		$fake = new class {
			public array $deleted = [];

			/**
			 * Answers with one expired and one fresh draft.
			 *
			 * @param array<string, mixed> $config The query.
			 * @param bool $_rbac Unused.
			 * @param bool $_multitenancy Unused.
			 *
			 * @return array<int, mixed> The drafts.
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return [
					['@self' => ['uuid' => 'old-1'], 'expiresAt' => '2026-09-04T12:00:00+00:00'],
					['@self' => ['uuid' => 'fresh-1'], 'expiresAt' => '2026-11-04T12:00:00+00:00'],
				];
			}

			/**
			 * Records a delete.
			 *
			 * @param string $uuid The row.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param bool $_rbac Unused.
			 * @param bool $_multitenancy Unused.
			 *
			 * @return bool True.
			 */
			public function deleteObject(string $uuid, string $register = '', string $schema = '', bool $_rbac = true, bool $_multitenancy = true): bool {
				$this->deleted[] = $uuid;
				return true;
			}
		};

		$this->runJob($this->job(store: $this->store(objectService: $fake)));

		$this->assertSame(['old-1'], $fake->deleted);
	}//end testAnExpiredDraftIsDeletedAndAFreshOneStays()

	/**
	 * The job measures expiry against its own clock, so a draft that expires
	 * later today is not deleted by a run this morning.
	 *
	 * @return void
	 */
	public function testThePurgeMeasuresAgainstTheJobsClock(): void {
		$store = $this->createMock(PortalDraftStore::class);
		$store->expects($this->once())->method('purgeExpired')->with(self::NOW)->willReturn(3);

		$this->runJob($this->job(store: $store));
	}//end testThePurgeMeasuresAgainstTheJobsClock()

	/**
	 * A store that throws does not take the cron run down with it.
	 *
	 * @return void
	 */
	public function testAFailingPurgeIsLoggedAndSwallowed(): void {
		$store = $this->createMock(PortalDraftStore::class);
		$store->method('purgeExpired')->willThrowException(new RuntimeException('no store'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$this->runJob($this->job(store: $store, logger: $logger));
	}//end testAFailingPurgeIsLoggedAndSwallowed()

	/**
	 * The job runs once a day and never in a time-sensitive slot.
	 *
	 * @return void
	 */
	public function testTheJobIsDailyAndTimeInsensitive(): void {
		$job = $this->job(store: $this->createMock(PortalDraftStore::class));

		$this->assertSame(86400, PortalDraftPurgeJob::INTERVAL);
		$sensitivity = new \ReflectionProperty($job, 'timeSensitivity');
		$this->assertSame(IJob::TIME_INSENSITIVE, $sensitivity->getValue($job));
		$this->assertFalse($job->isTimeSensitive());
	}//end testTheJobIsDailyAndTimeInsensitive()

	/**
	 * Invoke the protected run().
	 *
	 * @param PortalDraftPurgeJob $job The job.
	 *
	 * @return void
	 */
	private function runJob(PortalDraftPurgeJob $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * The real store with a fake OpenRegister behind it.
	 *
	 * @param object $objectService The fake.
	 *
	 * @return PortalDraftStore The store.
	 */
	private function store(object $objectService): PortalDraftStore {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return new PortalDraftStore(
			$this->createMock(PortalObjectReader::class),
			$this->createMock(PortalObjectWriter::class),
			$container,
			$this->createMock(LoggerInterface::class)
		);
	}//end store()

	/**
	 * The job under test, on a pinned clock.
	 *
	 * @param PortalDraftStore $store The store.
	 * @param LoggerInterface|null $logger The logger double.
	 *
	 * @return PortalDraftPurgeJob The job.
	 */
	private function job(PortalDraftStore $store, ?LoggerInterface $logger = null): PortalDraftPurgeJob {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(strtotime(self::NOW));

		return new PortalDraftPurgeJob($time, $store, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end job()

}//end class
