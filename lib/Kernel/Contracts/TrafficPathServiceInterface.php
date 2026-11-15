<?php

/**
 * Contract of TrafficPathService
 *
 * The public surface of TrafficPathService that other components call, so they depend
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
 * Contract of TrafficPathService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TrafficPathServiceInterface {

	/**
	 * The explorer for a portal, a period and a segment.
	 *
	 * @param string              $portal  The portal slug.
	 * @param string              $from    The first day, YYYY-MM-DD.
	 * @param string              $to      The last day, YYYY-MM-DD.
	 * @param string              $segment The segment id, '' for all visits.
	 * @param string              $mode    `start` or `end`.
	 * @param string              $anchor  The page, '' for a visit's start or end.
	 * @param int                 $steps   The steps after step 0.
	 * @param array<int, ?string> $trail   The chosen page per step.
	 *
	 * @return array<string, mixed> The explorer and what it covers, or `['error' => reason]`.
	 *
	 * @spec openspec/specs/portal-traffic-path-explorer/spec.md#requirement-the-explorer-must-say-when-it-shows-less-than-the-chosen-period
	 * @spec openspec/specs/portal-traffic-path-explorer/spec.md#requirement-the-explorer-must-follow-the-pages-portal-period-and-segment
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the eight are the
	 * request's eight parameters, each validated by the controller.
	 */
	public function explore(
		string $portal,
		string $from,
		string $to,
		string $segment,
		string $mode,
		string $anchor,
		int $steps,
		array $trail
	): array;
}//end interface
