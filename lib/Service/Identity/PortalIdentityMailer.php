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
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly IFactory $l10nFactory,
		private readonly PortalDeepLinkBuilder $deepLinks,
		private readonly PortalResolver $portals,
		private readonly PortalOrganisationConfigService $organisations,
		private readonly LoggerInterface $logger,
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
	 *
	 * @return bool True when the mail left.
	 *
	 * @spec openspec/specs/portal-ways-in/spec.md#requirement-every-way-in-sends-its-secret-by-mail-req-iwi-001
	 */
	public function send(string $template, string $email, string $secret, string $organisation, ?array $portal = null): bool {
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
			$mail->setSubject($l10n->t($keys['subject'], [$name]));
			$mail->addHeader();
			$mail->addHeading($l10n->t($keys['heading']));
			$mail->addBodyText($l10n->t($keys['intro'], [$name]));
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
			return false;
		}

		if (count($failed) > 0) {
			$this->logger->warning('Portaliq: identity mail refused by the mail server', ['template' => $template]);
			return false;
		}

		return true;
	}//end send()

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
