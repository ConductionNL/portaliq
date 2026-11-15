<?php

/**
 * Contract of CitizenWriteRecorder
 *
 * The public surface of CitizenWriteRecorder that other components call, so they depend
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
 * Contract of CitizenWriteRecorder.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CitizenWriteRecorderInterface {

	/**
	 * Record the fact in the audit trail and raise the citizen write event.
	 *
	 * Dispatch is typed and happens exactly once, so a rule bound to the event
	 * fires once per act. Nothing else in Portaliq dispatches it, and a staff
	 * write never reaches this class, which is what makes "a staff write is not
	 * a portal write" true by construction rather than by a filter.
	 *
	 * @param string $register The register the case lives in.
	 * @param string $schema The schema the case lives in.
	 * @param string $caseId The case the write landed on.
	 * @param string $act One of the PortalClientWriteEvent ACT_* constants.
	 * @param array<int, string> $fields The field names the act touched.
	 * @param array<string, mixed> $subject The resolved portal session.
	 * @param array<string, mixed> $action The matched update action.
	 * @param string $occurredAt The moment of the write, ISO 8601.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function announce(
		string $register,
		string $schema,
		string $caseId,
		string $act,
		array $fields,
		array $subject,
		array $action,
		string $occurredAt,
	): void;

	/**
	 * Record and announce a withdrawal.
	 *
	 * It is raised as its own event, never as a write: a rule bound to a
	 * citizen's withdrawal must not fire when a handler sets the same status
	 * internally, and this is the path that tells the two apart.
	 *
	 * @param string $register The register the case lives in.
	 * @param string $schema The schema the case lives in.
	 * @param string $caseId The case that was withdrawn.
	 * @param string $status The status it landed on.
	 * @param string $reason What the applicant said, or ''.
	 * @param array<string, mixed> $subject The resolved portal session.
	 * @param array<string, mixed> $action The matched update action.
	 * @param string $occurredAt The moment of the withdrawal, ISO 8601.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/withdrawing-your-own-case/spec.md
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the parameters are the
	 * fact being recorded; folding them into an array would lose the types on
	 * an audited boundary.
	 */
	public function announceWithdrawal(
		string $register,
		string $schema,
		string $caseId,
		string $status,
		string $reason,
		array $subject,
		array $action,
		string $occurredAt,
	): void;

	/**
	 * Append one record to whatever the case already carries, dropping
	 * anything that is not a record so a malformed value cannot make the whole
	 * history unreadable.
	 *
	 * @param mixed $existing The case's current record list.
	 * @param array<string, mixed> $record The record to append.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function append(mixed $existing, array $record): array;

	/**
	 * Build the record entry to append to the case.
	 *
	 * @param string $act One of the PortalClientWriteEvent ACT_* constants.
	 * @param array<string, mixed> $subject The resolved portal session.
	 * @param array<string, mixed> $action The matched update action.
	 * @param array<string, array<string, mixed>> $changes Per field, `from` and `to`.
	 * @param string $occurredAt The moment of the write, ISO 8601.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function buildRecord(
		string $act,
		array $subject,
		array $action,
		array $changes,
		string $occurredAt,
	): array;

	/**
	 * The moment a write happened, ISO 8601.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function now(): string;
}//end interface
