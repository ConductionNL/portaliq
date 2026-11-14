<?php

/**
 * Contract of PortalTaskGateway
 *
 * The public surface of PortalTaskGateway that other components call, so they depend
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
 * Contract of PortalTaskGateway.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalTaskGatewayInterface {

	/**
	 * Complete a portal task as the subject, relaying answers, comment,
	 * outcome and the uploaded files as multipart.
	 *
	 * @param array<string, mixed> $subject The resolved bearer subject.
	 * @param string $uuid The task uuid.
	 * @param array<string, mixed> $answers The submitted answer fields.
	 * @param string|null $comment The resident's comment, when any.
	 * @param string $outcome The outcome to record ('' = the seam's default).
	 * @param array<int, array<string, mixed>> $files The uploads, each an
	 *                                                IRequest::getUploadedFile
	 *                                                shape {name, type, tmp_name, size}.
	 *
	 * @return array{status: int, body: array<string, mixed>}|null The relayed
	 *         answer, or null on transport failure / an unmintable assertion.
	 *
	 * @spec openspec/specs/portal-task-delivery/spec.md#requirement-the-task-proxy-is-the-only-path-and-the-assertion-never-reaches-the-browser
	 */
	public function completeTask(
		array $subject,
		string $uuid,
		array $answers = [],
		?string $comment = null,
		string $outcome = '',
		array $files = [],
	): ?array;

	/**
	 * One portal task, if it is the subject's.
	 *
	 * @param array<string, mixed> $subject The resolved bearer subject.
	 * @param string $uuid The task uuid.
	 *
	 * @return array{status: int, body: array<string, mixed>}|null The relayed
	 *         answer, or null on transport failure / an unmintable assertion.
	 *
	 * @spec openspec/specs/portal-task-delivery/spec.md#requirement-the-task-proxy-is-the-only-path-and-the-assertion-never-reaches-the-browser
	 */
	public function getTask(array $subject, string $uuid): ?array;

	/**
	 * The subject's open portal tasks, paged.
	 *
	 * @param array<string, mixed> $subject The resolved bearer subject.
	 * @param int $limit Page size.
	 * @param int $offset Page offset.
	 *
	 * @return array{status: int, body: array<string, mixed>}|null The relayed
	 *         answer, or null on transport failure / an unmintable assertion.
	 *
	 * @spec openspec/specs/portal-task-delivery/spec.md#requirement-the-task-proxy-is-the-only-path-and-the-assertion-never-reaches-the-browser
	 */
	public function listTasks(array $subject, int $limit = 25, int $offset = 0): ?array;

	/**
	 * Whether the task seam can be reached at all: openregister is installed,
	 * the dedicated signing secret the assertion needs is configured, AND the
	 * route table knows the seam. Drives the `tasks: {enabled}` announcement
	 * on the contributions aggregate — the SPA never shows a "Mijn taken"
	 * entry that can only 502.
	 *
	 * 🔴 THE ROUTE PROBE IS PART OF THE GATE. An openregister that is installed
	 * but pre-dates the portal-task routes makes every list/detail/complete
	 * relay degrade to null (see forward()); without this probe the tile would
	 * be announced and then fail on every click (review of WOO-568).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-task-delivery/spec.md#requirement-mijn-taken-lists-details-and-completes-the-partys-open-tasks
	 */
	public function isAvailable(): bool;
}//end interface
