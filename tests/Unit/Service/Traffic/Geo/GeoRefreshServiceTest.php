<?php

/**
 * Unit tests for GeoRefreshService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Traffic\Geo;

use OCA\Portaliq\BackgroundJob\TrafficGeoFirstDownloadJob;
use OCA\Portaliq\Service\Traffic\Geo\DbIpLiteProvider;
use OCA\Portaliq\Service\Traffic\Geo\GeoDatabaseStore;
use OCA\Portaliq\Service\Traffic\Geo\GeoRefreshService;
use OCA\Portaliq\Service\Traffic\Geo\GeoSettings;
use OCA\Portaliq\Service\Traffic\Geo\MaxMindProvider;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\ITempManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Download, verify, install: the file in use is replaced only by a file
 * that opened.
 */
class GeoRefreshServiceTest extends TestCase {

	/**
	 * The fixture database.
	 */
	private const FIXTURE = __DIR__ . '/../../../../fixtures/GeoIP2-City-Test.mmdb';

	/**
	 * What the store was asked to install: [path, metadata].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $installed = [];

	/**
	 * Jobs added to the list.
	 *
	 * @var array<int, string>
	 */
	private array $queued = [];

	/**
	 * Temporary files to remove.
	 *
	 * @var array<int, string>
	 */
	private array $files = [];

	private bool $installs = true;

	private bool $tempFiles = true;

	private bool $jobsFail = false;

	private array $logged = [];


	/**
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->files as $file) {
			@unlink($file);
		}

		parent::tearDown();
	}//end tearDown()


	/**
	 * The service with a DB-IP double that writes `$payload` (or copies the
	 * fixture when null, or throws when false) to the target.
	 *
	 * @param string            $provider  The configured provider.
	 * @param string|false|null $payload   What the download produces.
	 * @param bool              $queuedYet Whether the first-download job already waits.
	 *
	 * @return GeoRefreshService The service.
	 */
	private function service(string $provider = 'dbip', string|false|null $payload = null, bool $queuedYet = false): GeoRefreshService {
		$settings = $this->createMock(GeoSettings::class);
		$settings->method('provider')->willReturn($provider);

		$store = $this->createMock(GeoDatabaseStore::class);
		$store->method('install')->willReturnCallback(
			function (string $verifiedPath, array $metadata): bool {
				$this->installed[] = [$verifiedPath, $metadata];

				return $this->installs;
			}
		);
		$store->method('databasePath')->willReturn(null);
		$store->method('metadata')->willReturn([]);

		$dbip = $this->createMock(DbIpLiteProvider::class);
		$dbip->method('providerId')->willReturn('dbip');
		$dbip->method('attribution')->willReturn('IP Geolocation by DB-IP');
		$dbip->method('fetch')->willReturnCallback(
			static function (string $targetPath) use ($payload): string {
				if ($payload === false) {
					throw new RuntimeException('the network said no');
				}

				if ($payload === null) {
					copy(self::FIXTURE, $targetPath);
				} else {
					file_put_contents($targetPath, $payload);
				}

				return 'https://download.db-ip.com/free/dbip-city-lite-2026-09.mmdb.gz';
			}
		);

		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(
			function (): string|false {
				if ($this->tempFiles === false) {
					return false;
				}

				$path = (string)tempnam(sys_get_temp_dir(), 'geo');
				$this->files[] = $path;

				return $path;
			}
		);

		$jobs = $this->createMock(IJobList::class);
		$jobs->method('has')->willReturnCallback(
			function () use ($queuedYet): bool {
				if ($this->jobsFail === true) {
					throw new RuntimeException('the job list is down');
				}

				return $queuedYet;
			}
		);
		$jobs->method('add')->willReturnCallback(
			function (string $job): void {
				$this->queued[] = $job;
			}
		);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getTime')->willReturn(1788000000);

		return new GeoRefreshService(
			$settings,
			$store,
			$dbip,
			$this->createMock(MaxMindProvider::class),
			$temp,
			$jobs,
			$clock,
			$this->logger()
		);
	}//end service()


	/**
	 * Provider none: disabled, nothing fetched, nothing installed, nothing
	 * queued.
	 *
	 * @return void
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		return $logger;
	}//end logger()


	public function testProviderNoneIsDisabled(): void {
		$service = $this->service(provider: 'none');

		$result = $service->refresh();
		$this->assertSame('disabled', $result['status']);
		$this->assertStringContainsString('disabled', $result['message']);
		$this->assertSame([], $this->installed);
		$this->assertNull($service->provider());
		$this->assertFalse($service->queueFirstDownload());
		$this->assertSame([], $this->queued);
	}//end testProviderNoneIsDisabled()


	/**
	 * A real database is verified, installed, and described: provider,
	 * attribution, source, type, fetched at.
	 *
	 * @return void
	 */
	public function testARealDatabaseIsVerifiedAndInstalledWithItsAttribution(): void {
		$result = $this->service()->refresh();

		$this->assertSame('refreshed', $result['status']);
		$this->assertSame('dbip', $result['provider']);
		$this->assertCount(1, $this->installed);
		$metadata = $this->installed[0][1];
		$this->assertSame('dbip', $metadata['provider']);
		$this->assertSame('IP Geolocation by DB-IP', $metadata['attribution']);
		$this->assertSame('GeoIP2-City', $metadata['databaseType']);
		$this->assertSame('2026-08-29T10:40:00Z', $metadata['fetchedAt']);
		$this->assertStringContainsString('dbip-city-lite-2026-09', $metadata['source']);
	}//end testARealDatabaseIsVerifiedAndInstalledWithItsAttribution()


