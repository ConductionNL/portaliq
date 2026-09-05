<?php

/**
 * Portaliq Traffic Report Delivery.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-scheduled-report-must-be-sent-once-per-period
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

use OCA\Portaliq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hands a rendered report or a fired alert to its recipients.
 *
 * A recipient is a Nextcloud user id or an e-mail address. A user gets a
 * Nextcloud notification AND a mail to the address on their account (or
 * only the notification, when they have none); an address gets a mail.
 * Every failure is caught and logged per recipient: one bounced address
 * must not cost the others their report, and the job that called this
 * has already recorded the period as done.
 *
 * The mail carries numbers, never a visitor. The notification carries the
 * report's or the alert's name and a link to the Traffic page; the
 * Notifier renders it.
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-scheduled-report-must-be-sent-once-per-period
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- delivery is where the
 * mailer, the user directory, the notification manager, the URL generator
 * and the language factory meet; that is what it is for.
 */
class TrafficReportDelivery {

	/**
	 * The notification subject of a report.
	 */
	public const SUBJECT_REPORT = 'traffic_report';

	/**
	 * The notification subject of an alert.
	 */
	public const SUBJECT_ALERT = 'traffic_alert';

	/**
	 * Constructor.
	 *
	 * @param IMailer              $mailer        Sends the mail.
	 * @param IUserManager         $users         Resolves a user id to an address.
	 * @param INotificationManager $notifications Creates the in-app notification.
	 * @param IURLGenerator        $urls          Links to the Traffic page.
	 * @param IFactory             $l10n          The recipient's language.
	 * @param ITimeFactory         $time          The clock.
	 * @param TrafficReportMail    $mail          Renders the words.
	 * @param LoggerInterface      $logger        The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IMailer $mailer,
		private readonly IUserManager $users,
		private readonly INotificationManager $notifications,
		private readonly IURLGenerator $urls,
		private readonly IFactory $l10n,
		private readonly ITimeFactory $time,
		private readonly TrafficReportMail $mail,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Deliver a report to its recipients.
	 *
	 * @param array<string, mixed> $portal   The portal record.
	 * @param array<string, mixed> $report   The resolved report.
	 * @param array<string, mixed> $period   The period.
	 * @param array<string, mixed> $current  The period's folded numbers.
	 * @param array<string, mixed> $previous The previous period's folded numbers.
	 *
	 * @return int How many deliveries succeeded (mails and notifications).
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-scheduled-report-must-be-sent-once-per-period
	 */
	public function sendReport(array $portal, array $report, array $period, array $current, array $previous): int {
		$done = 0;
		foreach ((array)$report['recipients'] as $recipient) {
			$userId = $this->userId(recipient: (string)$recipient);
			$l = $this->l10n->get(Application::APP_ID, $this->language(userId: $userId));
			$subject = $this->mail->subject(l: $l, portal: $portal, report: $report, period: $period);
			$sections = $this->mail->sections(l: $l, report: $report, period: $period, current: $current, previous: $previous);
			$link = $this->trafficLink();
			$linkText = $l->t('Open the Traffic page');
			if ($userId !== '') {
				$done += (int)$this->notify(
					userId: $userId,
					subject: self::SUBJECT_REPORT,
					parameters: ['name' => (string)$report['name'], 'portal' => (string)($portal['title'] ?? $portal['slug'] ?? ''), 'span' => $period['from'] . ' / ' . $period['to']],
					objectId: (string)($portal['slug'] ?? '') . ':' . (string)$report['id'] . ':' . (string)$period['key']
				);
			}

			$address = $this->address(recipient: (string)$recipient, userId: $userId);
			if ($address === '') {
				continue;
			}

			$done += (int)$this->sendMail(
				address: $address,
				subject: $subject,
				plain: $this->mail->plain(sections: $sections, link: $link, linkText: $linkText),
				sections: $sections,
				link: $link,
				linkText: $linkText
			);
		}

		return $done;
	}

	/**
	 * Deliver a fired alert to its recipients.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 * @param array<string, mixed> $alert  The resolved alert.
	 * @param array<string, mixed> $period The period.
	 * @param float                $value  The metric's value.
	 * @param float|null           $change The percent change, when the comparison is one.
	 *
	 * @return int How many deliveries succeeded.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-an-alert-must-fire-once-per-period
	 */
	public function sendAlert(array $portal, array $alert, array $period, float $value, ?float $change): int {
		$done = 0;
		foreach ((array)$alert['recipients'] as $recipient) {
			$userId = $this->userId(recipient: (string)$recipient);
			$l = $this->l10n->get(Application::APP_ID, $this->language(userId: $userId));
			$portalName = (string)($portal['title'] ?? $portal['slug'] ?? '');
			$line = $this->alertLine(l: $l, alert: $alert, value: $value, change: $change);
			$subject = $l->t('Traffic alert: %1$s (%2$s)', [(string)$alert['name'], $portalName]);
			$link = $this->trafficLink();
			$linkText = $l->t('Open the Traffic page');
			if ($userId !== '') {
				$done += (int)$this->notify(
					userId: $userId,
					subject: self::SUBJECT_ALERT,
					parameters: ['name' => (string)$alert['name'], 'portal' => $portalName, 'line' => $line],
					objectId: (string)($portal['slug'] ?? '') . ':' . (string)$alert['id'] . ':' . (string)$period['key']
				);
			}

			$address = $this->address(recipient: (string)$recipient, userId: $userId);
			if ($address === '') {
				continue;
			}

			$sections = [[
				'heading' => $subject,
				'lines' => [$line, $l->t('Period: %1$s to %2$s.', [(string)$period['from'], (string)$period['to']])],
			]];
			$done += (int)$this->sendMail(
				address: $address,
				subject: $subject,
				plain: $this->mail->plain(sections: $sections, link: $link, linkText: $linkText),
				sections: $sections,
				link: $link,
				linkText: $linkText
			);
		}

		return $done;
	}

