<?php

/**
 * Portaliq Traffic DB-IP Lite Provider.
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

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\ITempManager;
use RuntimeException;
use Throwable;

/**
 * DB-IP's free city database, the default (Ruben, decision 7).
 *
 * One file per month at a predictable URL, gzip compressed, no account.
 * The licence is CC BY 4.0, which is why the attribution is not optional
 * here: it is stored beside the file and shown in the admin panel.
 *
 * The current month is tried first. A refresh that runs on the first of
 * the month may find that file not published yet, so the previous month
 * is the fallback rather than a failure.
 *
 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-geography-must-come-from-an-offline-database-the-operator-chose
 */
class DbIpLiteProvider implements GeoDatabaseProvider {

	/**
	 * The download URL pattern; `%s` is YYYY-MM.
	 */
	public const URL = 'https://download.db-ip.com/free/dbip-city-lite-%s.mmdb.gz';

	/**
	 * The attribution CC BY 4.0 asks for.
	 */
	public const ATTRIBUTION = 'IP Geolocation by DB-IP (https://db-ip.com), licensed under CC BY 4.0';

	/**
	 * Seconds a download may take. The file is around sixty megabytes.
	 */
	private const TIMEOUT = 600;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clients The HTTP client factory.
	 * @param ITempManager   $temp    Temporary files for the compressed download.
	 * @param ITimeFactory   $time    The clock, for the month.
	 * @param GzipExtractor  $gzip    Decompresses the download.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IClientService $clients,
		private readonly ITempManager $temp,
		private readonly ITimeFactory $time,
		private readonly GzipExtractor $gzip = new GzipExtractor(),
	) {
	}

	/**
	 * The provider id.
	 *
	 * @return string dbip.
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-geography-must-come-from-an-offline-database-the-operator-chose
	 */
	public function providerId(): string {
		return 'dbip';
	}

	/**
	 * The attribution line.
	 *
	 * @return string The CC BY 4.0 attribution.
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-geography-must-come-from-an-offline-database-the-operator-chose
	 */
	public function attribution(): string {
		return self::ATTRIBUTION;
	}

	/**
	 * The URLs to try, newest first.
	 *
	 * @return array<int, string> This month's URL, then last month's.
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-geography-database-must-be-refreshed-without-an-operator-and-on-demand
	 */
	public function urls(): array {
		$now = $this->time->getTime();

		return [
			sprintf(self::URL, gmdate('Y-m', $now)),
			sprintf(self::URL, gmdate('Y-m', (int)strtotime('first day of last month', $now))),
		];
	}

	/**
	 * Download the month's file and decompress it to the target.
	 *
	 * @param string $targetPath Where the MMDB file must end up.
	 *
	 * @return string The URL that answered.
	 *
	 * @throws RuntimeException When neither month's file could be fetched.
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-geography-database-must-be-refreshed-without-an-operator-and-on-demand
	 */
	public function fetch(string $targetPath): string {
		$failures = [];
		foreach ($this->urls() as $url) {
			$compressed = $this->temp->getTemporaryFile('.mmdb.gz');
			if ($compressed === false) {
				throw new RuntimeException('No temporary file could be created for the download.');
			}

			try {
				$response = $this->clients->newClient()->get($url, ['sink' => $compressed, 'timeout' => self::TIMEOUT]);
				if ($response->getStatusCode() !== 200) {
					$failures[] = $url . ' answered ' . $response->getStatusCode();
					continue;
				}

				$this->gzip->extract(source: $compressed, target: $targetPath);

				return $url;
			} catch (Throwable $e) {
				$failures[] = $url . ': ' . $e->getMessage();
			} finally {
				if (is_file($compressed) === true) {
					unlink($compressed);
				}
			}
		}

		throw new RuntimeException('DB-IP download failed: ' . implode('; ', $failures));
	}
}
