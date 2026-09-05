<?php

/**
 * Portaliq Traffic MaxMind Provider.
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

use OCP\Http\Client\IClientService;
use OCP\ITempManager;
use RuntimeException;
use Throwable;

/**
 * MaxMind's GeoLite2-City or GeoIP2-City, for an operator with an
 * account (Ruben, decision 7: optional, account id plus licence key).
 *
 * The download is a tarball with the database one directory down; the
 * extractor pulls that one member out. The credentials travel as HTTP
 * basic auth on the one request that needs them and are read from the
 * settings at that moment, never held on this object.
 *
 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-geography-must-come-from-an-offline-database-the-operator-chose
 */
class MaxMindProvider implements GeoDatabaseProvider {

	/**
	 * The download URL pattern; `%s` is the edition.
	 */
	public const URL = 'https://download.maxmind.com/geoip/databases/%s/download?suffix=tar.gz';

	/**
	 * Seconds a download may take.
	 */
	private const TIMEOUT = 600;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clients  The HTTP client factory.
	 * @param ITempManager   $temp     Temporary files for the archive.
	 * @param GeoSettings    $settings The credentials and the edition.
	 * @param GzipExtractor  $gzip     Pulls the database out of the tarball.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IClientService $clients,
		private readonly ITempManager $temp,
		private readonly GeoSettings $settings,
		private readonly GzipExtractor $gzip = new GzipExtractor(),
	) {
	}

	/**
	 * The provider id.
	 *
	 * @return string maxmind.
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-geography-must-come-from-an-offline-database-the-operator-chose
	 */
	public function providerId(): string {
		return 'maxmind';
	}

	/**
	 * The attribution line for the configured edition.
	 *
	 * @return string The attribution.
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-geography-must-come-from-an-offline-database-the-operator-chose
	 */
	public function attribution(): string {
		return 'This product includes ' . $this->settings->maxMindEdition() . ' data created by MaxMind, available from https://www.maxmind.com';
	}

	/**
	 * The download URL for the configured edition.
	 *
	 * @return string The URL.
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-geography-database-must-be-refreshed-without-an-operator-and-on-demand
	 */
	public function url(): string {
		return sprintf(self::URL, $this->settings->maxMindEdition());
	}

	/**
	 * Download the edition's tarball and extract the database.
	 *
	 * @param string $targetPath Where the MMDB file must end up.
	 *
	 * @return string The edition and the member that was extracted.
	 *
	 * @throws RuntimeException Without credentials, or when the download fails.
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-geography-database-must-be-refreshed-without-an-operator-and-on-demand
	 */
	public function fetch(string $targetPath): string {
		$accountId = $this->settings->maxMindAccountId();
		$licenseKey = $this->settings->maxMindLicenseKey();
		if ($accountId === '' || $licenseKey === '') {
			throw new RuntimeException('MaxMind needs an account id and a licence key in the Portaliq settings.');
		}

		$archive = $this->temp->getTemporaryFile('.tar.gz');
		if ($archive === false) {
			throw new RuntimeException('No temporary file could be created for the download.');
		}

		try {
			$response = $this->clients->newClient()->get(
				$this->url(),
				[
					'sink' => $archive,
					'timeout' => self::TIMEOUT,
					'headers' => ['Authorization' => 'Basic ' . base64_encode($accountId . ':' . $licenseKey)],
				]
			);
			if ($response->getStatusCode() !== 200) {
				throw new RuntimeException('MaxMind answered ' . $response->getStatusCode() . ' for ' . $this->settings->maxMindEdition());
			}

			$member = $this->gzip->extractTarMember(source: $archive, suffix: '.mmdb', target: $targetPath);
		} catch (RuntimeException $e) {
			throw $e;
		} catch (Throwable $e) {
			throw new RuntimeException('MaxMind download failed: ' . $e->getMessage(), 0, $e);
		} finally {
			if (is_file($archive) === true) {
				unlink($archive);
			}
		}

		return $this->settings->maxMindEdition() . ' (' . $member . ')';
	}
}
