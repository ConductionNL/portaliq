<?php

/**
 * Portaliq Form Confirmation Mailer (form-statements-intro-and-confirmation-mail)
 *
 * @category Intake
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
 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t05
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCA\Portaliq\AppInfo\Application;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mails the resident the reference of a submitted form and a summary of the
 * answers.
 *
 * The address is the form's own e-mail answer. A mail is sent once, after the
 * submission has its reference, and the caller records whether it went out so
 * a refusal shows as "Bevestiging mislukt". The receipt PDF is not attached:
 * this app has no PDF writer yet.
 *
 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t05
 */
class FormConfirmationMailer {
	private const DEFAULT_LANGUAGE = 'nl';

	/**
	 * Constructor.
	 *
	 * @param IMailer         $mailer      Sends the mail.
	 * @param IFactory        $l10nFactory Gives the portal's language.
	 * @param LoggerInterface $logger      Logs a mail that was not sent.
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly IFactory $l10nFactory,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

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
	public function addressIn(array $fields, array $answers): string {
		foreach ($fields as $field) {
			$name = (string)($field['name'] ?? '');
			if (($field['type'] ?? '') !== 'email' || is_string($answers[$name] ?? null) === false) {
				continue;
			}

			$address = trim($answers[$name]);
			if ($this->mailer->validateMailAddress($address) === true) {
				return $address;
			}
		}

		return '';
	}//end addressIn()

	/**
	 * Send the confirmation.
	 *
	 * @param string                                       $email     The address.
	 * @param array<string, mixed>                         $site      The portal.
	 * @param string                                       $reference The submission's reference.
	 * @param string                                       $formName  The form's name.
	 * @param array<int, array{label: string, value: string}> $summary   The answers to repeat.
	 *
	 * @return bool True when the mail server took it.
	 *
	 * @spec openspec/changes/form-statements-intro-and-confirmation-mail/tasks.md#t05
	 */
	public function send(string $email, array $site, string $reference, string $formName, array $summary): bool {
		try {
			$locales  = (array)($site['locales'] ?? []);
			$language = self::DEFAULT_LANGUAGE;
			if (is_string($locales[0] ?? null) === true && $locales[0] !== '') {
				$language = $locales[0];
			}

			$l10n = $this->l10nFactory->get(Application::APP_ID, $language);

			$mail = $this->mailer->createEMailTemplate('portaliq.form.confirmation', []);
			$mail->setSubject($l10n->t('We have received your request'));
			$mail->addHeader();
			$mail->addHeading($l10n->t('We have received your request'));
			$mail->addBodyText($l10n->t('Your request %1$s has been received under reference %2$s.', [$formName, $reference]));
			foreach ($summary as $line) {
				$mail->addBodyText($line['label'] . ': ' . $line['value']);
			}

			$mail->addFooter();

			$message = $this->mailer->createMessage();
			$message->setTo([$email]);
			$message->useTemplate($mail);

			return count($this->mailer->send($message)) === 0;
		} catch (Throwable $failure) {
			// The exception's own message is left out: a transport error may quote the recipient or the body.
			$this->logger->warning('Portaliq: form confirmation mail not sent', ['exception' => get_class($failure)]);

			return false;
		}
	}//end send()
}//end class
