<?php

/**
 * Contract of PortalAdminWriteGate
 *
 * The public surface of PortalAdminWriteGate that other components call, so they depend
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
 * Contract of PortalAdminWriteGate.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalAdminWriteGateInterface {

	/**
	 * Why this write is refused, or null when it may go ahead.
	 *
	 * @param string                    $schema The schema slug of the row.
	 * @param array<string, mixed>      $row    The row as it will be (or, on delete, as it is).
	 * @param array<string, mixed>|null $before The row before an update; null on create and delete.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t11
	 */
	public function refusal(string $schema, array $row, ?array $before = null): ?string;
}//end interface
