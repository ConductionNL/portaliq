<?php

/**
 * Portaliq Form Cosign Mailer
 *
 * Mails the co-signer the invitation with its code and deadline, and tells the
 * submitter when the co-signer refused or the deadline passed.
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
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use DateTimeImmutable;
use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Service\Mail\MailTemplateRenderer;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The templates `form-cosign-invite` and `form-cosign-ended`: a portal may write its own text for them.
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
class FormCosignMailer {
	private const DEFAULT_LANGUAGE = 'nl';

	/**
	 * Constructor.
	 *
	 * @param IMailer                   $mailer      Sends the mail.
	 * @param IFactory                  $l10nFactory Gives the portal's language.
	 * @param LoggerInterface           $logger      Logs a mail that was not sent.
	 * @param MailTemplateRenderer|null $renderer    Swaps in a portal's own text.
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly IFactory $l10nFactory,
		private readonly LoggerInterface $logger,
		private readonly ?MailTemplateRenderer $renderer=null,
	) {
	}//end __construct()

	/**
	 * Invite the co-signer: the code, the deadline and where to co-sign.
	 *
	 * @param string               $email    The co-signer's address.
	 * @param string               $code     The six-character code in two groups.
	 * @param string               $deadline The deadline, ISO 8601.
	 * @param string               $formName The form's name.
	 * @param array<string, mixed> $site     The portal.
	 *
	 * @return bool True when the mail server took it.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function invite(string $email, string $code, string $deadline, string $formName, array $site): bool {
		$l10n = $this->l10nFor(site: $site);
		$day  = self::dayOf(value: $deadline);
		$text = $this->text(
			site: $site,
			key: 'form-cosign-invite',
			values: ['portal' => trim((string)($site['title'] ?? '')), 'code' => $code, 'deadline' => $day, 'formName' => $formName],
			default: [
				'subject' => $l10n->t('Would you co-sign a request?'),
				'body' => $l10n->t('You are asked to co-sign the request "%1$s". Your code is %2$s. Do this before %3$s.', [$formName, $code, $day]),
			]
		);

		return $this->deliver(
			email: $email,
			text: $text,
			heading: $l10n->t('Co-sign a request'),
			lines: [$l10n->t('Go to the co-sign page of the portal, enter the code and sign in with DigiD. You can read the request before you decide.')],
			template: 'portaliq.form.cosigninvite'
		);
	}//end invite()

	/**
	 * Tell the submitter the request was not co-signed and is not delivered.
	 *
	 * @param string               $email     The submitter's address.
	 * @param string               $reference The request's reference.
	 * @param string               $state     Why: `refused` or `expired`.
	 * @param array<string, mixed> $site      The portal.
	 *
	 * @return bool True when the mail server took it.
	 *
	 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
	 */
	public function ended(string $email, string $reference, string $state, array $site): bool {
		$l10n = $this->l10nFor(site: $site);
		$body = $l10n->t('Your request %1$s was not co-signed before the deadline, so it was not sent.', [$reference]);
		if ($state === PortalCosign::REFUSED) {
			$body = $l10n->t('The co-signer refused your request %1$s, so it was not sent.', [$reference]);
		}

		$text = $this->text(
			site: $site,
			key: 'form-cosign-ended',
			values: ['portal' => trim((string)($site['title'] ?? '')), 'reference' => $reference],
			default: ['subject' => $l10n->t('Your request was not co-signed'), 'body' => $body]
		);

		return $this->deliver(
			email: $email,
			text: $text,
			heading: $l10n->t('Not co-signed'),
			lines: [$l10n->t('You can send the request again with another co-signer.')],
			template: 'portaliq.form.cosignended'
		);
	}//end ended()

	/**
	 * The subject and body: the portal's own text when it wrote one, else the default.
	 *
	 * @param array<string, mixed>                 $site    The portal.
	 * @param string                               $key     The template key.
	 * @param array<string, string>                $values  The template's variables.
	 * @param array{subject: string, body: string} $default The default text.
	 *
	 * @return array{subject: string, body: string}
	 */
	private function text(array $site, string $key, array $values, array $default): array {
		if ($this->renderer === null) {
			return $default;
		}

		return $this->renderer->render(
			portal: trim((string)($site['slug'] ?? '')),
			key: $key,
			values: $values,
			subject: $default['subject'],
			body: $default['body']
		);
	}//end text()

	/**
	 * Write and send one mail; a failure is logged without the recipient.
	 *
	 * @param string                               $email    The address.
	 * @param array{subject: string, body: string} $text     The subject and body.
	 * @param string                               $heading  The heading.
	 * @param array<int, string>                   $lines    More lines under the body.
	 * @param string                               $template The mail template id.
	 *
	 * @return bool True when the mail server took it.
	 */
	private function deliver(string $email, array $text, string $heading, array $lines, string $template): bool {
		try {
			$mail = $this->mailer->createEMailTemplate($template, []);
			$mail->setSubject($text['subject']);
			$mail->addHeader();
			$mail->addHeading($heading);
			$mail->addBodyText($text['body']);
			foreach ($lines as $line) {
				$mail->addBodyText($line);
			}

			$mail->addFooter();

			$message = $this->mailer->createMessage();
			$message->setTo([$email]);
			$message->useTemplate($mail);

			return (count($this->mailer->send($message)) === 0);
		} catch (Throwable $failure) {
			// The exception's own message is left out: a transport error may quote the recipient.
			$this->logger->warning('Portaliq: co-sign mail not sent', ['exception' => get_class($failure), 'app' => AppIdentity::APP_ID]);

			return false;
		}
	}//end deliver()

	/**
	 * The day of an ISO 8601 moment, `Y-m-d`; the value itself when it does not parse.
	 *
	 * @param string $value The moment.
	 *
	 * @return string
	 */
	private static function dayOf(string $value): string {
		try {
			return (new DateTimeImmutable($value))->format('Y-m-d');
		} catch (Throwable) {
			return $value;
		}
	}//end dayOf()

	/**
	 * The portal's first language, or Dutch.
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

		return $this->l10nFactory->get(AppIdentity::APP_ID, $language);
	}//end l10nFor()
}//end class
