<?php

/**
 * Portaliq Contact Confirmation Mailer (contact-page-question-form-and-not-found)
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Identity
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
 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Kernel\Contracts\ConfirmationMailKeysInterface;
use OCA\Portaliq\Kernel\Contracts\ContactConfirmationMailerInterface;
use OCA\Portaliq\Service\Mail\MailLog;
use OCA\Portaliq\Service\Mail\MailSite;
use OCA\Portaliq\Service\Mail\MailTemplateRenderer;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalDeepLinkBuilder;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mails the resident that their question has arrived.
 *
 * The mail names the subject the resident chose and links to the portal. It
 * never repeats the question text: no content in mail, as for every notice.
 * It goes to the account's confirmed address and to nobody else; without one,
 * or for a withdrawn (void) account, nothing is sent and nothing is claimed.
 *
 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t02
 */
class ContactConfirmationMailer implements ContactConfirmationMailerInterface {
	/**
	 * Constructor.
	 *
	 * @param IMailer                       $mailer        Sends the mail.
	 * @param IFactory                      $l10nFactory   Gives the portal's language.
	 * @param PortalDeepLinkBuilder         $deepLinks     Builds the portal link.
	 * @param MailSite                $site       The portal, name and language a mail is sent with.
	 * @param PortalAccountService          $accounts      Reads the account's address.
	 * @param LoggerInterface               $logger        Logs a mail that was not sent.
	 * @param MailTemplateRenderer|null     $renderer      Swaps in a portal's own text.
	 * @param MailLog|null                  $mailLog       Logs each send, with the address masked.
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly IFactory $l10nFactory,
		private readonly PortalDeepLinkBuilder $deepLinks,
		private readonly MailSite $site,
		private readonly PortalAccountService $accounts,
		private readonly LoggerInterface $logger,
		private readonly ?MailTemplateRenderer $renderer=null,
		private readonly ?MailLog $mailLog=null,
	) {
	}//end __construct()

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
	public function afterCreate(array $subject, array $action, array $data): bool {
		if (in_array(($action['confirmationMail'] ?? null), ConfirmationMailKeysInterface::TEMPLATES, true) === false) {
			return false;
		}

		try {
			$email = $this->address(subjectRef: (string)($subject['subjectRef'] ?? ''));
			if ($email === '' || $this->mailer->validateMailAddress($email) === false) {
				return false;
			}

			$organisation = (string)($subject['organisation'] ?? '');

			return $this->send(
				email: $email,
				organisation: $organisation,
				topic: $this->topic(action: $action, data: $data)
			);
		} catch (Throwable $failure) {
			$this->logger->warning('Portaliq: question confirmation not sent', ['exception' => get_class($failure)]);

			return false;
		}
	}//end afterCreate()

	/**
	 * The account's address, or '' when there is none or the account was withdrawn.
	 *
	 * @param string $subjectRef The resident.
	 *
	 * @return string
	 */
	private function address(string $subjectRef): string {
		$account = $this->accounts->findBySubjectRef(subjectRef: $subjectRef);
		if (($account['status'] ?? '') === PortalAccountService::STATUS_VOID) {
			$this->logger->info('Portaliq: no question confirmation to a withdrawn account', ['account' => (string)($account['id'] ?? '')]);
			return '';
		}

		return trim((string)($account['email'] ?? ''));
	}//end address()

	/**
	 * The subject the resident chose, in the words the action gives it.
	 *
	 * @param array<string, mixed> $action The action.
	 * @param array<string, mixed> $data   The stored values.
	 *
	 * @return string The subject, or '' when the action names none.
	 */
	private function topic(array $action, array $data): string {
		$field = (string)($action['topicField'] ?? '');
		if ($field === '' || is_scalar($data[$field] ?? null) === false) {
			return '';
		}

		$value  = (string)$data[$field];
		$labels = ($action['fieldConfigs'][$field]['valueLabels'] ?? null);
		if (is_array($labels) === true && is_string($labels[$value] ?? null) === true) {
			return $labels[$value];
		}

		return $value;
	}//end topic()

