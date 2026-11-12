<?php

/**
 * Portaliq Traffic Geo Refresh Service.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 *
 * @spec openspec/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-geography-database-must-be-refreshed-without-an-operator-and-on-demand
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic\Geo;

use MaxMind\Db\Reader;
use OCA\Portaliq\BackgroundJob\TrafficGeoFirstDownloadJob;
use OCA\Portaliq\Service\Connection\ConnectionReporter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fetches the geography database from the configured provider and puts
 * it in place, once it has proven to be a database.
 *
 * THE FILE IN USE IS REPLACED LAST. The download lands in a temporary
 * file, the reader opens it and reads its metadata, and only then does it
 * move into the store. A provider that answers 200 with an HTML error
 * page, a truncated transfer, or a wrong archive therefore leaves the
 * previous database serving and the failure in the log, instead of
 * leaving every portal's region empty until somebody looks.
 *
 * Three callers: the monthly job, the occ command, and the queued first
 * download the resolver asks for when a portal wants a region and there
 * is no file yet.
 *
 * @spec openspec/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-geography-database-must-be-refreshed-without-an-operator-and-on-demand
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- the thirteenth class is
 * the optional ConnectionReporter (adopt-connection-registry). Reporting here
 * covers all three callers at once, where three caller-side reports could drift.
 */
class GeoRefreshService {

	/**
	 * The outcome when the provider is none.
	 */
	private const DISABLED = [
		'status' => 'disabled',
		'provider' => 'none',
		'message' => 'Geography is disabled: the provider is set to none, so no database is fetched and no region is stored.',
	];

	/**
	 * Constructor.
	 *
	 * @param GeoSettings       $settings The provider choice and credentials.
	 * @param GeoDatabaseStore  $store    Where the database lives.
	 * @param DbIpLiteProvider  $dbip     The default provider.
	 * @param MaxMindProvider   $maxmind  The optional provider.
	 * @param ITempManager      $temp     Temporary files for the download.
	 * @param IJobList          $jobs     Queues the first download.
	 * @param ITimeFactory      $time     The clock.
	 * @param LoggerInterface   $logger   The logger.
	 * @param ConnectionReporter|null $connectionReporter Tells integriq what a refresh met.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-002-a-geography-save-refreshes-and-a-refresh-or-a-failed-open-reports
	 */
	public function __construct(
		private readonly GeoSettings $settings,
		private readonly GeoDatabaseStore $store,
		private readonly DbIpLiteProvider $dbip,
		private readonly MaxMindProvider $maxmind,
		private readonly ITempManager $temp,
		private readonly IJobList $jobs,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
		private readonly ?ConnectionReporter $connectionReporter = null,
	) {
	}

	/**
	 * The provider the settings name, or null for `none`.
	 *
	 * @return GeoDatabaseProvider|null The provider.
	 *
	 * @spec openspec/specs/portal-traffic-visitors-and-geo/spec.md#requirement-geography-must-come-from-an-offline-database-the-operator-chose
	 */
	public function provider(): ?GeoDatabaseProvider {
		return match ($this->settings->provider()) {
			'dbip' => $this->dbip,
			'maxmind' => $this->maxmind,
			default => null,
		};
	}

	/**
	 * Download, verify, install, and tell integriq what the refresh met.
	 *
	 * The report reaches integriq's connection registry from all three
	 * callers alike (adopt-connection-registry). It never changes the result.
	 *
	 * @return array{status: string, provider: string, message: string} `disabled`, `refreshed` or `failed`, and why.
	 *
	 * @spec openspec/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-geography-database-must-be-refreshed-without-an-operator-and-on-demand
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-002-a-geography-save-refreshes-and-a-refresh-or-a-failed-open-reports
	 */
	public function refresh(): array {
		$result = $this->refreshDatabase();
		$this->connectionReporter?->geoRefreshed(result: $result);

		return $result;
	}

	/**
	 * Download, verify, install.
	 *
	 * @return array{status: string, provider: string, message: string} `disabled`, `refreshed` or `failed`, and why.
	 *
	 * @spec openspec/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-geography-database-must-be-refreshed-without-an-operator-and-on-demand
	 */
	private function refreshDatabase(): array {
		$provider = $this->provider();
		if ($provider === null) {
			return self::DISABLED;
		}

		$download = $this->temp->getTemporaryFile('.mmdb');
		if ($download === false) {
			return $this->failed(provider: $provider, reason: 'no temporary file could be created');
		}

		return $this->install(provider: $provider, download: $download);
	}

	/**
	 * Fetch into the downloaded file, verify it and install it; a download
	 * that fails on the way is removed.
	 *
	 * @param GeoDatabaseProvider $provider The provider.
	 * @param string              $download The temporary file.
	 *
	 * @return array{status: string, provider: string, message: string} `refreshed` or `failed`, and why.
	 */
	private function install(GeoDatabaseProvider $provider, string $download): array {
		try {
			$fetched = $this->fetchAndInstall(provider: $provider, download: $download);
			if ($fetched['installed'] === false) {
				return $this->failed(provider: $provider, reason: 'the verified database could not be moved into the app data directory');
			}
		} catch (Throwable $e) {
			if (is_file($download) === true) {
				unlink($download);
			}

			return $this->failed(provider: $provider, reason: $e->getMessage());
		}

		return $this->refreshed(provider: $provider, fetched: $fetched);
	}

