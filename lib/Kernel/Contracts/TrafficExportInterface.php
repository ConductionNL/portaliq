<?php

/**
 * Contract of TrafficExport
 *
 * The public surface of TrafficExport that other components call, so they depend
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
 * Contract of TrafficExport.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TrafficExportInterface {

	/**
	 * The formats.
	 *
	 * @var string[]
	 */
	public const FORMATS = ['csv', 'json'];

	/**
	 * The records as CSV.
	 *
	 * @param array<int, array<string, mixed>> $records The daily records.
	 *
	 * @return string The CSV, header first, CRLF lines.
	 *
	 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-the-daily-records-must-be-exportable
	 */
	public function csv(array $records): string;

	/**
	 * The records as JSON.
	 *
	 * @param array<int, array<string, mixed>> $records The daily records.
	 *
	 * @return string The JSON array.
	 *
	 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-the-daily-records-must-be-exportable
	 */
	public function json(array $records): string;

	/**
	 * The file name for a download.
	 *
	 * @param string $portal  The portal slug.
	 * @param string $from    The first day.
	 * @param string $to      The last day.
	 * @param string $segment The segment, '' for all.
	 * @param string $format  `csv` or `json`.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-the-daily-records-must-be-exportable
	 */
	public function fileName(string $portal, string $from, string $to, string $segment, string $format): string;
}//end interface
