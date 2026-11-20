<?php

/**
 * Contract of RowActionResolver
 *
 * The public surface of RowActionResolver that other components call, so they depend
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
 * Contract of RowActionResolver.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RowActionResolverInterface {

	/**
	 * Whether a row matches an action's `rowWhen`. An action without one
	 * applies to every row; a field the row does not carry never matches.
	 *
	 * @param array<string, mixed> $action The endpoint row action.
	 * @param array<string, mixed> $row The row, already read under the subject's scope.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-row-action-must-be-offered-only-on-the-rows-its-rowwhen-names
	 */
	public function rowMatches(array $action, array $row): bool;

	/**
	 * Whether an action is an endpoint row action: an instance-local endpoint,
	 * a type with no surface of its own, a well-formed `rowField`, and a
	 * `rowWhen` that is absent or well-formed.
	 *
	 * @param array<string, mixed> $action The sanitised action.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-row-scoped-forward-must-prove-the-row-before-it-forwards
	 */
	public function isEndpointRowAction(array $action): bool;
}//end interface