	/**
	 * Build and send the mail.
	 *
	 * @param string $email        The confirmed address.
	 * @param string $organisation The organisation slug.
	 * @param string $topic        The chosen subject, or ''.
	 *
	 * @return bool True when the mail server took it.
	 */
	private function send(string $email, string $organisation, string $topic): bool {
		$portal = $this->site->portal(organisation: $organisation);
		$name   = $this->site->name(portal: $portal, organisation: $organisation);
		$link   = $this->deepLinks->forSite(portalSlug: trim((string)($portal['slug'] ?? '')), organisation: $organisation);
		$l10n   = $this->l10nFactory->get(AppIdentity::APP_ID, $this->site->language(portal: $portal));

		$slug = trim((string)($portal['slug'] ?? ''));
		$text = $this->confirmationText(l10n: $l10n, name: $name, topic: $topic, slug: $slug);
		$sent = $this->sendMail(l10n: $l10n, text: $text, link: $link, email: $email);
		$this->logSend(slug: $slug, email: $email, sent: $sent);

		return $sent;
	}//end send()

	/**
	 * The confirmation's subject and body, through the portal's mail template when one renders.
	 *
	 * @param IL10N  $l10n  The portal's language.
	 * @param string $name  The organisation's name.
	 * @param string $topic The chosen subject, or ''.
	 * @param string $slug  The portal slug.
	 *
	 * @return array{subject: string, body: string}
	 */
	private function confirmationText(IL10N $l10n, string $name, string $topic, string $slug): array {
		$subject = $l10n->t('We have received your question');
		$body    = $this->confirmationBody(l10n: $l10n, name: $name, topic: $topic);
		if ($this->renderer !== null) {
			$text    = $this->renderer->render(
				portal: $slug,
				key: 'contact-confirmation',
				values: ['portal' => $name, 'topic' => $topic],
				subject: $subject,
				body: $body
			);
			$subject = $text['subject'];
			$body    = $text['body'];
		}

		return ['subject' => $subject, 'body' => $body];
	}//end confirmationText()

	/**
	 * The confirmation's default body, naming the topic when there is one.
	 *
	 * @param IL10N  $l10n  The portal's language.
	 * @param string $name  The organisation's name.
	 * @param string $topic The chosen subject, or ''.
	 *
	 * @return string
	 */
	private function confirmationBody(IL10N $l10n, string $name, string $topic): string {
		$body = $l10n->t('Your question has reached %1$s. You find it back under My questions.', [$name]);
		if ($topic !== '') {
			$body = $l10n->t('Your question about %1$s has reached %2$s. You find it back under My questions.', [$topic, $name]);
		}

		return $body;
	}//end confirmationBody()

	/**
	 * Write and send the confirmation mail.
	 *
	 * @param IL10N                                $l10n  The portal's language.
	 * @param array{subject: string, body: string} $text  The subject and body.
	 * @param string                               $link  The link to My questions.
	 * @param string                               $email The confirmed address.
	 *
	 * @return bool True when the mail server took it.
	 */
	private function sendMail(IL10N $l10n, array $text, string $link, string $email): bool {
		$mail = $this->mailer->createEMailTemplate('portaliq.contact.confirmation', []);
		$mail->setSubject($text['subject']);
		$mail->addHeader();
		$mail->addHeading($l10n->t('We have received your question'));
		$mail->addBodyText($text['body']);

		$mail->addBodyButton($l10n->t('Open My questions'), $link);
		$mail->addFooter();

		$message = $this->mailer->createMessage();
		$message->setTo([$email]);
		$message->useTemplate($mail);

		return (count($this->mailer->send($message)) === 0);
	}//end sendMail()

	/**
	 * Log the send, when there is a mail log.
	 *
	 * @param string $slug  The portal slug.
	 * @param string $email The confirmed address.
	 * @param bool   $sent  Whether the mail server took it.
	 *
	 * @return void
	 */
	private function logSend(string $slug, string $email, bool $sent): void {
		if ($this->mailLog !== null) {
			$status = 'failed';
			if ($sent === true) {
				$status = 'delivered';
			}

			$this->mailLog->record(portal: $slug, templateKey: 'contact-confirmation', email: $email, status: $status);
		}
	}//end logSend()
}//end class
