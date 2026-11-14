<?php

/**
 * Staff actions that more than one component checks
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Structural constants, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel;

/**
 * The names of the staff actions that services check as well as the controllers that guard them.
 *
 * @spec exclude Structural constants, no functional requirement.
 */
final class StaffAction {

	/**
	 * The action that may measure and record the audit.
	 */
	public const MEASURE_ACCESSIBILITY = 'portal.measure-accessibility';

	/**
	 * The ADR-023 action every method is gated by.
	 */
	public const ANSWER_ACCESS_REQUEST = 'portal.answer-access-request';

	/**
	 * The ADR-023 action both methods are gated by.
	 */
	public const PROVISION = 'portal.provision';
}//end class
