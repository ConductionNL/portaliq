<?php

/**
 * Portaliq Partner Ask Run
 *
 * The state of one ask a handler sends a partner while
 * `PartnerTaskController` raises it: the handler, the partner and the ask
 * itself. It never leaves the controller that builds it.
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
 * @spec openspec/specs/partner-tasks-in-the-portal/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCP\IUser;

/**
 * One ask in progress.
 *
 * @spec openspec/specs/partner-tasks-in-the-portal/spec.md#requirement-a-handler-raises-an-ask-from-the-case-req-ptp-002
 */
final class PartnerAskRun {

	/**
	 * The ask: title, description, due date, upload rules and the case it
	 * hangs on (register, schema, caseId).
	 *
	 * @var array<string, mixed>
	 */
	public array $ask = [];

	/**
	 * Constructor.
	 *
	 * @param IUser                $user    The handler asking.
	 * @param array<string, mixed> $partner `subjectRef`, or `kvk`, `email` and `name`.
	 */
	public function __construct(
		public readonly IUser $user,
		public readonly array $partner,
	) {
	}//end __construct()
}//end class