	/**
	 * Fetch, verify and hand the file to the store with its provenance.
	 *
	 * @param GeoDatabaseProvider $provider The provider.
	 * @param string              $download The temporary file.
	 *
	 * @return array{source: string, verified: array{databaseType: string, buildEpoch: int}, installed: bool}
	 *
	 * @throws Throwable When the fetch fails or the file is not a database the reader opens.
	 */
	private function fetchAndInstall(GeoDatabaseProvider $provider, string $download): array {
		$source    = $provider->fetch(targetPath: $download);
		$verified  = $this->verify(path: $download);
		$installed = $this->store->install(
			verifiedPath: $download,
			metadata: ['provider' => $provider->providerId(), 'attribution' => $provider->attribution(), 'source' => $source] + $verified
				+ ['fetchedAt' => gmdate('Y-m-d\TH:i:s\Z', $this->time->getTime())]
		);

		return ['source' => $source, 'verified' => $verified, 'installed' => $installed];
	}

	/**
	 * A refresh that installed, logged.
	 *
	 * @param GeoDatabaseProvider                                                                  $provider The provider.
	 * @param array{source: string, verified: array{databaseType: string, buildEpoch: int}} $fetched  What was fetched.
	 *
	 * @return array{status: string, provider: string, message: string} The outcome.
	 */
	private function refreshed(GeoDatabaseProvider $provider, array $fetched): array {
		$this->logger->info('Portaliq: geography database refreshed', ['provider' => $provider->providerId(), 'source' => $fetched['source']]);

		return [
			'status' => 'refreshed',
			'provider' => $provider->providerId(),
			'message' => 'Fetched ' . $fetched['source'] . ' (' . $fetched['verified']['databaseType'] . ').',
		];
	}

	/**
	 * What the admin panel and the command show: the provider, whether a
	 * database is installed, and its provenance.
	 *
	 * @return array{provider: string, present: bool, path: string|null, metadata: array<string, mixed>} The status.
	 *
	 * @spec openspec/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-geography-database-must-be-refreshed-without-an-operator-and-on-demand
	 */
	public function status(): array {
		$path = $this->store->databasePath();

		return [
			'provider' => $this->settings->provider(),
			'present' => ($path !== null),
			'path' => $path,
			'metadata' => $this->store->metadata(),
		];
	}

	/**
	 * Queue the first download, once. Called from the resolver when a
	 * portal wants a region and there is no database; the collector
	 * request itself downloads nothing.
	 *
	 * @return bool True when a job was queued now, false when one already waits or the provider is none.
	 *
	 * @spec openspec/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-geography-database-must-be-refreshed-without-an-operator-and-on-demand
	 */
	public function queueFirstDownload(): bool {
		if ($this->provider() === null || $this->queueOnce() === false) {
			return false;
		}

		$this->logger->info('Portaliq: a portal wants a region and no geography database exists; the first download is queued for the background job');

		return true;
	}

	/**
	 * Add the first-download job unless one already waits.
	 *
	 * @return bool True when it was added now.
	 */
	private function queueOnce(): bool {
		try {
			if ($this->jobs->has(TrafficGeoFirstDownloadJob::class, null) === true) {
				return false;
			}

			$this->jobs->add(TrafficGeoFirstDownloadJob::class);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: the first geography download could not be queued', ['reason' => $e->getMessage()]);

			return false;
		}

		return true;
	}

	/**
	 * Open a downloaded file as a database and read its metadata.
	 *
	 * @param string $path The downloaded file.
	 *
	 * @return array{databaseType: string, buildEpoch: int} What it is.
	 *
	 * @throws Throwable When it is not a database the reader opens.
	 */
	private function verify(string $path): array {
		$reader = new Reader($path);
		try {
			$metadata = $reader->metadata();

			return ['databaseType' => (string)$metadata->databaseType, 'buildEpoch' => (int)$metadata->buildEpoch];
		} finally {
			$reader->close();
		}
	}

	/**
	 * A failure, logged.
	 *
	 * @param GeoDatabaseProvider $provider The provider that failed.
	 * @param string              $reason   Why.
	 *
	 * @return array{status: string, provider: string, message: string} The outcome.
	 */
	private function failed(GeoDatabaseProvider $provider, string $reason): array {
		$this->logger->error('Portaliq: geography database refresh failed', ['provider' => $provider->providerId(), 'reason' => $reason]);

		return [
			'status' => 'failed',
			'provider' => $provider->providerId(),
			'message' => 'Refresh from ' . $provider->providerId() . ' failed: ' . $reason . ' The previous database, if any, stays in use.',
		];
	}
}
