<?php

/**
 * Contract of PendingPushService
 *
 * The public surface of PendingPushService that other components call, so they depend
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
 * Contract of PendingPushService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PendingPushServiceInterface {

	/**
	 * Deliver every not-yet-delivered row whose `deliverAfter` is at or
	 * before `$now`, and leave every not-yet-due row untouched.
	 *
	 * @param DateTimeImmutable $now The run time.
	 *
	 * @return int The number of rows delivered.
	 *
	 * @spec openspec/specs/guardian-push-notifications/spec.md#requirement-a-due-deferred-push-is-delivered-by-the-background-job-an-undue-one-is-left-alone
	 */
	public function deliverDue(DateTimeImmutable $now): int;
}//end interface