	/**
	 * What an alert says: the metric, its value, the threshold.
	 *
	 * @param \OCP\IL10N           $l      The language.
	 * @param array<string, mixed> $alert  The alert.
	 * @param float                $value  The value.
	 * @param float|null           $change The percent change.
	 *
	 * @return string The line.
	 */
	private function alertLine(\OCP\IL10N $l, array $alert, float $value, ?float $change): string {
		$metric = (string)$alert['metric'];
		$threshold = (string)(float)$alert['threshold'];
		if ((string)$alert['comparison'] === 'changeAbove') {
			return $l->t('%1$s changed by %2$s%% against the previous period (threshold %3$s%%).', [$metric, (string)($change ?? 0), $threshold]);
		}

		if ((string)$alert['comparison'] === 'below') {
			return $l->t('%1$s was %2$s, below the threshold of %3$s.', [$metric, (string)$value, $threshold]);
		}

		return $l->t('%1$s reached %2$s, above the threshold of %3$s.', [$metric, (string)$value, $threshold]);
	}

	/**
	 * A recipient as a user id, or '' when it is not a known user.
	 *
	 * @param string $recipient The recipient.
	 *
	 * @return string The user id.
	 */
	private function userId(string $recipient): string {
		if (str_contains($recipient, '@') === true) {
			return '';
		}

		return ($this->users->get($recipient) !== null) ? $recipient : '';
	}

	/**
	 * The address to mail: the recipient itself when it is one, else the
	 * user's account address.
	 *
	 * @param string $recipient The recipient.
	 * @param string $userId    The resolved user id, or ''.
	 *
	 * @return string The address, or ''.
	 */
	private function address(string $recipient, string $userId): string {
		if ($userId === '') {
			return ($this->mailer->validateMailAddress($recipient) === true) ? $recipient : '';
		}

		$user = $this->users->get($userId);
		$address = trim((string)($user?->getEMailAddress() ?? ''));

		return ($address !== '' && $this->mailer->validateMailAddress($address) === true) ? $address : '';
	}

	/**
	 * A user's language, or the instance default.
	 *
	 * @param string $userId The user id, or ''.
	 *
	 * @return string|null The language code.
	 */
	private function language(string $userId): ?string {
		if ($userId === '') {
			return null;
		}

		$language = $this->l10n->getUserLanguage($this->users->get($userId));

		return ($language === '') ? null : $language;
	}

	/**
	 * The Traffic page, absolute.
	 *
	 * @return string The URL.
	 */
	private function trafficLink(): string {
		return $this->urls->getAbsoluteURL($this->urls->linkToRoute('portaliq.dashboard.catchAll', ['path' => 'traffic']));
	}

	/**
	 * Send one mail, HTML and plain, never throwing.
	 *
	 * @param string                                             $address  The address.
	 * @param string                                             $subject  The subject.
	 * @param string                                             $plain    The plain body.
	 * @param array<int, array{heading: string, lines: string[]}> $sections The sections, for the HTML template.
	 * @param string                                             $link     The Traffic page.
	 * @param string                                             $linkText The button text.
	 *
	 * @return bool True when sent.
	 */
	private function sendMail(string $address, string $subject, string $plain, array $sections, string $link, string $linkText): bool {
		try {
			$template = $this->mailer->createEMailTemplate('portaliq.TrafficReport', []);
			$template->setSubject($subject);
			$template->addHeader();
			foreach ($sections as $section) {
				$template->addHeading($section['heading']);
				$template->addBodyText(implode("\n", $section['lines']), implode("\n", $section['lines']));
			}

			$template->addBodyButton($linkText, $link);
			$template->addFooter();

			$message = $this->mailer->createMessage();
			$message->setSubject($subject);
			$message->setTo([$address]);
			$message->setHtmlBody($template->renderHtml());
			$message->setPlainBody($plain);
			$failed = $this->mailer->send($message);
			if ($failed !== []) {
				$this->logger->warning('Portaliq: traffic report mail not delivered', ['address' => $address]);

				return false;
			}

			return true;
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: traffic report mail failed', ['address' => $address, 'reason' => $e->getMessage()]);

			return false;
		}
	}

	/**
	 * Create one in-app notification, never throwing.
	 *
	 * @param string                $userId     The user.
	 * @param string                $subject    The subject key.
	 * @param array<string, string> $parameters The subject parameters the Notifier renders.
	 * @param string                $objectId   What the notification is about (portal, definition, period).
	 *
	 * @return bool True when created.
	 */
	private function notify(string $userId, string $subject, array $parameters, string $objectId): bool {
		try {
			$notification = $this->notifications->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($userId)
				->setDateTime($this->time->getDateTime())
				->setObject('traffic', mb_substr($objectId, 0, 64))
				->setSubject($subject, $parameters)
				->setLink($this->trafficLink());
			$this->notifications->notify($notification);

			return true;
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: traffic notification failed', ['user' => $userId, 'reason' => $e->getMessage()]);

			return false;
		}
	}
}
