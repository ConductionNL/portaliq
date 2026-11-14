<?php

/**
 * Portaliq Reminder Mailer
 *
 * The shared flow of a mail that reminds one resident of a date: find the
 * address, compose the portal's mail, send it and log the outcome.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Mail
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
 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-the-assignee-gets-a-reminder-before-the-end-date-req-ral-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Mail;

use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalDeepLinkBuilder;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends one reminder mail with a `date` value, in the portal's language and name.
 *
 * A subclass names its template key (`TEMPLATE`), its mail id (`MAIL_ID`),
 * what it mails about (`NOUN`, used in log lines) and its own text.
 *
 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-the-assignee-gets-a-reminder-before-the-end-date-req-ral-003
 */
abstract class ReminderMailer {
	/**
	 * The template key, also the mail log's.
	 */
	public const TEMPLATE = '';

	/**
	 * The id of the mail template.
	 */
	protected const MAIL_ID = '';

	/**
	 * What the mail is about, as it appears in log lines.
	 */
	protected const NOUN = '';

	/**
	 * Constructor.
	 *
	 * @param IMailer               $mailer      Sends the mail.
	 * @param IFactory              $l10nFactory The portal's language.
	 * @param PortalDeepLinkBuilder $deepLinks   The link back to the portal.
	 * @param MailSite              $site        The portal the mail speaks for.
	 * @param PortalAccountService  $accounts    The recipient's address.
	 * @param MailTemplateRenderer  $renderer    The portal's own text, when it has one.
	 * @param MailLog               $mailLog     Records the outcome.
	 * @param LoggerInterface       $logger      Notes a failure, without the address.
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly IFactory $l10nFactory,
		private readonly PortalDeepLinkBuilder $deepLinks,
		private readonly MailSite $site,
		private readonly PortalAccountService $accounts,
		private readonly MailTemplateRenderer $renderer,
		private readonly MailLog $mailLog,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The heading, default subject and default body of the mail.
	 *
	 * @param IL10N                 $l10n   The portal's language.
	 * @param array<string, string> $values The values, `date` already in words.
	 *
	 * @return array{heading: string, subject: string, body: string}
	 *
	 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-the-assignee-gets-a-reminder-before-the-end-date-req-ral-003
	 */
	abstract protected function text(IL10N $l10n, array $values): array;

	/**
	 * Mail one resident; never throws.
	 *
	 * @param string                $subjectRef   The recipient.
	 * @param string                $organisation The tenant.
	 * @param array<string, string> $values       The values, with `date` as `Y-m-d`.
	 *
	 * @return bool Whether a mail left; false for a recipient without a usable address or with a withdrawn account.
	 *
	 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-the-assignee-gets-a-reminder-before-the-end-date-req-ral-003
	 */
	protected function deliver(string $subjectRef, string $organisation, array $values): bool {
		try {
			$email = $this->address(subjectRef: $subjectRef);
			if ($email === '' || $this->mailer->validateMailAddress($email) === false) {
				return false;
			}

			return $this->mail(email: $email, subjectRef: $subjectRef, organisation: $organisation, values: $values);
		} catch (Throwable $failure) {
			$this->logger->warning('Portaliq: '.static::NOUN.' mail not sent', ['exception' => get_class($failure)]);

			return false;
		}
	}//end deliver()

	/**
	 * The recipient's address, or '' when there is none or the account was withdrawn.
	 *
	 * @param string $subjectRef The recipient.
	 *
	 * @return string
	 */
	private function address(string $subjectRef): string {
		$account = $this->accounts->findBySubjectRef(subjectRef: $subjectRef);
		if (($account['status'] ?? '') === PortalAccountService::STATUS_VOID) {
			$this->logger->info('Portaliq: no '.static::NOUN.' mail to a withdrawn account', ['account' => (string)($account['id'] ?? '')]);
			return '';
		}

		return trim((string)($account['email'] ?? ''));
	}//end address()

	/**
	 * Compose, send and log the mail.
	 *
	 * @param string                $email        The address.
	 * @param string                $subjectRef   The recipient.
	 * @param string                $organisation The tenant.
	 * @param array<string, string> $values       The values, with `date`.
	 *
	 * @return bool
	 */
	private function mail(string $email, string $subjectRef, string $organisation, array $values): bool {
		$portal = $this->site->portal(organisation: $organisation);
		$name   = $this->site->name(portal: $portal, organisation: $organisation);
		$slug   = trim((string)($portal['slug'] ?? ''));
		$l10n   = $this->l10nFactory->get(AppIdentity::APP_ID, $this->site->language(portal: $portal));
		$day    = strtotime($values['date']);
		if ($day !== false) {
			$values['date'] = (string)$l10n->l('date', $day, ['width' => 'long']);
		}

		$copy = $this->text(l10n: $l10n, values: $values);
		$text = $this->renderer->render(
			portal: $slug,
			key: static::TEMPLATE,
			values: ['portal' => $name] + $values,
			subject: $copy['subject'],
			body: $copy['body']
		);

		$mail = $this->mailer->createEMailTemplate(static::MAIL_ID, []);
		$mail->setSubject($text['subject']);
		$mail->addHeader();
		$mail->addHeading($copy['heading']);
		$mail->addBodyText($text['body']);
		$mail->addBodyButton($l10n->t('Go to %1$s', [$name]), $this->deepLinks->forSite(portalSlug: $slug, organisation: $organisation));
		$mail->addFooter();

		$message = $this->mailer->createMessage();
		$message->setTo([$email]);
		$message->useTemplate($mail);

		$sent   = (count($this->mailer->send($message)) === 0);
		$status = 'failed';
		if ($sent === true) {
			$status = 'delivered';
		}

		$this->mailLog->record(portal: $slug, templateKey: static::TEMPLATE, email: $email, status: $status, subjectRef: $subjectRef);

		return $sent;
	}//end mail()
}//end class
