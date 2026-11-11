<?php

/**
 * Portaliq Portal Identity Mailer
 *
 * The one place an identity secret leaves portaliq: the one-time reference
 * link, the invitation into the portal and the confirmation of a new e-mail
 * address. Each goes to the address it belongs to, inside a link, and
 * nowhere else. The mail is what proves the address, so a secret that comes
 * back in an HTTP answer or lands in a log line proves nothing.
 *
 * The secret rides in the link's fragment (`#reference=<secret>`). A
 * fragment never reaches a server access log; the portal consumes it once
 * on load. The mailer builds the link, sends, and forgets the secret.
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
 * @spec openspec/specs/portal-ways-in/spec.md#requirement-every-way-in-sends-its-secret-by-mail-req-iwi-001
 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T01
 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-invite-an-address-and-portaliq-mails-it-req-isa-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Mail\MailLog;
use OCA\Portaliq\Service\Mail\MailTemplateRenderer;
use OCA\Portaliq\Service\Mail\MailSite;
use OCA\Portaliq\Service\PortalDeepLinkBuilder;
use DateTimeInterface;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mails the identity secrets, each inside its own link.
 *
 * @spec openspec/specs/portal-ways-in/spec.md#requirement-every-way-in-sends-its-secret-by-mail-req-iwi-001
 */
class PortalIdentityMailer {
	/**
	 * The one-time link to follow one case (identity-ways-in-screens D1).
	 */
	public const TEMPLATE_REFERENCE_LINK = 'reference-link';

	/**
	 * The invitation into the portal (identity-staff-account-screens D2).
	 */
	public const TEMPLATE_INVITATION = 'invitation';

	/**
	 * The confirmation of a new address (identity-profile-page D1).
	 */
	public const TEMPLATE_EMAIL_CONFIRMATION = 'email-confirmation';

	/**
	 * The activation link of a self-registration (identity-ways-in-screens D4).
	 */
	public const TEMPLATE_REGISTRATION_ACTIVATION = 'registration-activation';

	/**
	 * The invitation of a waiting account an app provisioned: the person
	 * follows the link, signs in, and the waiting account joins theirs
	 * (invitation-secret-joins-the-signed-in-account).
	 */
	public const TEMPLATE_ACCOUNT_INVITATION = 'account-invitation';

	/**
	 * The invitation of a resident to become a contact of another resident
	 * (own-contacts-and-invitations REQ-ROC-003).
	 */
	public const TEMPLATE_CONTACT_INVITATION = 'contact-invitation';

	/**
	 * The invitation to act on someone's behalf (site-mandates-the-represented-manage).
	 */
	public const TEMPLATE_MANDATE_INVITATION = 'mandate-invitation';

	/**
	 * The one-time sign-in link of an `email` account (sign-in-with-an-email-link).
	 */
	public const TEMPLATE_EMAIL_LINK = 'email-link';

	/**
	 * The notice after each e-mail link sign-in (security review L4).
	 */
	public const NOTICE_SIGNED_IN = 'email-link-signed-in';

	/**
	 * The notice to the old address after the sign-in address changed (H2).
	 */
	public const NOTICE_ADDRESS_CHANGED = 'sign-in-address-changed';

