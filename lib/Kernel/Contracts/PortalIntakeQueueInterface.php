<?php

/**
 * Contract of PortalIntakeQueue
 *
 * The public surface of PortalIntakeQueue that other components call, so they depend
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
 * Contract of PortalIntakeQueue.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalIntakeQueueInterface {

	/**
	 * A submission whose case exists.
	 */
	public const STATE_REGISTERED = 'registered';

	/**
	 * What the reference page may say about a submission.
	 *
	 * @param string $reference The reference the citizen was given.
	 * @param string $portal The portal, re-checked against the row.
	 *
	 * @return array<string, mixed>|null The state, and the reason when it
	 *         failed. Null when no submission carries that reference.
	 *
	 * @spec openspec/specs/portal-intake-form/spec.md
	 * @spec openspec/specs/portal-intake-form/spec.md#requirement-a-woo-request-form-is-delivered-to-opencatalogis-intake
	 */
	public function status(string $reference, string $portal): ?array;
}//end interface
