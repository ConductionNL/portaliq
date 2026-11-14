<?php

/**
 * Contract of SettingsService
 *
 * The public surface of SettingsService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

/**
 * Contract of SettingsService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SettingsServiceInterface {

	/**
	 * The settings key the visitor geography block travels under.
	 */
	public const GEO_KEY = 'traffic_geo';

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
	public function getSettings(): array;

	/**
	 * Check whether OpenRegister is installed and available.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/settings-management/spec.md#REQ-CFG-001
	 */
	public function isOpenRegisterAvailable(): bool;

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
	public function loadConfiguration(bool $force = false): array;

	/**
	 * Update settings with the provided data.
	 *
	 * @param array<string,mixed> $data The data to update
	 *
	 * A geography save also asks integriq to resolve the geography database
	 * connection again, then reports what the saved settings say
	 * (adopt-connection-registry). That never throws, does nothing without
	 * integriq, and never changes the result.
	 *
	 * @return array<string,mixed> The updated settings
	 *
	 * @spec openspec/specs/settings-management/spec.md#REQ-CFG-002
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-002-a-geography-save-refreshes-and-a-refresh-or-a-failed-open-reports
	 */
	public function updateSettings(array $data): array;
}//end interface
