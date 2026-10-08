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

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Contribution\ConfirmationMailKeys;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalDeepLinkBuilder;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCA\Portaliq\Service\PortalResolver;
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
 * nothing is sent and nothing is claimed.
 *
 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t02
 */
class ContactConfirmationMailer {
	private const DEFAULT_LANGUAGE = 'nl';

	private const DEFAULT_NAME = 'Portaliq';

	/**
	 * Constructor.
	 *
	 * @param IMailer                       $mailer        Sends the mail.
	 * @param IFactory                      $l10nFactory   Gives the portal's language.
	 * @param PortalDeepLinkBuilder         $deepLinks     Builds the portal link.
	 * @param PortalResolver                $portals       Finds the organisation's portal.
	 * @param PortalOrganisationConfigService $organisations Names the organisation.
	 * @param PortalAccountService          $accounts      Reads the account's address.
	 * @param LoggerInterface               $logger        Logs a mail that was not sent.
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly IFactory $l10nFactory,
		private readonly PortalDeepLinkBuilder $deepLinks,
		private readonly PortalResolver $portals,
		private readonly PortalOrganisationConfigService $organisations,
		private readonly PortalAccountService $accounts,
		private readonly LoggerInterface $logger,
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
		if (in_array(($action['confirmationMail'] ?? null), ConfirmationMailKeys::TEMPLATES, true) === false) {
			return false;
		}

		try {
			$account = $this->accounts->findBySubjectRef(subjectRef: (string)($subject['subjectRef'] ?? ''));
			$email   = trim((string)($account['email'] ?? ''));
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
		$portal = $this->portalOf(organisation: $organisation);
		$name   = $this->nameOf(portal: $portal, organisation: $organisation);
		$link   = $this->deepLinks->forSite(portalSlug: trim((string)($portal['slug'] ?? '')), organisation: $organisation);
		$l10n   = $this->l10nFactory->get(Application::APP_ID, $this->languageOf(portal: $portal));

		$mail = $this->mailer->createEMailTemplate('portaliq.contact.confirmation', []);
		$mail->setSubject($l10n->t('We have received your question'));
		$mail->addHeader();
		$mail->addHeading($l10n->t('We have received your question'));
		if ($topic !== '') {
			$mail->addBodyText($l10n->t('Your question about %1$s has reached %2$s. You find it back under My questions.', [$topic, $name]));
		} else {
			$mail->addBodyText($l10n->t('Your question has reached %1$s. You find it back under My questions.', [$name]));
		}

		$mail->addBodyButton($l10n->t('Open My questions'), $link);
		$mail->addFooter();

		$message = $this->mailer->createMessage();
		$message->setTo([$email]);
		$message->useTemplate($mail);

		return count($this->mailer->send($message)) === 0;
	}//end send()

	/**
	 * The organisation's portal, or null.
	 *
	 * @param string $organisation The organisation slug.
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
	 * The name the mail speaks for.
	 *
	 * @param array<string, mixed>|null $portal       The portal.
	 * @param string                    $organisation The organisation slug.
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
	 * The portal's language: its first locale.
	 *
	 * @param array<string, mixed>|null $portal The portal.
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
