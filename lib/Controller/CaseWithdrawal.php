<?php

/**
 * Portaliq Case Withdrawal
 *
 * What a citizen's withdrawal of their own request writes: the status the
 * case type declares and the reason the applicant gave.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/withdrawing-your-own-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

/**
 * One withdrawal: its target status and reason.
 *
 * @spec openspec/specs/withdrawing-your-own-case/spec.md#requirement-a-withdrawal-is-confirmed-and-may-carry-a-reason-req-woc-003
 */
final class CaseWithdrawal {
	/**
	 * Constructor.
	 *
	 * @param string $status The status the case type declares for a withdrawal.
	 * @param string $reason The reason the applicant gave, or ''.
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $reason,
	) {
	}//end __construct()
}//end class
