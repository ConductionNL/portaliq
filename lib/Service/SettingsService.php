<?php

/**
 * Portaliq Settings Service
 *
 * Service for managing Portaliq application configuration and settings.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Traffic\Geo\GeoRefreshService;
use OCA\Portaliq\Service\Traffic\Geo\GeoSettings;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing Portaliq application configuration and settings.
 *
 * @spec openspec/specs/settings-management/spec.md#REQ-CFG-001
 */
class SettingsService {

	/**
	 * Configuration keys managed by this service.
	 *
	 * @var array<string>
	 */
	private const CONFIG_KEYS = [
		'register',
	];

	/**
	 * The settings key the visitor geography block travels under.
	 */
	public const GEO_KEY = 'traffic_geo';

	/**
	 * Constructor for the SettingsService.
	 *
	 * @param IAppConfig $appConfig The app config interface
	 * @param IAppManager $appManager The app manager
	 * @param ContainerInterface $container The container
	 * @param IGroupManager $groupManager The group manager
	 * @param IUserSession $userSession The user session
	 * @param LoggerInterface $logger The logger
	 * @param PageEditorService $pageEditor Owns the editor groups and the schema rules they become
	 * @param GeoSettings $geoSettings The visitor geography provider and credentials
	 * @param GeoRefreshService $geoRefresh Reports whether a geography database is installed
	 *
	 * @return void
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IAppManager $appManager,
		private ContainerInterface $container,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private PageEditorService $pageEditor,
		private GeoSettings $geoSettings,
		private GeoRefreshService $geoRefresh,
	) {
	}//end __construct()

	/**
	 * Check whether OpenRegister is installed and available.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/settings-management/spec.md#REQ-CFG-001
	 */
	public function isOpenRegisterAvailable(): bool {
		return $this->appManager->isInstalled('openregister');
	}//end isOpenRegisterAvailable()

	/**
	 * Retrieve all current settings.
	 *
	 * Returns a flat array containing all app config values plus metadata
	 * fields (openregisters, isAdmin) consumed by the frontend.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/settings-management/spec.md#REQ-CFG-001
	 */
	public function getSettings(): array {
		$settings = [];
		foreach (self::CONFIG_KEYS as $key) {
			$settings[$key] = $this->appConfig->getValueString(Application::APP_ID, $key, '');
		}

		$user = $this->userSession->getUser();
		$isAdmin = ($user !== null && $this->groupManager->isAdmin($user->getUID()));

		$extra = [
			'openregisters' => $this->isOpenRegisterAvailable(),
			'isAdmin' => $isAdmin,
			'editor_groups' => $this->pageEditor->getEditorGroups(),
			'mayEditPages' => $this->pageEditor->mayEdit(),
		];

		// The instance's full group list is offered to the picker that
		// configures page editing, and to nobody else. It is not secret, but a
		// non-admin has no field to put it in — and an endpoint that hands out
		// the org chart to every authenticated caller because one admin form
		// needed it is how that stops being true.
		if ($isAdmin === true) {
			$extra['availableGroups'] = $this->pageEditor->availableGroups();
			// Visitor geography (portal-traffic-visitors-and-geo): the provider,
			// the MaxMind account id, whether a licence key is stored (never
			// the key), and whether a database is installed. Administrators
			// only: the provider choice is instance configuration.
			$extra[self::GEO_KEY] = $this->geoSettings->toArray() + ['status' => $this->geoRefresh->status()];
		}

		return array_merge($settings, $extra);
	}//end getSettings()

	/**
	 * Update settings with the provided data.
	 *
	 * @param array<string,mixed> $data The data to update
	 *
	 * @return array<string,mixed> The updated settings
	 *
	 * @spec openspec/specs/settings-management/spec.md#REQ-CFG-002
	 */
	public function updateSettings(array $data): array {
		foreach (self::CONFIG_KEYS as $key) {
			if (isset($data[$key]) === true) {
				$this->appConfig->setValueString(Application::APP_ID, $key, (string)$data[$key]);
			}
		}

		// NOT one of CONFIG_KEYS, because it is not a string and storing it is
		// not the whole write: the groups are pushed into the `page` schema's
		// authorization block, which is where OpenRegister actually refuses a
		// non-editor's write. See PageEditorService::setEditorGroups().
		if (isset($data[PageEditorService::CONFIG_KEY]) === true
			&& is_array($data[PageEditorService::CONFIG_KEY]) === true
		) {
			$this->pageEditor->setEditorGroups($data[PageEditorService::CONFIG_KEY]);
		}

		// Also not one of CONFIG_KEYS: the licence key is stored SENSITIVE
		// and the block is validated by GeoSettings, which never echoes it.
		if (isset($data[self::GEO_KEY]) === true && is_array($data[self::GEO_KEY]) === true) {
			$this->geoSettings->update($data[self::GEO_KEY]);
		}

		return $this->getSettings();
	}//end updateSettings()

	/**
	 * Load configuration from app_template_register.json via OpenRegister.
	 *
	 * @param bool $force Force re-import even if already configured.
	 *
	 * @return array<string,mixed> Result with success flag, message, and version.
	 *
	 * @spec openspec/specs/settings-management/spec.md#REQ-CFG-003
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) -- mirrors OpenRegister's
	 * ConfigurationService::importFromApp() force semantics; splitting into two
	 * methods would fork the fleet-canonical import API.
	 */
	public function loadConfiguration(bool $force = false): array {
		if ($this->isOpenRegisterAvailable() === false) {
			$this->logger->warning('Portaliq: OpenRegister not available, skipping register initialization');
			return [
				'success' => false,
				'message' => 'OpenRegister is not installed or enabled.',
			];
		}

		try {
			$configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');

			$absPath = $this->appManager->getAppPath(Application::APP_ID) . '/lib/Settings/' . Application::APP_ID . '_register.json';
			$version = '0.1.0';
			$data = [];
			if (is_file($absPath) === true) {
				$data = json_decode(file_get_contents($absPath), true) ?? [];
				$version = $data['info']['version'] ?? $version;
			}

			$result = $configurationService->importFromApp(
				appId: Application::APP_ID,
				data: $data,
				version: $version,
				force: $force
			);

			if (empty($result) === false) {
				$this->logger->info('Portaliq: register configuration imported successfully');

				// THE IMPORT CARRIES THE SEED'S OWN AUTHORIZATION BLOCK, which
				// declares empty write rules — admin-only. Left alone, every
				// upgrade would therefore silently un-configure page editing on
				// an instance that had configured it, and the symptom would be
				// an editor who could edit yesterday being refused today with
				// the settings page still showing their group.
				$this->pageEditor->applyToSchema($this->pageEditor->getEditorGroups());
				return [
					'success' => true,
					'message' => 'Configuration imported successfully.',
					'version' => ($result['version'] ?? $version),
				];
			}

			return [
				'success' => false,
				'message' => 'Import returned an empty result.',
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'Portaliq: configuration import failed',
				['exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'message' => $e->getMessage(),
			];
		}//end try
	}//end loadConfiguration()
}//end class
