<?php

/**
 * Contract of PortalRegistrationPolicyService
 *
 * The public surface of PortalRegistrationPolicyService that other components call, so they depend
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
 * Contract of PortalRegistrationPolicyService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalRegistrationPolicyServiceInterface {

	/**
	 * The account waits for a mail the registrant confirms.
	 */
	public const POLICY_ACTIVATION = 'activation';

	/**
	 * What becomes of a registration from this address.
	 *
	 * @param array<string, mixed> $site The portal's own configuration row.
	 * @param string $email The address registering.
	 *
	 * @return array{accepted: bool, reason: string, status: string} The
	 *         decision: `accepted` false carries the refusal in `reason`;
	 *         `accepted` true carries the account status to create in
	 *         `status`, which is `pending` under either policy, because
	 *         neither admits anybody yet.
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function decide(array $site, string $email): array;

	/**
	 * Whether this portal offers registration at all.
	 *
	 * @param array<string, mixed> $site The portal's own configuration row.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function isOffered(array $site): bool;
}//end interface
