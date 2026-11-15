<?php

/**
 * Contract of TrafficServerBatch
 *
 * The public surface of TrafficServerBatch that other components call, so they depend
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
 * Contract of TrafficServerBatch.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TrafficServerBatchInterface {



	/**
	 * The batch's events grouped by the visitor they belong to, each
	 * event's own `remoteAddress` and `userAgent` winning over the batch's.
	 *
	 * @param array<string, mixed> $batch The decoded batch.
	 *
	 * @return array<int, array{ip: string, userAgent: string, events: array<int, mixed>}> The groups.
	 *
	 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-a-server-side-caller-must-hold-the-portals-token
	 */
	public function byVisitor(array $batch): array;
}//end interface
