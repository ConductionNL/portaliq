<?php

/**
 * Portaliq Intake Confirmation (form-statements-intro-and-confirmation-mail)
 *
 * Mails the resident the reference and a summary of a submission.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Intake
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
 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

/**
 * Sends the confirmation mail of a submission and records how it went.
 *
 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md
 */
class PortalIntakeConfirmation {
	/**
	 * Constructor.
	 *
	 * @param FormConfirmationMailer|null $confirmationMail Mails the resident the reference and a summary.
	 */
	public function __construct(
		private readonly ?FormConfirmationMailer $confirmationMail = null,
	) {
	}//end __construct()

	/**
	 * Mail the confirmation when the form asks for it. The mailer logs the
	 * outcome; there is no portal record to write it on any more (decision 179).
	 *
	 * @param array<string, mixed> $site      The portal.
	 * @param array<string, mixed> $render    What the binding renders to.
	 * @param array<string, mixed> $answers   The accepted answers.
	 * @param string               $reference The reference of the object the request created.
	 * @param array<string, mixed> $moments   The destination's confirmation properties (receivedAt, termStartsAt, deadline).
	 *
	 * @return string The address the mail went to, or '' when none went.
	 *
	 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t05
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#2.4
	 */
	public function send(array $site, array $render, array $answers, string $reference, array $moments = []): string {
		if (($render['settings']['confirmationMail'] ?? false) !== true || $this->confirmationMail === null) {
			return '';
		}

		$fields = (array)($render['fields'] ?? []);
		$email  = $this->confirmationMail->addressIn(fields: $fields, answers: $answers);
		if ($email === '') {
			return '';
		}

		$sent = $this->confirmationMail->send(
			email: $email,
			site: $site,
			reference: $reference,
			formName: (string)($render['formName'] ?? ''),
			summary: (new FormConfirmationSummary())->build(fields: $fields, answers: $answers),
			moments: $moments
		);
		if ($sent === true) {
			return $email;
		}

		return '';
	}//end send()
}//end class
