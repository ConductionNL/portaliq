<?php

/**
 * Contract of AuditTrailService
 *
 * The public surface of AuditTrailService that other components call, so they depend
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

use DateTime;
use OCA\Portaliq\Kernel\AppIdentity;
use Throwable;

/**
 * Contract of AuditTrailService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AuditTrailServiceInterface {

	/**
	 * Write one fact as an audit-trail row with a given uuid and time. The
	 * repair step uses it to move an old record with its own uuid and time.
	 *
	 * @param array<string, mixed> $fact The fact fields (see record()).
	 * @param string $uuid The row's uuid.
	 * @param DateTime $created When the fact happened.
	 *
	 * @return void
	 *
	 * @throws Throwable When OpenRegister is absent or the insert fails.
	 *
	 * @spec openspec/changes/archive/2026-09-30-consume-or-audit-trail-proof-records/tasks.md#T01
	 */
	public function append(array $fact, string $uuid, DateTime $created): void;

	/**
	 * Whether the audit trail already holds a row with this uuid.
	 *
	 * @param string $uuid The row uuid.
	 *
	 * @return bool
	 *
	 * @throws Throwable When OpenRegister is absent.
	 *
	 * @spec openspec/changes/archive/2026-09-30-consume-or-audit-trail-proof-records/tasks.md#T02
	 */
	public function has(string $uuid): bool;

	/**
	 * Record one audit fact. NEVER throws.
	 *
	 * @param string $verb One of self::VERBS.
	 * @param string $subjectRef The subject the event belongs to.
	 * @param string $organisation The subject's tenant.
	 * @param string $register The target register (or the app id of a forwarded action).
	 * @param string $schema The target schema (or the action id).
	 * @param string $id The target object id (may be empty for session events).
	 * @param string $jti The acting session's token id, when known.
	 * @param string $appId The contributing app id recording the entry.
	 * @param array<string, string> $detail What changed, when the verb needs it
	 *                                      (an `audience` row: `from` and `to`).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-30-consume-or-audit-trail-proof-records/tasks.md#T01
	 * @spec openspec/specs/portal-identity-space/spec.md
	 */
	public function record(
		string $verb,
		string $subjectRef,
		string $organisation,
		string $register,
		string $schema,
		string $id,
		string $jti = '',
		string $appId = AppIdentity::APP_ID,
		array $detail = [],
	): void;
}//end interface
