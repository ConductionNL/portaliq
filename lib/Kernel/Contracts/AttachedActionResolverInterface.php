<?php

/**
 * Contract of AttachedActionResolver
 *
 * The public surface of AttachedActionResolver that other components call, so they depend
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
 * Contract of AttachedActionResolver.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AttachedActionResolverInterface {

	/**
	 * The attaching action a forward names, when the collection lists it and
	 * its own app still offers it; else null.
	 *
	 * @param array<int, array<string, mixed>> $contributions The resolved contributions.
	 * @param string                           $collectionApp The app that owns the collection.
	 * @param array<string, mixed>             $collection    The target collection.
	 * @param string                           $actionApp     The app of the action.
	 * @param string                           $actionId      The action id.
	 *
	 * @return array<string, mixed>|null The action as its own app declares it.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-an-endpoint-action-must-be-able-to-attach-to-another-apps-collection-req-wje-004
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-an-attached-action-must-be-able-to-name-one-collection-and-its-own-app-req-ato-001
	 */
	public function attachedAction(array $contributions, string $collectionApp, array $collection, string $actionApp, string $actionId): ?array;
}//end interface
