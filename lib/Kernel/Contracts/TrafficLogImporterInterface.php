<?php

/**
 * Contract of TrafficLogImporter
 *
 * The public surface of TrafficLogImporter that other components call, so they depend
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
 * Contract of TrafficLogImporter.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TrafficLogImporterInterface {

	/**
	 * Import every line of a stream.
	 *
	 * @param string   $slug   The portal slug.
	 * @param resource $stream The open log, read line by line.
	 * @param string   $format `combined` or `json`.
	 * @param string   $host   The site's origin (`https://example.org`), for the page location.
	 *
	 * @return array{lines: int, views: int, skipped: int, duplicates: int, accepted: int,
	 *               refused: array<string, int>}|null The outcome, or null for an unknown portal.
	 *
	 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-an-access-log-must-import-as-page-views-without-assets-or-bots
	 */
	public function import(string $slug, $stream, string $format, string $host): ?array;
}//end interface
