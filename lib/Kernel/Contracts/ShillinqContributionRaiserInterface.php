<?php

/**
 * Contract of ShillinqContributionRaiser
 *
 * The public surface of ShillinqContributionRaiser that other components call, so they depend
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
 * Contract of ShillinqContributionRaiser.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ShillinqContributionRaiserInterface {

	/**
	 * Shillinq is not installed or does not ship the raise.
	 */
	public const ERROR_UNAVAILABLE = 'shillinq_unavailable';

	/**
	 * Shillinq refused the charge as a whole (its 400).
	 */
	public const ERROR_INVALID = 'invalid_charge';

	/**
	 * Shillinq refused the staff member (its 403).
	 */
	public const ERROR_FORBIDDEN = 'forbidden';

	/**
	 * Any other failure.
	 */
	public const ERROR_FAILED = 'raise_failed';
}//end interface
