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
	 * @param PortalIntakeQueue $queue Records and reports on submissions.
	 * @param FormConfirmationMailer|null $confirmationMail Mails the resident the reference and a summary.
	 */
	public function __construct(
		private readonly PortalIntakeQueue $queue,
		private readonly ?FormConfirmationMailer $confirmationMail = null,
	) {
	}//end __construct()

	/**
	 * Mail the confirmation when the form asks for it, and record the outcome.
	 *
	 * @param array<string, mixed> $site      The portal.
	 * @param array<string, mixed> $render    What the binding renders to.
	 * @param array<string, mixed> $answers   The accepted answers.
	 * @param string               $reference The submission's reference.
	 *
	 * @return string The address the mail went to, or '' when none went.
	 *
	 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t05
	 */
	public function send(array $site, array $render, array $answers, string $reference): string {
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
			summary: (new FormConfirmationSummary())->build(fields: $fields, answers: $answers)
		);
		$this->queue->markConfirmationMail(reference: $reference, portal: (string)($site['slug'] ?? ''), state: $this->mailState(sent: $sent));
		if ($sent === true) {
			return $email;
		}

		return '';
	}//end send()

	/**
	 * The state word recorded for a mail.
	 *
	 * @param bool $sent Whether the mail server took it.
	 *
	 * @return string
	 */
	private function mailState(bool $sent): string {
		if ($sent === true) {
			return 'sent';
		}

		return 'failed';
	}//end mailState()
}//end class