	/**
	 * A download that is not a database is NOT installed: the previous
	 * one stays, and the failure names the reason.
	 *
	 * @return void
	 */
	public function testADownloadThatDoesNotOpenIsNotInstalled(): void {
		$result = $this->service(payload: '<html>Not a database</html>')->refresh();

		$this->assertSame('failed', $result['status']);
		$this->assertStringContainsString('previous database', $result['message']);
		$this->assertSame([], $this->installed);
	}//end testADownloadThatDoesNotOpenIsNotInstalled()


	/**
	 * A provider that throws is a failure with its message, not an
	 * exception out of the job.
	 *
	 * @return void
	 */
	public function testAProviderFailureIsReportedNotThrown(): void {
		$result = $this->service(payload: false)->refresh();

		$this->assertSame('failed', $result['status']);
		$this->assertStringContainsString('the network said no', $result['message']);
	}//end testAProviderFailureIsReportedNotThrown()


	/**
	 * The first download is queued once: a second ask while one waits
	 * adds nothing.
	 *
	 * @return void
	 */
	public function testTheFirstDownloadIsQueuedOnce(): void {
		$this->assertTrue($this->service()->queueFirstDownload());
		$this->assertSame([TrafficGeoFirstDownloadJob::class], $this->queued);

		$this->queued = [];
		$this->assertFalse($this->service(queuedYet: true)->queueFirstDownload());
		$this->assertSame([], $this->queued);
	}//end testTheFirstDownloadIsQueuedOnce()

	/**
	 * Every refresh outcome, its metadata, its log line and the temporary file, exactly as recorded.
	 *
	 * @return void
	 */
	public function testEveryRefreshIsAsRecorded(): void {
		$source = 'https://download.db-ip.com/free/dbip-city-lite-2026-09.mmdb.gz';

		$this->assertSame(
			['status' => 'disabled', 'provider' => 'none', 'message' => 'Geography is disabled: the provider is set to none, so no database is fetched and no region is stored.'],
			$this->service(provider: 'none')->refresh()
		);

		$this->assertSame(['status' => 'refreshed', 'provider' => 'dbip', 'message' => 'Fetched ' . $source . ' (GeoIP2-City).'], $this->service()->refresh());
		$this->assertSame(
			['provider' => 'dbip', 'attribution' => 'IP Geolocation by DB-IP', 'source' => $source, 'databaseType' => 'GeoIP2-City', 'buildEpoch' => $this->installed[0][1]['buildEpoch'], 'fetchedAt' => '2026-08-29T10:40:00Z'],
			$this->installed[0][1]
		);
		$this->assertIsInt($this->installed[0][1]['buildEpoch']);
		$this->assertSame([['info', 'Portaliq: geography database refreshed', ['provider' => 'dbip', 'source' => $source]]], $this->logged);

		$this->logged   = [];
		$this->installs = false;
		$this->assertSame(
			['status' => 'failed', 'provider' => 'dbip', 'message' => 'Refresh from dbip failed: the verified database could not be moved into the app data directory The previous database, if any, stays in use.'],
			$this->service()->refresh()
		);
		$this->assertSame('error', $this->logged[0][0]);
		$this->assertCount(1, $this->logged);

		$this->installs = true;
		$this->service(payload: false)->refresh();
		$this->assertFileDoesNotExist(end($this->files), 'a failed download is removed');

		$this->tempFiles = false;
		$this->assertSame(
			['status' => 'failed', 'provider' => 'dbip', 'message' => 'Refresh from dbip failed: no temporary file could be created The previous database, if any, stays in use.'],
			$this->service()->refresh()
		);
	}//end testEveryRefreshIsAsRecorded()


	/**
	 * A job list that fails queues nothing and says so.
	 *
	 * @return void
	 */
	public function testAFailingJobListQueuesNothing(): void {
		$this->jobsFail = true;

		$this->assertFalse($this->service()->queueFirstDownload());
		$this->assertSame([], $this->queued);
		$this->assertSame([['warning', 'Portaliq: the first geography download could not be queued', ['reason' => 'the job list is down']]], $this->logged);

		$this->jobsFail = false;
		$this->logged   = [];
		$this->assertTrue($this->service()->queueFirstDownload());
		$this->assertSame('info', $this->logged[0][0]);
	}//end testAFailingJobListQueuesNothing()

	/**
	 * MaxMind is the provider when the settings name it, and the status
	 * reports the provider and that no database is installed yet.
	 *
	 * @return void
	 */
	public function testMaxMindIsChosenAndTheStatusSaysNothingIsInstalled(): void {
		$service = $this->service(provider: 'maxmind');

		$this->assertInstanceOf(MaxMindProvider::class, $service->provider());
		$this->assertSame(['provider' => 'maxmind', 'present' => false, 'path' => null, 'metadata' => []], $service->status());
	}//end testMaxMindIsChosenAndTheStatusSaysNothingIsInstalled()
}//end class
