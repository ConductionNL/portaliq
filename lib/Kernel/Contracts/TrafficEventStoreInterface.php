<?php

/**
 * Contract of TrafficEventStore
 *
 * The public surface of TrafficEventStore that other components call, so they depend
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
 * Contract of TrafficEventStore.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TrafficEventStoreInterface {

	/**
	 * A portal's rollups over a span of days, for one segment, oldest first.
	 *
	 * @param string $portal  The portal slug.
	 * @param string $from    The first day, inclusive.
	 * @param string $to      The last day, inclusive.
	 * @param string $segment The segment id, '' for all sessions.
	 *
	 * @return array<int, array<string, mixed>> The rollups.
	 *
	 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-the-daily-records-must-be-exportable
	 */
	public function dailyBetween(string $portal, string $from, string $to, string $segment = ''): array;
}//end interface
