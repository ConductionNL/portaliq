<?php

/**
 * Portaliq Traffic MMDB Geo Resolver.
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
 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-geography-must-come-from-an-offline-database-the-operator-chose
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic\Geo;

use MaxMind\Db\Reader;
use OCA\Portaliq\Service\Traffic\GeoResolverInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The address goes in, a country or a subdivision comes out, and the
 * address goes nowhere else.
 *
 * An offline lookup in the database the store holds. No network is
 * touched on the request path: when the file is absent the resolver asks
 * the refresh service to queue the first download, says so in the log
 * once per process, and answers null, so the event is stored without a
 * region and the visitor waits for nothing.
 *
 * The reader is opened once per process and reused. A city database is
 * mapped, not read, so that is cheap; opening it per event would not be.
 *
 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-geography-must-come-from-an-offline-database-the-operator-chose
 */
class MmdbGeoResolver implements GeoResolverInterface {

	/**
	 * The open reader, or null before the first lookup.
	 *
	 * @var Reader|null
	 */
	private ?Reader $reader = null;

	/**
	 * Whether opening was already attempted this process.
	 *
	 * @var bool
	 */
	private bool $opened = false;

	/**
	 * Constructor.
	 *
	 * @param GeoSettings      $settings The provider choice.
	 * @param GeoDatabaseStore $store    Where the database lives.
	 * @param GeoRefreshService $refresh Queues the first download.
	 * @param LoggerInterface  $logger   The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly GeoSettings $settings,
		private readonly GeoDatabaseStore $store,
		private readonly GeoRefreshService $refresh,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * The region code for an address, no finer than asked.
	 *
	 * @param string $address     The visitor's address.
	 * @param string $granularity One of `country`, `region`.
	 *
	 * @return string|null `NL`, `NL-NB`, or null when unknown, absent or disabled.
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-geography-must-come-from-an-offline-database-the-operator-chose
	 */
	public function resolve(string $address, string $granularity): ?string {
		if ($granularity === 'none' || $address === '' || $this->settings->provider() === 'none') {
			return null;
		}

		$reader = $this->reader();
		if ($reader === null) {
			return null;
		}

		try {
			$record = $reader->get($address);
		} catch (Throwable) {
			// A malformed address, or one outside the database's address
			// family. Not a region, and not worth a log line per event.
			return null;
		}

		if (is_array($record) === false) {
			return null;
		}

		return $this->code(record: $record, granularity: $granularity);
	}

	/**
	 * The code for a database record: the country, or country-subdivision
	 * at region granularity when the record has one.
	 *
	 * @param array<string, mixed> $record      The database record.
	 * @param string               $granularity `country` or `region`.
	 *
	 * @return string|null The code, or null without a valid country.
	 */
	private function code(array $record, string $granularity): ?string {
		$country = strtoupper(trim((string)($record['country']['iso_code'] ?? '')));
		if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
			return null;
		}

		if ($granularity !== 'region') {
			return $country;
		}

		$subdivision = strtoupper(trim((string)($record['subdivisions'][0]['iso_code'] ?? '')));
		if (preg_match('/^[A-Z0-9]{1,3}$/', $subdivision) !== 1) {
			return $country;
		}

		return $country . '-' . $subdivision;
	}

	/**
	 * The reader, opened on first use; null when there is no database.
	 *
	 * @return Reader|null The reader.
	 */
	private function reader(): ?Reader {
		if ($this->opened === true) {
			return $this->reader;
		}

		$this->opened = true;
		$path = $this->store->databasePath();
		if ($path === null) {
			$this->refresh->queueFirstDownload();
			$this->logger->info('Portaliq: no geography database is installed yet; regions stay empty until the first download completes');

			return null;
		}

		try {
			$this->reader = new Reader($path);
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: the geography database cannot be opened', ['path' => $path, 'reason' => $e->getMessage()]);
			$this->reader = null;
		}

		return $this->reader;
	}
}
