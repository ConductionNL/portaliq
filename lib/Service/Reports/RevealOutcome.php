<?php

/**
 * Portaliq Reveal Outcome
 *
 * What a custodian's answer records on the request: allowed or refused, the
 * names of the fields that were shown, and when.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Reports
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
 * @spec openspec/specs/report-without-an-account/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Reports;

/**
 * The recorded outcome of a reveal request.
 *
 * @spec openspec/specs/report-without-an-account/spec.md
 */
final class RevealOutcome {
	/**
	 * Constructor.
	 *
	 * @param string             $state      `allowed` or `refused`.
	 * @param array<int, string> $fields     Which fields were shown, by name.
	 * @param string             $occurredAt When, ISO 8601.
	 */
	public function __construct(
		public readonly string $state,
		public readonly array $fields,
		public readonly string $occurredAt,
	) {
	}//end __construct()
}//end class
