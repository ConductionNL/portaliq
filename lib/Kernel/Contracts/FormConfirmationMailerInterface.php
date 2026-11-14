<?php

/**
 * Contract of FormConfirmationMailer
 *
 * The public surface of FormConfirmationMailer that other components call, so they depend
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
 * Contract of FormConfirmationMailer.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FormConfirmationMailerInterface {

	/**
	 * The address in the form's answers: its first e-mail field that holds a valid one.
	 *
	 * @param array<int, array<string, mixed>> $fields  The form's fields.
	 * @param array<string, mixed>             $answers The accepted answers.
	 *
	 * @return string The address, or ''.
	 *
	 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t05
	 */
	public function addressIn(array $fields, array $answers): string;

	/**
	 * Send the confirmation.
	 *
	 * @param string                                       $email     The address.
	 * @param array<string, mixed>                         $site      The portal.
	 * @param string                                       $reference The submission's reference.
	 * @param string                                       $formName  The form's name.
	 * @param array<int, array{label: string, value: string}> $summary   The answers to repeat.
	 * @param array<string, mixed>                         $moments   The destination's confirmation moments: receivedAt, termStartsAt, deadline.
	 *
	 * @return bool True when the mail server took it.
	 *
	 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t05
	 */
	public function send(string $email, array $site, string $reference, string $formName, array $summary, array $moments = []): bool;
}//end interface
