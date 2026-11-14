<?php

/**
 * Contract of PortalActionService
 *
 * The public surface of PortalActionService that other components call, so they depend
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

use OCP\AppFramework\Http\StreamResponse;

/**
 * Contract of PortalActionService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalActionServiceInterface {

	/**
	 * Keep one file with an action, replacing the one it had.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The action.
	 * @param array<string, mixed>|null $uploaded The multipart entry.
	 * @param string $planId The plan the action is in, for a participant.
	 *
	 * @return string OK, NOT_FOUND, FORBIDDEN, INVALID (not a PDF, JPG or PNG up to 10 MB) or FAILED.
	 *
	 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-a-resident-keeps-a-list-of-own-actions-req-ral-001
	 */
	public function attach(array $subject, string $id, ?array $uploaded, string $planId=''): string;

	/**
	 * Add an action to the resident's own list.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param array<string, mixed> $data `title`, and optionally `description`, `kind`, `status`, `endDate` and `assignee`.
	 *
	 * @return array{status: string, id: string} OK with the new id, INVALID or FAILED.
	 *
	 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-a-resident-keeps-a-list-of-own-actions-req-ral-001
	 */
	public function create(array $subject, array $data): array;

	/**
	 * Delete an action: its owner, or in a plan also the plan's owner.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The action.
	 * @param string $planId The plan the action is in, for a participant.
	 *
	 * @return string OK, NOT_FOUND, FORBIDDEN or FAILED.
	 *
	 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-an-action-can-be-shared-with-an-approved-contact-req-ral-002
	 */
	public function delete(array $subject, string $id, string $planId=''): string;

	/**
	 * The action's file, or null for a stranger or an action without one.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The action.
	 * @param string $planId The plan the action is in, for a participant.
	 *
	 * @return StreamResponse|null
	 *
	 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-a-resident-keeps-a-list-of-own-actions-req-ral-001
	 */
	public function file(array $subject, string $id, string $planId=''): ?StreamResponse;

	/**
	 * The history of an action, newest first, or null for a stranger.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The action.
	 * @param string $planId The plan the action is in, for a participant.
	 *
	 * @return array<int, array<string, string>>|null The lines.
	 *
	 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-an-action-can-be-shared-with-an-approved-contact-req-ral-002
	 */
	public function history(array $subject, string $id, string $planId=''): ?array;

	/**
	 * The resident's actions, with and without a plan, the count done, and
	 * the people a new action can be given to.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 *
	 * @return array{actions: array<int, array<string, mixed>>, done: int, total: int, assignees: array<int, array{ref: string, displayName: string}>}
	 *
	 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-a-resident-keeps-a-list-of-own-actions-req-ral-001
	 */
	public function overview(array $subject): array;

	/**
	 * Change an action, within what the viewer may change.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The action.
	 * @param array<string, mixed> $data The fields to change; `file` only as '' to take the file off.
	 * @param string $planId The plan the action is in, for a participant who is not its owner or assignee.
	 *
	 * @return string OK, NOT_FOUND, FORBIDDEN, INVALID or FAILED.
	 *
	 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-an-action-can-be-shared-with-an-approved-contact-req-ral-002
	 */
	public function update(array $subject, string $id, array $data, string $planId=''): string;
}//end interface
