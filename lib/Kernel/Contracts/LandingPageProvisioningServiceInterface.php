<?php

/**
 * Contract of LandingPageProvisioningService
 *
 * The public surface of LandingPageProvisioningService that other components call, so they depend
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

use OCA\Portaliq\Event\LandingPageRequestedEvent;

/**
 * Contract of LandingPageProvisioningService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LandingPageProvisioningServiceInterface {

	/**
	 * Validate and provision the request, writing the outcome onto the
	 * event's own result slot. Never throws — every failure mode is
	 * expressed through `error`/`handled`, per the fail-closed contract
	 * (no partial write plan: either both objects are created, or neither
	 * is).
	 *
	 * @param LandingPageRequestedEvent $event The request event (mutated in place).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-requests-fail-closed-with-a-machine-readable-error-and-no-partial-write-plan
	 */
	public function provision(LandingPageRequestedEvent $event): void;
}//end interface
