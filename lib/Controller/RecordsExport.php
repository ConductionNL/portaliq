<?php

/**
 * Portaliq Records Export
 *
 * One download of a portal's daily traffic records: which portal, which
 * days, which segment, and in which format.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-the-daily-records-must-be-exportable
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

/**
 * The parameters of one TrafficReportController::export() request.
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-the-daily-records-must-be-exportable
 */
final class RecordsExport {
	/**
	 * Constructor.
	 *
	 * @param string $portal  The portal slug.
	 * @param string $from    The first day, YYYY-MM-DD.
	 * @param string $to      The last day, YYYY-MM-DD.
	 * @param string $segment The segment id, '' for all sessions.
	 * @param string $format  `csv` or `json`.
	 */
	public function __construct(
		public readonly string $portal,
		public readonly string $from,
		public readonly string $to,
		public readonly string $segment,
		public readonly string $format,
	) {
	}//end __construct()
}//end class
