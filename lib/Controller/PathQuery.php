<?php

/**
 * Portaliq Path Query
 *
 * One question to the path explorer: the portal, period and segment, the
 * direction, the anchor page, the steps and the chosen trail.
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
 * @spec openspec/specs/portal-traffic-path-explorer/spec.md#requirement-the-paths-endpoint-must-count-each-visits-own-path-from-the-raw-events
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

/**
 * The parameters of one TrafficPathController::paths() request, with its
 * defaults filled in and its trail parsed.
 *
 * @spec openspec/specs/portal-traffic-path-explorer/spec.md#requirement-the-paths-endpoint-must-count-each-visits-own-path-from-the-raw-events
 */
final class PathQuery {
	/**
	 * Constructor.
	 *
	 * @param string                   $portal  The portal slug.
	 * @param string                   $from    The first day, YYYY-MM-DD.
	 * @param string                   $to      The last day, YYYY-MM-DD.
	 * @param string                   $segment The segment id, '' for all visits.
	 * @param string                   $mode    `start` or `end`.
	 * @param string                   $anchor  A page path, '' for the start or end of a visit.
	 * @param string                   $steps   The steps, as sent.
	 * @param array<int, ?string>|null $trail   The parsed trail, null when it did not parse.
	 */
	public function __construct(
		public readonly string $portal,
		public readonly string $from,
		public readonly string $to,
		public readonly string $segment,
		public readonly string $mode,
		public readonly string $anchor,
		public readonly string $steps,
		public readonly ?array $trail,
	) {
	}//end __construct()
}//end class
