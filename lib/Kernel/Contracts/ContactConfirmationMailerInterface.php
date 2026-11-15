<?php

/**
 * Contract of ContactConfirmationMailer
 *
 * The public surface of ContactConfirmationMailer that other components call, so they depend
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
 * Contract of ContactConfirmationMailer.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContactConfirmationMailerInterface {

	/**
	 * Send the confirmation when the created action asks for one.
	 *
	 * Never throws: the question is already stored, so a failed mail is a log
	 * line, not a failed request.
	 *
	 * @param array<string, mixed> $subject The resolved subject (`subjectRef`, `organisation`).
	 * @param array<string, mixed> $action  The matched create action.
	 * @param array<string, mixed> $data    The values just stored.
	 *
	 * @return bool True when a mail went out.
	 *
	 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t02
	 */
	public function afterCreate(array $subject, array $action, array $data): bool;
}//end interface
