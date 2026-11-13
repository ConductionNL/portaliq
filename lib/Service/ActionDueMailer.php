<?php

/**
 * Portaliq Action Due Mailer
 *
 * The mail an assignee gets three days before the end date of an action
 * that is not done.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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

namespace OCA\Portaliq\Service;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Mail\MailLog;
use OCA\Portaliq\Service\Mail\MailSite;
use OCA\Portaliq\Service\Mail\MailTemplateRenderer;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mails the assignee that an action is nearly due (template `action-due`).
 *
 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-the-assignee-gets-a-reminder-before-the-end-date-req-ral-003
 */
class ActionDueMailer {
	/**
	 * The template key, also the mail log's.
	 */
	public const TEMPLATE = 'action-due';

	/**
	 * Constructor.
	 *
	 * @param IMailer               $mailer      Sends the mail.
	 * @param IFactory              $l10nFactory The portal's language.
	 * @param PortalDeepLinkBuilder $deepLinks   The link back to the portal.
	 * @param MailSite              $site        The portal the mail speaks for.
	 * @param PortalAccountService  $accounts    The assignee's address.
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
	 * Mail the assignee about an action near its end date.
	 *
	 * @param string $subjectRef   The assignee.
	 * @param string $organisation The tenant.
	 * @param string $action       The action's title.
	 * @param string $endDate      The end date, `Y-m-d`.
	 *
	 * @return bool Whether a mail left; false for an assignee without a usable address or with a withdrawn account.
	 *
	 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-the-assignee-gets-a-reminder-before-the-end-date-req-ral-003
	 */
	public function send(string $subjectRef, string $organisation, string $action, string $endDate): bool {
		try {
			$email = $this->address(subjectRef: $subjectRef);
			if ($email === '' || $this->mailer->validateMailAddress($email) === false) {
				return false;
			}

			return $this->mail(
				email: $email,
				subjectRef: $subjectRef,
				organisation: $organisation,
				values: ['action' => $action, 'date' => $endDate]
			);
		} catch (Throwable $failure) {
			$this->logger->warning('Portaliq: action due mail not sent', ['exception' => get_class($failure)]);

			return false;
		}
	}//end send()

	/**
	 * The assignee's address, or '' when there is none or the account was withdrawn.
	 *
	 * @param string $subjectRef The assignee.
	 *
	 * @return string
	 */
	private function address(string $subjectRef): string {
		$account = $this->accounts->findBySubjectRef(subjectRef: $subjectRef);
		if (($account['status'] ?? '') === PortalAccountService::STATUS_VOID) {
			$this->logger->info('Portaliq: no action due mail to a withdrawn account', ['account' => (string)($account['id'] ?? '')]);
			return '';
		}

		return trim((string)($account['email'] ?? ''));
	}//end address()

	/**
	 * Compose, send and log the mail.
	 *
	 * @param string                $email        The address.
	 * @param string                $subjectRef   The assignee.
	 * @param string                $organisation The tenant.
	 * @param array<string, string> $values       `action` and `date`.
	 *
	 * @return bool
	 */
	private function mail(string $email, string $subjectRef, string $organisation, array $values): bool {
		$portal = $this->site->portal(organisation: $organisation);
		$name   = $this->site->name(portal: $portal, organisation: $organisation);
		$slug   = trim((string)($portal['slug'] ?? ''));
		$l10n   = $this->l10nFactory->get(Application::APP_ID, $this->site->language(portal: $portal));
		$day    = strtotime($values['date']);
		if ($day !== false) {
			$values['date'] = (string)$l10n->l('date', $day, ['width' => 'long']);
		}

		$heading = $l10n->t('Your action is nearly due');
		$text    = $this->renderer->render(
			portal: $slug,
			key: self::TEMPLATE,
			values: ['portal' => $name] + $values,
			subject: $l10n->t('Your action %1$s is nearly due', [$values['action']]),
			body: $l10n->t('Your action "%1$s" must be done by %2$s.', [$values['action'], $values['date']])
		);

		$mail = $this->mailer->createEMailTemplate('portaliq.action.due', []);
		$mail->setSubject($text['subject']);
		$mail->addHeader();
		$mail->addHeading($heading);
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

		$this->mailLog->record(portal: $slug, templateKey: self::TEMPLATE, email: $email, status: $status, subjectRef: $subjectRef);

		return $sent;
	}//end mail()
}//end class
