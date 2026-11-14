<?php

/**
 * Contract of RowIdentifier
 *
 * The public surface of RowIdentifier that other components call, so they depend
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
 * Contract of RowIdentifier.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RowIdentifierInterface {

	/**
	 * The row's own identifier, else the path id it was read by.
	 *
	 * @param array<string, mixed> $row The proven row.
	 * @param string $fallback The path id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-row-scoped-forward-must-prove-the-row-before-it-forwards
	 */
	public function idFor(array $row, string $fallback): string;
}//end interface
