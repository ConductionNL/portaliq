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
use OCA\Portaliq\Service\PortalDeepLinkBuilder;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCA\Portaliq\Service\PortalResolver;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
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
	 * The line every identity mail carries under its button.
	 */
	private const ONCE_KEY = 'The link works once and for a limited time.';

	/**
	 * The line for the person who never asked.
	 */
	private const IGNORE_KEY = 'Did you not ask for this? Then you can ignore this mail.';

	/**
	 * The language of a portal that declares none.
	 */
	private const DEFAULT_LANGUAGE = 'nl';

	/**
	 * The name of a tenant nothing names.
	 */
	private const DEFAULT_NAME = 'Portaliq';

	/**
	 * Constructor.
	 *
	 * @param IMailer $mailer Sends the mail.
	 * @param IFactory $l10nFactory The portal's language, independent of any session locale.
	 * @param PortalDeepLinkBuilder $deepLinks Builds the portal link from the route table.
	 * @param PortalResolver $portals Finds the organisation's portal when the caller has none.
	 * @param PortalOrganisationConfigService $organisations The tenant's name when no portal names it.
	 * @param LoggerInterface $logger Records a failed send, never the secret.
	 * @param MailTemplateRenderer|null $renderer Swaps in a portal's own text (mail-templates-admin-screen).
	 * @param MailLog|null $mailLog Logs each send, with the address masked.
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly IFactory $l10nFactory,
		private readonly PortalDeepLinkBuilder $deepLinks,
		private readonly PortalResolver $portals,
		private readonly PortalOrganisationConfigService $organisations,
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

		$portal = ($portal ?? $this->portalOf(organisation: $organisation));
		$name   = $this->nameOf(portal: $portal, organisation: $organisation);
		$link   = $this->linkFor(keys: $keys, portalSlug: trim((string)($portal['slug'] ?? '')), organisation: $organisation);
		$link  .= '#' . $keys['fragment'] . '=' . rawurlencode($secret);

		try {
			$l10n = $this->l10nFactory->get(Application::APP_ID, $this->languageOf(portal: $portal));

			$mail = $this->mailer->createEMailTemplate('portaliq.identity.' . $template, []);
			$text = $this->textOf(
				template: $template,
				portalSlug: trim((string)($portal['slug'] ?? '')),
				values: ['portal' => $name, 'link' => $link],
				subject: $l10n->t($keys['subject'], [$name]),
				intro: $l10n->t($keys['intro'], [$name])
			);

			$mail->setSubject($text['subject']);
			$mail->addHeader();
			$mail->addHeading($l10n->t($keys['heading']));
			$mail->addBodyText($text['body']);
			foreach ($this->detailLines(l10n: $l10n, details: $details) as $line) {
				$mail->addBodyText($line);
			}

			$mail->addBodyButton($l10n->t($keys['button']), $link);
			$mail->addBodyText($this->closing(l10n: $l10n));
			$mail->addFooter();

			$message = $this->mailer->createMessage();
			$message->setTo([$email]);
			$message->useTemplate($mail);
			$failed = $this->mailer->send($message);
		} catch (Throwable $failure) {
			// The exception's own message is left out on purpose: a transport
			// error may quote the recipient or the body back.
			$this->logger->warning('Portaliq: identity mail not sent', ['template' => $template, 'exception' => get_class($failure)]);
			$this->logSend(template: $template, email: $email, portal: $portal, status: 'failed', reason: 'exception');
			return false;
		}

		if (count($failed) > 0) {
			$this->logger->warning('Portaliq: identity mail refused by the mail server', ['template' => $template]);
			$this->logSend(template: $template, email: $email, portal: $portal, status: 'failed', reason: 'refused');
			return false;
		}

		$this->logSend(template: $template, email: $email, portal: $portal, status: 'delivered', reason: '');

		return true;
	}//end send()

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

		return $lines;
	}//end detailLines()

	/**
	 * The subject and text to send: the portal's own when it has one.
	 *
	 * @param string                $template   The template key.
	 * @param string                $portalSlug The portal slug.
	 * @param array<string, string> $values     The variables' values.
	 * @param string                $subject    The default subject.
	 * @param string                $intro      The default text.
	 *
	 * @return array{subject: string, body: string}
	 *
	 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t03
	 */
	private function textOf(string $template, string $portalSlug, array $values, string $subject, string $intro): array {
		if ($this->renderer === null) {
			return ['subject' => $subject, 'body' => $intro];
		}

		$rendered = $this->renderer->render(portal: $portalSlug, key: $template, values: $values, subject: $subject, body: $intro);

		return ['subject' => $rendered['subject'], 'body' => $rendered['body']];
	}//end textOf()

	/**
	 * Log one send; never a reason to fail the mail.
	 *
	 * @param string                    $template The template key.
	 * @param string                    $email    The recipient.
	 * @param array<string, mixed>|null $portal   The portal.
	 * @param string                    $status   `delivered` or `failed`.
	 * @param string                    $reason   A word for a failure, or ''.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t03
	 */
	private function logSend(string $template, string $email, ?array $portal, string $status, string $reason): void {
		if ($this->mailLog === null) {
			return;
		}

		try {
			$this->mailLog->record(
				portal: trim((string)($portal['slug'] ?? '')),
				templateKey: $template,
				email: $email,
				status: $status,
				failureReason: $reason
			);
		} catch (Throwable) {
			// The log is a convenience; the mail has gone either way.
		}
	}//end logSend()

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

	/**
	 * The organisation's one published portal, or null when it has none or
	 * several (then the link names the organisation instead).
	 *
	 * @param string $organisation The tenant.
	 *
	 * @return array<string, mixed>|null
	 */
	private function portalOf(string $organisation): ?array {
		try {
			return $this->portals->resolveByOrganisation(organisation: $organisation);
		} catch (Throwable) {
			return null;
		}
	}//end portalOf()

	/**
	 * The name the mail greets from: the portal's title, else the tenant's.
	 *
	 * @param array<string, mixed>|null $portal The portal, or null.
	 * @param string $organisation The tenant.
	 *
	 * @return string
	 */
	private function nameOf(?array $portal, string $organisation): string {
		$title = trim((string)($portal['title'] ?? ''));
		if ($title !== '') {
			return $title;
		}

		try {
			$name = trim((string)($this->organisations->resolve(orgSlug: $organisation)['organisationName'] ?? ''));
		} catch (Throwable) {
			$name = '';
		}

		if ($name === '') {
			return self::DEFAULT_NAME;
		}

		return $name;
	}//end nameOf()

	/**
	 * The portal's language: the first locale it publishes in.
	 *
	 * @param array<string, mixed>|null $portal The portal, or null.
	 *
	 * @return string
	 */
	private function languageOf(?array $portal): string {
		$locales = ($portal['locales'] ?? []);
		if (is_array($locales) === true && is_string($locales[0] ?? null) === true && trim($locales[0]) !== '') {
			return trim($locales[0]);
		}

		return self::DEFAULT_LANGUAGE;
	}//end languageOf()
}//end class