	/**
	 * Per template: the fragment key the portal consumes, and the English
	 * source keys of the mail (l10n/nl.json carries the Dutch). `%1$s` is the
	 * portal's name in every line that takes one. `site` sends the link to
	 * the Vue site, where the ways-in screens live.
	 */
	private const TEMPLATES = [
		self::TEMPLATE_REFERENCE_LINK => [
			'fragment' => 'reference',
			'site' => true,
			'subject' => 'Your link to follow your case at %1$s',
			'heading' => 'Follow your case',
			'intro' => 'You asked for a link to follow your case at %1$s.',
			'button' => 'Open your case',
		],
		self::TEMPLATE_INVITATION => [
			'fragment' => 'invitation',
			'site' => true,
			'subject' => 'You are invited to the portal of %1$s',
			'heading' => 'You are invited',
			'intro' => 'Accept the invitation to create your account at %1$s.',
			'button' => 'Accept the invitation',
		],
		self::TEMPLATE_ACCOUNT_INVITATION => [
			'fragment' => 'claim',
			'site' => true,
			'subject' => 'You are invited to the portal of %1$s',
			'heading' => 'You are invited',
			'intro' => 'Open the link and sign in. After that you see what %1$s shares with you.',
			'button' => 'Open the portal',
		],
		self::TEMPLATE_CONTACT_INVITATION => [
			'fragment' => 'contact-invitation',
			'site' => true,
			'subject' => 'You are invited to work together at %1$s',
			'heading' => 'You are invited',
			'intro' => 'Someone you know wants to work with you in the portal of %1$s. Open the link to create your account or sign in.',
			'button' => 'Open the invitation',
		],
		self::TEMPLATE_MANDATE_INVITATION => [
			'fragment' => 'mandate-invitation',
			'site' => true,
			'subject' => 'You may arrange matters for someone at %1$s',
			'heading' => 'You are invited',
			'intro' => 'Someone wants you to arrange matters for them in the portal of %1$s. Sign in and accept the invitation. Only then does it count.',
			'button' => 'Open the invitation',
		],
		self::TEMPLATE_EMAIL_LINK => [
			'fragment' => 'email-link',
			'site' => true,
			'subject' => 'Your sign-in link for %1$s',
			'heading' => 'Sign in',
			'intro' => 'You asked for a link to sign in to %1$s. The link works for 15 minutes.',
			'button' => 'Sign in',
		],
		self::TEMPLATE_EMAIL_CONFIRMATION => [
			'fragment' => 'confirm-email',
			'subject' => 'Confirm your new e-mail address for %1$s',
			'heading' => 'Confirm your e-mail address',
			'intro' => 'You asked to use this address for your account at %1$s. Until you confirm it, we keep using your old address.',
			'button' => 'Confirm this address',
		],
		self::TEMPLATE_REGISTRATION_ACTIVATION => [
			'fragment' => 'activate',
			'site' => true,
			'subject' => 'Activate your account at %1$s',
			'heading' => 'Activate your account',
			'intro' => 'You created an account at %1$s. Follow the link to make it ready for use.',
			'button' => 'Activate your account',
		],
	];

	/**
	 * The notice that an invitation was accepted by an account that took on
	 * its audience (invitation-joins-an-unbound-account).
	 */
	public const NOTICE_TEMPLATE = 'invitation-accepted';

	/**
	 * The line every identity mail carries under its button.
	 */
	private const ONCE_KEY = 'The link works once and for a limited time.';

	/**
	 * The line for the person who never asked.
	 */
	private const IGNORE_KEY = 'Did you not ask for this? Then you can ignore this mail.';

	/**
	 * The mail log's status for each way a send ends: '' when it was
	 * delivered, else the word for the failure.
	 */
	private const LOG_STATUS = [
		''          => 'delivered',
		'exception' => 'failed',
		'refused'   => 'failed',
	];

