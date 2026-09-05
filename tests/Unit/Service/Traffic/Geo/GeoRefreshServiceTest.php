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

				return true;
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
			function (): string {
				$path = (string)tempnam(sys_get_temp_dir(), 'geo');
				$this->files[] = $path;

				return $path;
			}
		);

		$jobs = $this->createMock(IJobList::class);
		$jobs->method('has')->willReturn($queuedYet);
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
			$this->createMock(LoggerInterface::class)
		);
	}//end service()


	/**
	 * Provider none: disabled, nothing fetched, nothing installed, nothing
	 * queued.
	 *
	 * @return void
	 */
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
}//end class
