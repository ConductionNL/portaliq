<?php

/**
 * Contract of MailLog
 *
 * The public surface of MailLog that other components call, so they depend
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
 * Contract of MailLog.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MailLogInterface {

	/**
	 * Remove the rows older than the retention.
	 *
	 * @param int                    $limit How many rows to read at most.
	 * @param DateTimeImmutable|null $now   The clock.
	 *
	 * @return int How many rows were removed.
	 *
	 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t05
	 */
	public function purgeExpired(int $limit=500, ?DateTimeImmutable $now=null): int;

	/**
	 * Queue a new send of a failed one: a `queued` row that points back at it.
	 *
	 * Only a failed row can be sent again, and only once while a retry waits.
	 * The row keeps no full address, so redelivery rebuilds the mail from the
	 * account the row names.
	 *
	 * @param string $id The failed row's id.
	 *
	 * @return string `queued`, `not_found`, `not_failed` or `error`.
	 *
	 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t04
	 */
	public function queueRetry(string $id): string;
}//end interface