	/**
	 * Per notice: the English source keys of the informing mail and the width
	 * of the moment its line names. `%1$s` is the portal's name, `%2$s` the
	 * moment.
	 */
	private const NOTICES = [
		self::NOTICE_TEMPLATE => [
			'subject' => 'Your invitation to %1$s was accepted',
			'heading' => 'Your invitation was accepted',
			'line' => 'Your invitation to the portal of %1$s was accepted on %2$s.',
			'moment' => 'date',
		],
		self::NOTICE_SIGNED_IN => [
			'subject' => 'You signed in to %1$s',
			'heading' => 'You signed in',
			'line' => 'Your account at %1$s was signed in to with an e-mail link on %2$s.',
			'moment' => 'datetime',
		],
		self::NOTICE_ADDRESS_CHANGED => [
			'subject' => 'Your sign-in address for %1$s was changed',
			'heading' => 'Your sign-in address was changed',
			'line' => 'From %2$s, sign-in links for your account at %1$s go to another address.',
			'moment' => 'datetime',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param IMailer $mailer Sends the mail.
	 * @param IFactory $l10nFactory The portal's language, independent of any session locale.
	 * @param PortalDeepLinkBuilder $deepLinks Builds the portal link from the route table.
	 * @param MailSite $site The portal, name and language a mail is sent with.
	 * @param LoggerInterface $logger Records a failed send, never the secret.
	 * @param MailTemplateRenderer|null $renderer Swaps in a portal's own text (mail-templates-admin-screen).
	 * @param MailLog|null $mailLog Logs each send, with the address masked.
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly IFactory $l10nFactory,
		private readonly PortalDeepLinkBuilder $deepLinks,
		private readonly MailSite $site,
		private readonly LoggerInterface $logger,
		private readonly ?MailTemplateRenderer $renderer=null,
		private readonly ?MailLog $mailLog=null,
	) {
	}//end __construct()

	/**
	 * Mail one secret to the address it belongs to, inside its link.
	 *
	 * Never throws. A mail that did not leave answers false and is logged by
	 * template only: not the secret, not the link and not the address.
	 *
	 * @param string $template One of the TEMPLATE_* constants.
	 * @param string $email The address the secret belongs to.
	 * @param string $secret The plain one-time secret.
	 * @param string $organisation The tenant the secret belongs to.
	 * @param array<string, mixed>|null $portal The portal the request came
	 *                                          through, or null to look up
	 *                                          the organisation's one portal.
	 * @param array<string, string> $details Who wrote the mail's invitation: `inviter` (a name)
	 *                                       and `message` (their words), both optional.
	 *
	 * @return bool True when the mail left.
	 *
	 * @spec openspec/specs/portal-ways-in/spec.md#requirement-every-way-in-sends-its-secret-by-mail-req-iwi-001
	 */
	public function send(string $template, string $email, string $secret, string $organisation, ?array $portal = null, array $details = []): bool {
		$keys = (self::TEMPLATES[$template] ?? null);
		if ($keys === null || $secret === '' || $this->mailer->validateMailAddress($email) === false) {
			$this->logger->warning('Portaliq: identity mail not sent, the call was incomplete', ['template' => $template]);
			return false;
		}

		$portal = ($portal ?? $this->site->portal(organisation: $organisation));
		$name   = $this->site->name(portal: $portal, organisation: $organisation);
		$link   = $this->linkFor(keys: $keys, portalSlug: trim((string)($portal['slug'] ?? '')), organisation: $organisation);
		$link  .= '#' . $keys['fragment'] . '=' . rawurlencode($secret);

		$run    = new IdentityMailRun(template: $template, keys: $keys, email: $email, portal: $portal, name: $name, link: $link, details: $details);

		return $this->deliver(run: $run);
	}//end send()

	/**
	 * Write and send one identity mail, and log how it went.
	 *
	 * @param IdentityMailRun $run The mail being sent.
	 *
	 * @return bool True when the mail left.
	 */
	private function deliver(IdentityMailRun $run): bool {
		try {
			$l10n   = $this->l10nFactory->get(Application::APP_ID, $this->site->language(portal: $run->portal));
			$failed = $this->mailer->send($this->messageTo(email: $run->email, mail: $this->composeMail(run: $run, l10n: $l10n)));
		} catch (Throwable $failure) {
			// The exception's own message is left out on purpose: a transport
			// error may quote the recipient or the body back.
			$this->logger->warning('Portaliq: identity mail not sent', ['template' => $run->template, 'exception' => get_class($failure)]);
			$this->logSend(run: $run, failure: 'exception');
			return false;
		}

		return $this->reportOutcome(run: $run, failed: $failed);
	}//end deliver()

	/**
	 * Log a sent mail as delivered, or as refused when the mail server
	 * refused a recipient.
	 *
	 * @param IdentityMailRun $run    The mail that was sent.
	 * @param array<mixed>    $failed The recipients the mail server refused.
	 *
	 * @return bool True when the mail left.
	 */
	private function reportOutcome(IdentityMailRun $run, array $failed): bool {
		if (count($failed) > 0) {
			$this->logger->warning('Portaliq: identity mail refused by the mail server', ['template' => $run->template]);
			$this->logSend(run: $run, failure: 'refused');
			return false;
		}

		$this->logSend(run: $run, failure: '');

		return true;
	}//end reportOutcome()

	/**
	 * The identity mail in the portal's language: heading, text, who invited,
	 * the button with the link and the closing lines.
	 *
	 * @param IdentityMailRun $run  The mail being sent.
	 * @param IL10N           $l10n The portal's language.
	 *
	 * @return IEMailTemplate
	 */
	private function composeMail(IdentityMailRun $run, IL10N $l10n): IEMailTemplate {
		$mail = $this->mailer->createEMailTemplate('portaliq.identity.' . $run->template, []);
		$text = $this->textOf(run: $run, l10n: $l10n);

		$mail->setSubject($text['subject']);
		$mail->addHeader();
		$mail->addHeading($l10n->t($run->keys['heading']));
		$mail->addBodyText($text['body']);
		foreach ($this->detailLines(l10n: $l10n, details: $run->details) as $line) {
			$mail->addBodyText($line);
		}

		$mail->addBodyButton($l10n->t($run->keys['button']), $run->link);
		$mail->addBodyText($this->closing(l10n: $l10n));
		$mail->addFooter();

		return $mail;
	}//end composeMail()

	/**
	 * One message to one address, carrying the written mail.
	 *
	 * @param string         $email The recipient.
	 * @param IEMailTemplate $mail  The written mail.
	 *
	 * @return IMessage
	 */
	private function messageTo(string $email, IEMailTemplate $mail): IMessage {
		$message = $this->mailer->createMessage();
		$message->setTo([$email]);
		$message->useTemplate($mail);

		return $message;
	}//end messageTo()

	/**
	 * The lines that say who invited the reader and what they wrote.
	 *
	 * @param \OCP\IL10N $l10n The portal's language.
	 * @param array<string, string> $details `inviter` and `message`.
	 *
	 * @return array<int, string> The lines, none when the mail names no inviter.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t04
	 */
	private function detailLines(\OCP\IL10N $l10n, array $details): array {
		$lines   = [];
		$inviter = trim((string)($details['inviter'] ?? ''));
		if ($inviter !== '') {
			$lines[] = $l10n->t('%1$s invited you.', [$inviter]);
		}

		$message = trim((string)($details['message'] ?? ''));
		if ($message !== '') {
			$lines[] = $l10n->t('Their message: %1$s', [$message]);
		}

		// The e-mail link names the address it was asked for (security
		// review L7), so a look-alike mail without it stands out.
		$address = trim((string)($details['address'] ?? ''));
		if ($address !== '') {
			$lines[] = $l10n->t('This link was asked for %1$s.', [$address]);
		}

		return $lines;
	}//end detailLines()

	/**
	 * The subject and text to send: the portal's own when it has one.
	 *
	 * @param IdentityMailRun $run  The mail being sent.
	 * @param IL10N           $l10n The portal's language, for the default text.
	 *
	 * @return array{subject: string, body: string}
	 *
	 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t03
	 */
	private function textOf(IdentityMailRun $run, IL10N $l10n): array {
		$subject = $l10n->t($run->keys['subject'], [$run->name]);
		$intro   = $l10n->t($run->keys['intro'], [$run->name]);
		if ($this->renderer === null) {
			return ['subject' => $subject, 'body' => $intro];
		}

		$values   = ['portal' => $run->name, 'link' => $run->link];
		$rendered = $this->renderer->render(portal: $run->portalSlug(), key: $run->template, values: $values, subject: $subject, body: $intro);

		return ['subject' => $rendered['subject'], 'body' => $rendered['body']];
	}//end textOf()

	/**
	 * Log one send; never a reason to fail the mail.
	 *
	 * @param IdentityMailRun $run     The mail that was sent.
	 * @param string          $failure A word for a failure, or '' when it was delivered.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t03
	 */
	private function logSend(IdentityMailRun $run, string $failure): void {
		if ($this->mailLog === null) {
			return;
		}

		try {
			$this->mailLog->record(
				portal: $run->portalSlug(),
				templateKey: $run->template,
				email: $run->email,
				status: self::LOG_STATUS[$failure],
				failureReason: $failure
			);
		} catch (Throwable) {
			// The log is a convenience; the mail has gone either way.
		}
	}//end logSend()

	/**
	 * Tell the invited address that its invitation was accepted by an account
	 * that took on the invitation's audience (invitation-joins-an-unbound-account,
	 * security review M1): the date, and who to contact when that was not
	 * the person it was meant for. No secret and no link: the mail only
	 * informs.
	 *
	 * Never throws. A mail that did not leave answers false and is logged
	 * without the address.
	 *
	 * @param string            $email        The invited address.
	 * @param string            $organisation The tenant.
	 * @param DateTimeInterface $moment       When the invitation was accepted.
	 *
	 * @return bool True when the mail left.
	 *
	 * @spec openspec/changes/invitation-joins-an-unbound-account/specs/portal-identity-space/spec.md
	 */
	public function sendClaimNotice(string $email, string $organisation, DateTimeInterface $moment): bool {
		return $this->notice(
			notice: new IdentityNotice(
				template: self::NOTICE_TEMPLATE,
				email: $email,
				organisation: $organisation,
				portal: null,
				moment: $moment
			)
		);
	}//end sendClaimNotice()

	/**
	 * Tell an `email` account's address that it was just used to sign in
	 * (security review L4): the portal, the moment, and who to contact when
	 * it was not them. No secret and no link.
	 *
	 * Never throws.
	 *
	 * @param string            $email        The account's sign-in address.
	 * @param string            $organisation The tenant.
	 * @param array<string, mixed>|null $portal The portal signed in to.
	 * @param DateTimeInterface $moment       When.
	 *
	 * @return bool True when the mail left.
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-the-e-mail-link-never-reaches-a-log-an-answer-or-the-traffic-store-req-iwi-012
	 */
	public function sendSignedInNotice(string $email, string $organisation, ?array $portal, DateTimeInterface $moment): bool {
		return $this->notice(
			notice: new IdentityNotice(
				template: self::NOTICE_SIGNED_IN,
				email: $email,
				organisation: $organisation,
				portal: $portal,
				moment: $moment
			)
		);
	}//end sendSignedInNotice()

	/**
	 * Tell the OLD sign-in address that it no longer signs in (H2).
	 *
	 * Never throws.
	 *
	 * @param string            $email        The old sign-in address.
	 * @param string            $organisation The tenant.
	 * @param DateTimeInterface $moment       When it changed.
	 *
	 * @return bool True when the mail left.
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-an-e-mail-link-session-is-a-fresh-low-session-that-cannot-raise-itself-req-iwi-011
	 */
	public function sendSignInAddressChanged(string $email, string $organisation, DateTimeInterface $moment): bool {
		return $this->notice(
			notice: new IdentityNotice(
				template: self::NOTICE_ADDRESS_CHANGED,
				email: $email,
				organisation: $organisation,
				portal: null,
				moment: $moment
			)
		);
	}//end sendSignInAddressChanged()

	/**
	 * One informing mail: a heading, one line with the portal and the
	 * moment, and whom to contact. Never throws; never logs the address.
	 *
	 * @param IdentityNotice $notice Which notice, to whom and when.
	 *
	 * @return bool True when the mail left.
	 */
	private function notice(IdentityNotice $notice): bool {
		if ($this->mailer->validateMailAddress($notice->email) === false) {
			$this->logger->warning('Portaliq: identity mail not sent, the call was incomplete', ['template' => $notice->template]);
			return false;
		}

		$notice = $notice->withPortal(portal: ($notice->portal ?? $this->site->portal(organisation: $notice->organisation)));
		$name   = $this->site->name(portal: $notice->portal, organisation: $notice->organisation);

		try {
			$failed = $this->mailer->send($this->messageTo(email: $notice->email, mail: $this->noticeMail(notice: $notice, name: $name)));
		} catch (Throwable $failure) {
			$this->logger->warning('Portaliq: identity mail not sent', ['template' => $notice->template, 'exception' => get_class($failure)]);
			return false;
		}

		return count($failed) === 0;
	}//end notice()

	/**
	 * The informing mail in the portal's language.
	 *
	 * @param IdentityNotice $notice Which notice, for its portal, to whom and when.
	 * @param string         $name   The portal's name.
	 *
	 * @return IEMailTemplate
	 */
	private function noticeMail(IdentityNotice $notice, string $name): IEMailTemplate {
		$keys = self::NOTICES[$notice->template];
		$l10n = $this->l10nFactory->get(Application::APP_ID, $this->site->language(portal: $notice->portal));
		$day  = (string)$l10n->l($keys['moment'], $notice->moment, ['width' => 'long']);

		$mail = $this->mailer->createEMailTemplate('portaliq.identity.' . $notice->template, []);
		$mail->setSubject($l10n->t($keys['subject'], [$name]));
		$mail->addHeader();
		$mail->addHeading($l10n->t($keys['heading']));
		$mail->addBodyText($l10n->t($keys['line'], [$name, $day]));
		$mail->addBodyText($l10n->t('Was this not you? Then contact %1$s.', [$name]));
		$mail->addFooter();

		return $mail;
	}//end noticeMail()

	/**
	 * The page a template's link opens. Every link opens the site: the ways
	 * in by their portal slug (portaliq#1021), the e-mail confirmation through
	 * forPortal(), which builds the site address too since the React portal
	 * retired (site-reaches-portal-parity REQ-SRP-049).
	 *
	 * @param array<string, mixed> $keys         The template's keys.
	 * @param string               $portalSlug   The portal's slug, or ''.
	 * @param string               $organisation The tenant slug.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/portal-ways-in/spec.md#requirement-every-way-in-sends-its-secret-by-mail-req-iwi-001
	 */
	private function linkFor(array $keys, string $portalSlug, string $organisation): string {
		if (($keys['site'] ?? false) === true) {
			return $this->deepLinks->forSite(portalSlug: $portalSlug, organisation: $organisation);
		}

		return $this->deepLinks->forPortal(portalSlug: $portalSlug, organisation: $organisation);
	}//end linkFor()

	/**
	 * The two closing lines: once only, and what to do if you never asked.
	 *
	 * @param IL10N $l10n The portal's language.
	 *
	 * @return string
	 */
	private function closing(IL10N $l10n): string {
		return $l10n->t(self::ONCE_KEY) . ' ' . $l10n->t(self::IGNORE_KEY);
	}//end closing()
}//end class
