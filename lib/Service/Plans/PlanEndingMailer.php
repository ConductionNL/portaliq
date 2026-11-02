<?php

/**
 * Portaliq Plan Ending Mailer
 *
 * The mail a participant gets when a plan they share is near its end date
 * with actions still open.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Plans
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
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md#requirement-a-plan-near-its-end-date-asks-for-action-req-spl-004
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Plans;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Mail\MailLog;
use OCA\Portaliq\Service\Mail\MailSite;
use OCA\Portaliq\Service\Mail\MailTemplateRenderer;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalDeepLinkBuilder;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mails one participant that a plan is nearly ending (template `plan-ending`).
 *
 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md#requirement-a-plan-near-its-end-date-asks-for-action-req-spl-004
 */
class PlanEndingMailer {
	/**
	 * The template key, also the mail log's.
	 */
	public const TEMPLATE = 'plan-ending';

	/**
	 * Constructor.
	 *
	 * @param IMailer               $mailer      Sends the mail.
	 * @param IFactory              $l10nFactory The portal's language.
	 * @param PortalDeepLinkBuilder $deepLinks   The link back to the portal.
	 * @param MailSite              $site        The portal the mail speaks for.
	 * @param PortalAccountService  $accounts    The participant's address.
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
	 * Mail one participant about a plan near its end date.
	 *
	 * @param string $subjectRef   The participant.
	 * @param string $organisation The tenant.
	 * @param string $plan         The plan's title.
	 * @param string $endDate      The end date, `Y-m-d`.
	 * @param int    $open         How many actions are still open.
	 *
	 * @return bool Whether a mail left; false for a participant without a usable address.
	 *
	 * @spec openspec/changes/shared-plans-with-a-caseworker/specs/resident-plans/spec.md#requirement-a-plan-near-its-end-date-asks-for-action-req-spl-004
	 */
	public function send(string $subjectRef, string $organisation, string $plan, string $endDate, int $open): bool {
		try {
			$email = trim((string)($this->accounts->findBySubjectRef(subjectRef: $subjectRef)['email'] ?? ''));
			if ($email === '' || $this->mailer->validateMailAddress($email) === false) {
				return false;
			}

			return $this->mail(
				email: $email,
				subjectRef: $subjectRef,
				organisation: $organisation,
				values: ['plan' => $plan, 'date' => $endDate, 'open' => (string)$open]
			);
		} catch (Throwable $failure) {
			$this->logger->warning('Portaliq: plan end mail not sent', ['exception' => get_class($failure)]);

			return false;
		}
	}//end send()

	/**
	 * Compose, send and log the mail.
	 *
	 * @param string                $email        The address.
	 * @param string                $subjectRef   The participant.
	 * @param string                $organisation The tenant.
	 * @param array<string, string> $values       `plan`, `date` and `open`.
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

		$heading = $l10n->t('Your plan is nearly ending');
		$text    = $this->renderer->render(
			portal: $slug,
			key: self::TEMPLATE,
			values: ['portal' => $name] + $values,
			subject: $l10n->t('The plan %1$s ends on %2$s', [$values['plan'], $values['date']]),
			body: $l10n->t(
				'The plan "%1$s" ends on %2$s. %3$s actions are still open. Extend the plan with your caseworker, or complete the actions.',
				[$values['plan'], $values['date'], $values['open']]
			)
		);

		$mail = $this->mailer->createEMailTemplate('portaliq.plan.ending', []);
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
