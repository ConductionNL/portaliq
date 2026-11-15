<?php

/**
 * Contract of PortalPlanService
 *
 * The public surface of PortalPlanService that other components call, so they depend
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
 * Contract of PortalPlanService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalPlanServiceInterface {

	public const OK = 'ok';

	public const NOT_FOUND = 'not_found';

	public const FORBIDDEN = 'forbidden';

	public const INVALID = 'invalid';

	public const FAILED = 'failed';

	/**
	 * Add an action to a plan. Any participant may.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The plan.
	 * @param array<string, mixed> $data `title`, and optionally `kind`, `endDate`, `description` and `assignee`.
	 *
	 * @return string OK, NOT_FOUND, FORBIDDEN (a done plan), INVALID or FAILED.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
	 */
	public function addAction(array $subject, string $id, array $data): string;

	/**
	 * Add approved contacts of the owner to the plan. Owner only.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The plan.
	 * @param array<int, string> $contactIds The rows of approved contacts.
	 *
	 * @return string OK, NOT_FOUND, FORBIDDEN (not the owner, a done plan, or a person who is no approved contact) or FAILED.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t02
	 */
	public function addParticipants(array $subject, string $id, array $contactIds): string;

	/**
	 * Delete a plan and its actions. Owner only.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The plan.
	 *
	 * @return string OK, NOT_FOUND, FORBIDDEN or FAILED.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t02
	 */
	public function delete(array $subject, string $id): string;

	/**
	 * One plan with its actions and what the viewer may do.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The plan.
	 * @param string $today Today, `Y-m-d`.
	 *
	 * @return array<string, mixed>|null The plan, or null for one the viewer is not part of.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
	 */
	public function detail(array $subject, string $id, string $today): ?array;

	/**
	 * The resident's plans with counts and a card each.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $today Today, `Y-m-d`.
	 *
	 * @return array{plans: array<int, array<string, mixed>>, counts: array<string, int>} The cards, and the plans running, needing action and done.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
	 */
	public function overview(array $subject, string $today): array;

	/**
	 * The plan as rows of a PDF for a participant: goal, end date, who takes
	 * part, each action and the note.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The plan.
	 * @param string $today Today, `Y-m-d`.
	 *
	 * @return array{title: string, columns: array<int, array<string, string>>, rows: array<int, array<string, string>>}|null Null for a stranger.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t06
	 */
	public function pdfRows(array $subject, string $id, string $today): ?array;

	/**
	 * Take a participant off the plan. Owner only; the owner cannot be removed.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The plan.
	 * @param string $ref The participant's subject reference.
	 *
	 * @return string OK, NOT_FOUND, FORBIDDEN or FAILED.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t02
	 */
	public function removeParticipant(array $subject, string $id, string $ref): string;

	/**
	 * Start a plan, empty or from a template, with some of the owner's approved contacts.
	 *
	 * @param array<string, mixed> $subject The session subject, who becomes the owner.
	 * @param string $portal The portal's slug, for the templates.
	 * @param string $templateId A published template, or '' for an empty plan.
	 * @param string $title The name; the template's when empty.
	 * @param array<int, string> $contactIds The rows of approved contacts to add.
	 * @param string $today Today, `Y-m-d`.
	 *
	 * @return array{status: string, id: string} FORBIDDEN names a participant who is no approved contact.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
	 */
	public function start(array $subject, string $portal, string $templateId, string $title, array $contactIds, string $today): array;

	/**
	 * The templates a resident may start from: the published ones of the portal.
	 *
	 * @param string $portal The portal's slug.
	 *
	 * @return array<int, array<string, mixed>> `{id, title, summary, durationDays, actionCount}` each.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t03
	 */
	public function templates(string $portal): array;

	/**
	 * Change a plan. A participant changes the goal, its explanation and the
	 * note; the owner also the title, the end date and whether it is done.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The plan.
	 * @param array<string, mixed> $changes What to change.
	 * @param string $today Today, `Y-m-d`.
	 *
	 * @return string OK, NOT_FOUND, FORBIDDEN (an owner-only field, or a done plan), INVALID or FAILED.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t02
	 */
	public function update(array $subject, string $id, array $changes, string $today): string;

	/**
	 * Change an action of a plan. Any participant may.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The plan.
	 * @param string $actionId The action.
	 * @param array<string, mixed> $data The fields to change.
	 *
	 * @return string OK, NOT_FOUND, FORBIDDEN (a done plan), INVALID or FAILED.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/tasks.md#t04
	 */
	public function updateAction(array $subject, string $id, string $actionId, array $data): string;
}//end interface
