<?php

/**
 * Portaliq Traffic Geo Database Store.
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

use OCA\Portaliq\AppInfo\Application;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Where the geography database lives: the app's data directory, never
 * the app source.
 *
 * THE FILE IS READ BY PATH, ON PURPOSE. The MMDB reader wants a file it
 * can seek in, and a city database is over a hundred megabytes: pulling
 * it through the simple filesystem's `getContent()` on every process
 * would be a memory limit gamble on every page view. So the app data
 * folder is created through `IAppData`, which is what puts it under
 * `appdata_<instance>/portaliq/`, and the local path of that folder is
 * then resolved through the root folder's storage. A primary storage that
 * has no local path (object storage) gets the same directory under the
 * data directory, which is local by definition.
 *
 * The database and its attribution are two files beside each other, so
 * an operator who installs a database by hand (or a test rig that copies
 * one in) needs no cache entry for either.
 *
 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-geography-must-come-from-an-offline-database-the-operator-chose
 */
class GeoDatabaseStore {

	/**
	 * The folder under the app's data directory.
	 */
	public const FOLDER = 'geo';

	/**
	 * The database file name.
	 */
	public const DATABASE = 'traffic-geo.mmdb';

	/**
	 * The attribution and provenance file name.
	 */
	public const METADATA = 'traffic-geo.json';

	/**
	 * The resolved directory, once.
	 *
	 * @var string|null
	 */
	private ?string $directory = null;

	/**
	 * Constructor.
	 *
	 * @param IAppData        $appData    This app's data folder.
	 * @param IRootFolder     $rootFolder Resolves the folder's local path.
	 * @param IConfig         $config     The data directory and instance id, for the fallback.
	 * @param LoggerInterface $logger     The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppData $appData,
		private readonly IRootFolder $rootFolder,
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * The local directory the files live in, created when absent.
	 *
	 * @return string|null The directory, or null when none can be made.
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-geography-must-come-from-an-offline-database-the-operator-chose
	 */
	public function directory(): ?string {
		if ($this->directory !== null) {
			return $this->directory;
		}

		$this->ensureAppDataFolder();
		$path = $this->localPathFromRoot() ?? $this->localPathFromConfig();
		if ($path === null) {
			return null;
		}

		if (is_dir($path) === false && $this->create(path: $path) === false) {
			$this->logger->error('Portaliq: the geography directory cannot be created', ['path' => $path]);

			return null;
		}

		$this->directory = $path;

		return $path;
	}

	/**
	 * The database file, when it exists.
	 *
	 * @return string|null The path, or null when absent.
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-geography-must-come-from-an-offline-database-the-operator-chose
	 */
	public function databasePath(): ?string {
		$directory = $this->directory();
		if ($directory === null) {
			return null;
		}

		$path = $directory . '/' . self::DATABASE;
		if (is_file($path) === false) {
			return null;
		}

		return $path;
	}

	/**
	 * What is known about the installed database: provider, attribution,
	 * source and when it was fetched. Empty when nothing is installed.
	 *
	 * @return array<string, mixed> The metadata.
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-geography-database-must-be-refreshed-without-an-operator-and-on-demand
	 */
	public function metadata(): array {
		$directory = $this->directory();
		if ($directory === null || is_file($directory . '/' . self::METADATA) === false) {
			return [];
		}

		$decoded = json_decode((string)file_get_contents($directory . '/' . self::METADATA), true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}

	/**
	 * Move a verified database into place and record where it came from.
	 *
	 * A rename, not a copy: the file in use is replaced in one step, so a
	 * request that opens the database during a refresh sees the old one
	 * or the new one and never a half-written one.
	 *
	 * @param string               $verifiedPath A database that already opened.
	 * @param array<string, mixed> $metadata     provider, attribution, source, fetchedAt.
	 *
	 * @return bool True when installed.
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-geography-database-must-be-refreshed-without-an-operator-and-on-demand
	 */
	public function install(string $verifiedPath, array $metadata): bool {
		$directory = $this->directory();
		if ($directory === null) {
			return false;
		}

		$target = $directory . '/' . self::DATABASE;
		if ($this->move(from: $verifiedPath, to: $target) === false) {
			$this->logger->error('Portaliq: the geography database cannot be moved into place', ['target' => $target]);

			return false;
		}

		$encoded = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($encoded === false) {
			$encoded = '{}';
		}

		return file_put_contents($directory . '/' . self::METADATA, $encoded) !== false;
	}

	/**
	 * Create a directory, reporting rather than warning.
	 *
	 * @param string $path The directory.
	 *
	 * @return bool True when it exists afterwards.
	 */
	private function create(string $path): bool {
		$parent = dirname($path);
		if (is_dir($parent) === false && $this->create(path: $parent) === false) {
			return false;
		}

		if (is_writable($parent) === false) {
			return false;
		}

		return mkdir($path, 0770) === true || is_dir($path) === true;
	}

	/**
	 * Replace the target with the source in one step where the filesystem
	 * allows a rename, and by copy then unlink across filesystems.
	 *
	 * @param string $from The verified download.
	 * @param string $to   The database path.
	 *
	 * @return bool True when the target now holds the download.
	 */
	private function move(string $from, string $to): bool {
		if (is_file($from) === false || is_writable(dirname($to)) === false) {
			return false;
		}

		$moved = rename($from, $to);
		if ($moved === false) {
			$moved = copy($from, $to);
			if ($moved === true && is_file($from) === true) {
				unlink($from);
			}
		}

		if ($moved === true) {
			chmod($to, 0640);
		}

		return $moved;
	}

	/**
	 * Create the folder through the app data API, so it exists where the
	 * platform expects app data. Best effort: the path resolution below
	 * has a fallback.
	 *
	 * @return void
	 */
	private function ensureAppDataFolder(): void {
		try {
			$this->appData->getFolder(self::FOLDER);
		} catch (NotFoundException) {
			try {
				$this->appData->newFolder(self::FOLDER);
			} catch (Throwable $e) {
				$this->logger->debug('Portaliq: app data folder for geography not created', ['reason' => $e->getMessage()]);
			}
		} catch (Throwable $e) {
			$this->logger->debug('Portaliq: app data folder for geography not readable', ['reason' => $e->getMessage()]);
		}
	}

	/**
	 * The folder's local path through the root folder's storage.
	 *
	 * @return string|null The path, or null when the storage has none.
	 */
	private function localPathFromRoot(): ?string {
		try {
			$node = $this->rootFolder->get($this->appDataPath());
			$local = $node->getStorage()->getLocalFile($node->getInternalPath());
		} catch (Throwable) {
			return null;
		}

		if (is_string($local) === false || $local === '') {
			return null;
		}

		return $local;
	}

	/**
	 * The same folder under the data directory, for a primary storage with
	 * no local path.
	 *
	 * @return string|null The path, or null without a data directory.
	 */
	private function localPathFromConfig(): ?string {
		$dataDirectory = rtrim($this->config->getSystemValueString('datadirectory', ''), '/');
		if ($dataDirectory === '') {
			return null;
		}

		return $dataDirectory . '/' . $this->appDataPath();
	}

	/**
	 * The folder's path relative to the data directory.
	 *
	 * @return string appdata_<instance>/portaliq/geo.
	 */
	private function appDataPath(): string {
		return 'appdata_' . $this->config->getSystemValueString('instanceid', '') . '/' . Application::APP_ID . '/' . self::FOLDER;
	}
}
