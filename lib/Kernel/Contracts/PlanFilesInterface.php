<?php

/**
 * Contract of PlanFiles
 *
 * The public surface of PlanFiles that other components call, so they depend
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
 * Contract of PlanFiles.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PlanFilesInterface {

	/**
	 * Add a file to a plan, or a new version of a file it holds.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The plan.
	 * @param array<string, mixed>|null $uploaded The entry from IRequest::getUploadedFile().
	 * @param string $previousVersion The file this one is a new version of, or ''.
	 * @param string $now The moment, ISO 8601; now when empty.
	 *
	 * @return string OK, NOT_FOUND, FORBIDDEN (a done plan), INVALID (no file, or not the newest version) or FAILED.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md#requirement-participants-share-the-goal-actions-note-and-file-versions-req-spl-003
	 */
	public function add(array $subject, string $id, ?array $uploaded, string $previousVersion='', string $now=''): string;

	/**
	 * Open one file of a plan.
	 *
	 * @param array<string, mixed> $subject The session subject.
	 * @param string $id The plan.
	 * @param string $fileId The file.
	 *
	 * @return StreamResponse|null Null for a stranger, a file the plan does not list, or a file that cannot be read.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md#requirement-participants-share-the-goal-actions-note-and-file-versions-req-spl-003
	 */
	public function open(array $subject, string $id, string $fileId): ?StreamResponse;
}//end interface
