<?php

/**
 * Unit tests for TrafficReportDelivery.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Traffic;

use DateTime;
use OCA\Portaliq\Service\Traffic\TrafficReportDelivery;
use OCA\Portaliq\Service\Traffic\TrafficReportMail;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A user recipient gets a notification and a mail to their account
 * address; an address gets a mail; a failing mailer costs that mail only.
 */
class TrafficReportDeliveryTest extends TestCase {

	/**
	 * The mails sent, each with to, subject, plain and html.
	 *
	 * @var array<int, \stdClass>
	 */
	private array $mails = [];

	/**
	 * The notifications created, each with user, subject, parameters and link.
	 *
	 * @var array<int, \stdClass>
	 */
	private array $notified = [];

	/**
	 * The delivery over capturing doubles.
	 *
	 * @param bool $mailerWorks Whether `send` succeeds.
	 *
	 * @return TrafficReportDelivery The delivery.
	 */
	private function delivery(bool $mailerWorks = true): TrafficReportDelivery {
		$this->mails = [];
		$this->notified = [];

		$template = $this->createMock(IEMailTemplate::class);
		$template->method('renderHtml')->willReturn('<html>report</html>');

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturnCallback(static fn (string $address): bool => str_contains($address, '@'));
		$mailer->method('createEMailTemplate')->willReturn($template);
		$mailer->method('createMessage')->willReturnCallback(
			function (): IMessage {
				$captured = new \stdClass();
				$captured->to = [];
				$captured->subject = '';
				$captured->plain = '';
				$captured->html = '';
				$this->mails[] = $captured;
				$message = $this->createMock(IMessage::class);
				foreach (['setTo' => 'to', 'setSubject' => 'subject', 'setPlainBody' => 'plain', 'setHtmlBody' => 'html'] as $method => $field) {
					$message->method($method)->willReturnCallback(static function (mixed $value) use ($captured, $field, $message): IMessage {
						$captured->{$field} = $value;

						return $message;
					});
				}

				return $message;
			}
		);
		if ($mailerWorks === true) {
			$mailer->method('send')->willReturn([]);
		} else {
			$mailer->method('send')->willThrowException(new RuntimeException('SMTP down'));
		}

		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn('admin@example.org');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $id): ?IUser => ($id === 'admin') ? $user : null);

		$notifications = $this->createMock(IManager::class);
		$notifications->method('createNotification')->willReturnCallback(
			function (): INotification {
				$record = new \stdClass();
				$record->user = '';
				$record->subject = '';
				$record->parameters = [];
				$record->link = '';
				$this->notified[] = $record;
				$notification = $this->createMock(INotification::class);
				foreach (['setApp', 'setDateTime', 'setObject'] as $method) {
					$notification->method($method)->willReturn($notification);
				}

				$notification->method('setUser')->willReturnCallback(static function (string $user) use ($record, $notification): INotification {
					$record->user = $user;

					return $notification;
				});
				$notification->method('setSubject')->willReturnCallback(static function (string $subject, array $parameters = []) use ($record, $notification): INotification {
					$record->subject = $subject;
					$record->parameters = $parameters;

					return $notification;
				});
				$notification->method('setLink')->willReturnCallback(static function (string $link) use ($record, $notification): INotification {
					$record->link = $link;

					return $notification;
				});

				return $notification;
			}
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturn('/apps/portaliq/traffic');
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://x' . $path);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$l10n = $this->createMock(IFactory::class);
		$l10n->method('get')->willReturn($l);
		$l10n->method('getUserLanguage')->willReturn('en');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('@1788949800'));

		return new TrafficReportDelivery($mailer, $users, $notifications, $urls, $l10n, $time, new TrafficReportMail(), $this->createMock(LoggerInterface::class));
	}//end delivery()


	/**
	 * @return void
	 */
	public function testAReportReachesAUserByNotificationAndMailAndAnAddressByMail(): void {
		$delivery = $this->delivery();
		$period = ['key' => '2026-09-08', 'from' => '2026-09-08', 'to' => '2026-09-08', 'previousFrom' => '2026-09-07', 'previousTo' => '2026-09-07'];
		$current = ['pageViews' => 10, 'sessions' => 5, 'visitors' => 4, 'engagedSessions' => 2, 'bounceRate' => 0.6];
		$previous = ['pageViews' => 5, 'sessions' => 5, 'visitors' => 0, 'engagedSessions' => 1, 'bounceRate' => 0.8];

		$done = $delivery->sendReport(
			portal: ['slug' => 'open-tilburg', 'title' => 'Open Tilburg'],
			report: ['id' => 'd', 'name' => 'Daily', 'sections' => ['overview'], 'recipients' => ['admin', 'ext@example.org', 'unknown-user']],
			period: $period,
			current: $current,
			previous: $previous
		);

		$this->assertSame(3, $done, 'a notification and a mail for the user, a mail for the address, nothing for an unknown user');
		$this->assertCount(1, $this->notified);
		$this->assertSame('admin', $this->notified[0]->user);
		$this->assertSame(TrafficReportDelivery::SUBJECT_REPORT, $this->notified[0]->subject);
		$this->assertSame('Daily', $this->notified[0]->parameters['name']);
		$this->assertSame('https://x/apps/portaliq/traffic', $this->notified[0]->link);
		$this->assertCount(2, $this->mails);
		$this->assertSame(['admin@example.org'], $this->mails[0]->to);
		$this->assertSame(['ext@example.org'], $this->mails[1]->to);
		$this->assertSame('Open Tilburg: Daily (2026-09-08)', $this->mails[0]->subject);
		$this->assertStringContainsString('Page views: 10 (+100% on the previous period, 5)', $this->mails[0]->plain);
		$this->assertStringContainsString('https://x/apps/portaliq/traffic', $this->mails[0]->plain);
		$this->assertSame('<html>report</html>', $this->mails[0]->html);
	}//end testAReportReachesAUserByNotificationAndMailAndAnAddressByMail()


	/**
	 * @return void
	 */
	public function testAnAlertSaysWhatCrossedAndAFailingMailerCostsOnlyTheMail(): void {
		$delivery = $this->delivery(mailerWorks: false);
		$period = ['key' => '2026-09-09', 'from' => '2026-09-09', 'to' => '2026-09-09', 'previousFrom' => '2026-09-08', 'previousTo' => '2026-09-08'];

		$done = $delivery->sendAlert(
			portal: ['slug' => 'open-tilburg', 'title' => 'Open Tilburg'],
			alert: ['id' => 'busy', 'name' => 'Busy', 'metric' => 'pageViews', 'comparison' => 'above', 'threshold' => 50.0, 'recipients' => ['admin']],
			period: $period,
			value: 60.0,
			change: null
		);

		$this->assertSame(1, $done, 'the notification landed, the mail did not');
		$this->assertSame(TrafficReportDelivery::SUBJECT_ALERT, $this->notified[0]->subject);
		$this->assertSame('pageViews reached 60, above the threshold of 50.', $this->notified[0]->parameters['line']);
	}//end testAnAlertSaysWhatCrossedAndAFailingMailerCostsOnlyTheMail()
}//end class
