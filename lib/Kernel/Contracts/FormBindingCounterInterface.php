<?php

/**
 * Contract of FormBindingCounter
 *
 * The public surface of FormBindingCounter that other components call, so they depend
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
 * Contract of FormBindingCounter.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FormBindingCounterInterface {

	/**
	 * The counter of the binding with this id.
	 *
	 * @param string                 $id  The binding's id.
	 * @param DateTimeImmutable|null $now The moment to judge; now when null.
	 *
	 * @return array{found: bool, limited: bool, count: int|null, limit: int|null, per: string|null}
	 *         `found` false for an unknown or unreadable binding; `limited`
	 *         false when the binding sets no usable limit.
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#scenario-the-admin-sees-the-counter
	 */
	public function forBinding(string $id, ?DateTimeImmutable $now = null): array;
}//end interface
