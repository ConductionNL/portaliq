<?php

/**
 * Contract of PortalUserDisplayNames
 *
 * The public surface of PortalUserDisplayNames that other components call, so they depend
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
 * Contract of PortalUserDisplayNames.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalUserDisplayNamesInterface {

	/**
	 * One row with its user fields read as display names.
	 *
	 * @param array<string, mixed> $row        The row to answer.
	 * @param array<string, mixed> $collection The normalised collection.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-column-may-show-a-nextcloud-user-by-name
	 */
	public function row(array $row, array $collection): array;

	/**
	 * Every row with its user fields read as display names.
	 *
	 * @param array<int, mixed>    $rows       The rows to answer.
	 * @param array<string, mixed> $collection The normalised collection.
	 *
	 * @return array<int, mixed> The rows, in the same order.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-column-may-show-a-nextcloud-user-by-name
	 */
	public function rows(array $rows, array $collection): array;
}//end interface
