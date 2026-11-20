<?php

/**
 * Contract of PortalFormAvailability
 *
 * The public surface of PortalFormAvailability that other components call, so they depend
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
 * Contract of PortalFormAvailability.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalFormAvailabilityInterface {

	/**
	 * Why the form is not available now, or null when it takes submissions.
	 *
	 * Checked in this order: replaced, not yet open, closed, maintenance,
	 * full. Only the limit asks OpenRegister anything.
	 *
	 * @param array<string, mixed>   $binding The binding.
	 * @param DateTimeImmutable|null $now     The moment to judge; now when null.
	 *
	 * @return array<string, mixed>|null `situation` and what its page shows.
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-form-opens-and-closes-on-set-dates-and-after-a-set-number-of-submissions-req-fgv-001
	 */
	public function unavailable(array $binding, ?DateTimeImmutable $now = null): ?array;
}//end interface
