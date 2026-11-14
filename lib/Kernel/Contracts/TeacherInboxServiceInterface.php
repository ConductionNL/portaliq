<?php

/**
 * Contract of TeacherInboxService
 *
 * The public surface of TeacherInboxService that other components call, so they depend
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
 * Contract of TeacherInboxService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TeacherInboxServiceInterface {

	/**
	 * Build the staff member's own inbox, bucketed by group.
	 *
	 * @param string $staffRef The calling staff member's own subjectRef.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Bucket key => threads (each carrying `unreadCount`).
	 *
	 * @spec openspec/specs/guardian-direct-messaging/spec.md#requirement-a-staff-members-inbox-is-bucketed-by-group-with-an-unread-count
	 */
	public function inboxFor(string $staffRef): array;
}//end interface
