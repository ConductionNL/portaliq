<?php

/**
 * Contract of AvailabilityRollup
 *
 * The public surface of AvailabilityRollup that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of AvailabilityRollup.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AvailabilityRollupInterface {

	/**
	 * The check interval, in seconds.
	 */
	public const INTERVAL = 300;

	/**
	 * The latest `lastCheckAt` among the given days, or null.
	 *
	 * @param array<string, array<string, mixed>> $days The daily records.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/specs/portal-availability/spec.md#requirement-an-interval-without-a-check-counts-as-down-req-oar-002
	 */
	public function lastCheckAt(array $days): ?DateTimeImmutable;

	/**
	 * Fold one check into the portal's days and outage.
	 *
	 * @param string $portal The portal slug.
	 * @param DateTimeImmutable $checkedAt When the check ran.
	 * @param string $status AVAILABLE, DEGRADED or DOWN.
	 * @param string $cause Why it was not available ('' when it was).
	 * @param array<string, array<string, mixed>> $days The stored daily
	 *                                                  records by date that
	 *                                                  the check and the gap
	 *                                                  before it touch.
	 * @param array<string, mixed>|null $openOutage The portal's open outage.
	 *
	 * @return array{days: array<string, array<string, mixed>>, open: array<string, mixed>|null, closed: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/portal-availability/spec.md#requirement-an-interval-without-a-check-counts-as-down-req-oar-002
	 */
	public function record(string $portal, DateTimeImmutable $checkedAt, string $status, string $cause, array $days, ?array $openOutage): array;
}//end interface
