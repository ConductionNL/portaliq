<?php

/**
 * Portaliq Task Completion Run
 *
 * The state of one portal task answer while `PortalTaskProxyController`
 * relays it: the subject and the task it addresses, the outcome and comment
 * the resident sent, then the answers and uploads relayed and the uuid the
 * seam completed. It never leaves the controller that builds it.
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
 * @spec openspec/specs/portal-task-delivery/spec.md#requirement-mijn-taken-lists-details-and-completes-the-partys-open-tasks
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

/**
 * One task answer in progress.
 *
 * @spec openspec/specs/supplier-portal/spec.md#proof-of-receipt-log-satisfying-the-wmebv-burden-of-proof
 */
final class TaskCompletionRun {

	/**
	 * The outcome ('' keeps the seam's default).
	 *
	 * @var string
	 */
	public string $outcome = '';

	/**
	 * The resident's comment.
	 *
	 * @var string|null
	 */
	public ?string $comment = null;

	/**
	 * The submitted answers.
	 *
	 * @var array<string, mixed>
	 */
	public array $answers = [];

	/**
	 * The relayed uploads.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $files = [];

	/**
	 * The uuid the seam completed, else the addressed one.
	 *
	 * @var string
	 */
	public string $taskUuid = '';

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $subject The resolved bearer subject.
	 * @param string               $uuid    The task uuid the resident addressed.
	 */
	public function __construct(
		public readonly array $subject,
		public readonly string $uuid,
	) {
	}//end __construct()
}//end class
