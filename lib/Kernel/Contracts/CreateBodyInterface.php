<?php

/**
 * Contract of CreateBody
 *
 * The public surface of CreateBody that other components call, so they depend
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
 * Contract of CreateBody.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CreateBodyInterface {

	/**
	 * The whitelisted body with the action's defaults stamped over it.
	 *
	 * The field the action names in `createdAtField` is stamped with the
	 * moment of the create, over anything the client sent.
	 *
	 * @param array<string, mixed>   $action      The create action.
	 * @param array<string, mixed>   $whitelisted The whitelisted client body.
	 * @param DateTimeImmutable|null $now         The moment; now when null.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-10-07-claim-scoped-create-stamps-the-claim/tasks.md#T1
	 * @spec openspec/changes/a-create-stamps-the-moment-it-was-made/specs/portal-contribution-contract/spec.md#requirement-a-create-action-may-name-the-field-that-holds-when-it-was-made
	 */
	public function build(array $action, array $whitelisted, ?DateTimeImmutable $now = null): array;
}//end interface
