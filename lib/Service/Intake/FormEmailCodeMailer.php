<?php

/**
 * Portaliq Form Email Code Mailer
 *
 * Mails the six-digit code that proves an address is the resident's.
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
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Mail\MailTemplateRenderer;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The template `form-email-code`: a portal may write its own text for it.
 *
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
 */
class FormEmailCodeMailer {
	private const DEFAULT_LANGUAGE = 'nl';

	/**
	 * Constructor.
	 *
	 * @param IMailer $mailer Sends the mail.
	 * @param IFactory $l10nFactory Gives the portal's language.
	 * @param LoggerInterface $logger Logs a mail that was not sent.
	 * @param MailTemplateRenderer|null $renderer Swaps in a portal's own text.
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly IFactory $l10nFactory,
		private readonly LoggerInterface $logger,
		private readonly ?MailTemplateRenderer $renderer=null,
	) {
	}//end __construct()

	/**
	 * Send the code.
	 *
	 * @param string $email The address.
	 * @param string $code The six digits.
	 * @param array<string, mixed> $site The portal.
	 *
	 * @return bool True when the mail server took it.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
	 */
	public function send(string $email, string $code, array $site): bool {
		try {
			$l10n = $this->l10nFor(site: $site);
			$text = [
				'subject' => $l10n->t('Your code to check your e-mail address'),
				'body'    => $l10n->t('Your code is %1$s. It works for 15 minutes.', [$code]),
			];
			if ($this->renderer !== null) {
				$text = $this->renderer->render(
					portal: trim((string)($site['slug'] ?? '')),
					key: 'form-email-code',
					values: ['portal' => trim((string)($site['title'] ?? '')), 'code' => $code],
					subject: $text['subject'],
					body: $text['body']
				);
			}

			$mail = $this->mailer->createEMailTemplate('portaliq.form.emailcode', []);
			$mail->setSubject($text['subject']);
			$mail->addHeader();
			$mail->addHeading($l10n->t('Check your e-mail address'));
			$mail->addBodyText($text['body']);
			$mail->addBodyText($l10n->t('Did you not ask for this code? Then you can ignore this mail.'));
			$mail->addFooter();

			$message = $this->mailer->createMessage();
			$message->setTo([$email]);
			$message->useTemplate($mail);

			return (count($this->mailer->send($message)) === 0);
		} catch (Throwable $failure) {
			// The exception's own message is left out: a transport error may quote the recipient.
			$this->logger->warning('Portaliq: form e-mail code not sent', ['exception' => get_class($failure), 'app' => Application::APP_ID]);

			return false;
		}
	}//end send()

	/**
	 * The portal's language.
	 *
	 * @param array<string, mixed> $site The portal.
	 *
	 * @return IL10N
	 */
	private function l10nFor(array $site): IL10N {
		$locales  = (array)($site['locales'] ?? []);
		$language = self::DEFAULT_LANGUAGE;
		if (is_string($locales[0] ?? null) === true && $locales[0] !== '') {
			$language = $locales[0];
		}

		return $this->l10nFactory->get(Application::APP_ID, $language);
	}//end l10nFor()
}//end class
