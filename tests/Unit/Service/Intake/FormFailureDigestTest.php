<?php

/**
 * FormFailureDigest unit tests
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\BackgroundJob\FormFailureDigestJob;
use OCA\Portaliq\Kernel\Contracts\PortalResolverInterface;
use OCA\Portaliq\Service\Intake\FormFailureDigest;
use OCA\Portaliq\Service\Intake\FormFailureDigestMail;
use OCA\Portaliq\Service\Intake\FormFailureRecipients;
use OCA\Portaliq\Service\Intake\SubmitFailureLog;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\L10N\IFactory;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The daily digest of failed submits (REQ-FGV-003): from 07:00, once a day,
 * one mail per administrator of each portal with failures, none on a quiet day.
 *
 * @covers \OCA\Portaliq\Service\Intake\FormFailureDigest
 * @covers \OCA\Portaliq\Service\Intake\FormFailureDigestMail
 * @covers \OCA\Portaliq\Service\Intake\FormFailureRecipients
 * @covers \OCA\Portaliq\BackgroundJob\FormFailureDigestJob
 *
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
 */
class FormFailureDigestTest extends TestCase {

	/**
	 * The app config values, by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Now, as a unix timestamp: 25 Nov 2026 08:00 UTC.
	 *
	 * @var integer
	 */
	private int $now = 1795593600;

	/**
	 * The addresses each sent mail went to, and its body lines.
	 *
	 * @var array<int, array{to: array<int, string>, lines: array<int, string>, subject: string}>
	 */
	private array $sent = [];

	/**
	 * The body lines of the mail being written.
	 *
	 * @var array<int, string>
	 */
	private array $lines = [];

	/**
	 * The subject of the mail being written.
	 *
	 * @var string
	 */
	private string $subject = '';

	/**
	 * The time zone before the test.
	 *
	 * @var string
	 */
	private string $zone = 'UTC';

	/**
	 * Keep the tests in UTC.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->zone = date_default_timezone_get();
		date_default_timezone_set('UTC');
	}//end setUp()

	/**
	 * Restore the time zone.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		date_default_timezone_set($this->zone);
	}//end tearDown()

	/**
	 * The digest over doubles: an in-memory config and clock, two portals and their groups.
	 *
	 * @param array<int, array<string, mixed>> $failures What the failure log answers.
	 * @param boolean                          $mailFails Whether the mail server throws.
	 *
	 * @return FormFailureDigest The digest.
	 */
	private function digest(array $failures, bool $mailFails = false): FormFailureDigest {
		$log = $this->createMock(originalClassName: SubmitFailureLog::class);
		$log->method('since')->willReturnCallback(
			function (int $from) use ($failures): array {
				$this->assertSame(expected: $this->now - 86400, actual: $from);
				return $failures;
			}
		);

		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		$portals = $this->createMock(originalClassName: PortalResolverInterface::class);
		$portals->method('allPublishedPortals')->willReturn(
			[
				['slug' => 'zuid', 'title' => 'Zuiddrecht', 'adminGroups' => ['zuid-beheer']],
				['slug' => 'noord', 'title' => 'Noorddam'],
			]
		);

		$l = $this->createMock(originalClassName: IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$l10n = $this->createMock(originalClassName: IFactory::class);
		$l10n->method('get')->willReturn($l);

		return new FormFailureDigest(
			failures: $log,
			recipients: new FormFailureRecipients(portals: $portals, groups: $this->groupManager()),
			mail: new FormFailureDigestMail(
				mailer: $this->mailer(fails: $mailFails),
				l10n: $l10n,
				logger: $this->createMock(originalClassName: LoggerInterface::class)
			),
			appConfig: $config
		);
	}//end digest()

	/**
	 * The groups: zuid-beheer with two members (one without address), admin with one.
	 *
	 * @return IGroupManager The group manager.
	 */
	private function groupManager(): IGroupManager {
		$user = function (string $address): IUser {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getEMailAddress')->willReturn($address);
			return $user;
		};
		$beheer = $this->createMock(originalClassName: IGroup::class);
		$beheer->method('getUsers')->willReturn([$user('beheer@zuid.nl'), $user(''), $user('beheer@zuid.nl')]);
		$admin = $this->createMock(originalClassName: IGroup::class);
		$admin->method('getUsers')->willReturn([$user('root@example.nl')]);

		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('get')->willReturnCallback(static fn (string $gid): ?IGroup => ['zuid-beheer' => $beheer, 'admin' => $admin][$gid] ?? null);
		return $groups;
	}//end groupManager()

	/**
	 * A mailer that keeps what it sends.
	 *
	 * @param boolean $fails Whether sending throws.
	 *
	 * @return IMailer The mailer.
	 */
	private function mailer(bool $fails): IMailer {
		$template = $this->createMock(originalClassName: IEMailTemplate::class);
		$template->method('setSubject')->willReturnCallback(
			function (string $subject): void {
				$this->subject = $subject;
				$this->lines   = [];
			}
		);
		$template->method('addBodyText')->willReturnCallback(
			function (string $line): void {
				$this->lines[] = $line;
			}
		);

		$recipients = [];
		$message    = $this->createMock(originalClassName: IMessage::class);
		$message->method('setTo')->willReturnCallback(
			function (array $to) use (&$recipients, $message): IMessage {
				$recipients = $to;
				return $message;
			}
		);

		$mailer = $this->createMock(originalClassName: IMailer::class);
		$mailer->method('createEMailTemplate')->willReturn($template);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->method('send')->willReturnCallback(
			function () use (&$recipients, $fails): array {
				if ($fails === true) {
					throw new RuntimeException('smtp down for someone@example.nl');
				}

				$this->sent[] = ['to' => $recipients, 'lines' => $this->lines, 'subject' => $this->subject];
				return [];
			}
		);
		return $mailer;
	}//end mailer()

	/**
	 * Two Woo failures yesterday: one mail to the portal's administrators naming the form and both codes.
	 *
	 * @return void
	 */
	public function testOneDigestNamesTheFormAndItsCodes(): void {
		$failures = [
			['code' => 'OF-1A2B', 'portal' => 'zuid', 'route' => 'woo', 'title' => 'Woo-verzoek', 'status' => 503, 'at' => $this->now - 7200],
			['code' => 'OF-3C4D', 'portal' => 'zuid', 'route' => 'woo', 'title' => 'Woo-verzoek', 'status' => 503, 'at' => $this->now - 3600],
		];

		$this->assertSame(expected: 1, actual: $this->digest(failures: $failures)->run(now: $this->now));
		$this->assertSame(expected: ['beheer@zuid.nl'], actual: $this->sent[0]['to']);
		$this->assertSame(expected: 'Form submits that failed: Zuiddrecht', actual: $this->sent[0]['subject']);
		$this->assertSame(expected: 'Woo-verzoek (woo): 2 × OF-1A2B, OF-3C4D', actual: $this->sent[0]['lines'][1]);
		$this->assertStringStartsWith(prefix: '2 submits on Zuiddrecht', string: $this->sent[0]['lines'][0]);

		// The same day again sends nothing.
		$this->sent = [];
		$this->now += 3600;
		$this->assertSame(expected: 0, actual: $this->digest(failures: $failures)->run(now: $this->now));
		$this->assertSame(expected: [], actual: $this->sent);
	}//end testOneDigestNamesTheFormAndItsCodes()

	/**
	 * Before 07:00 nothing goes out and the day is not marked; a quiet day marks it and sends nothing.
	 *
	 * @return void
	 */
	public function testNothingBeforeSevenAndNothingOnAQuietDay(): void {
		$this->now = 1795589999;
		$failure   = [['code' => 'OF-1A2B', 'portal' => 'zuid', 'route' => 'woo', 'title' => '', 'status' => 503, 'at' => $this->now]];
		$this->assertSame(expected: 0, actual: $this->digest(failures: $failure)->run(now: $this->now));
		$this->assertArrayNotHasKey(key: FormFailureDigest::SENT_KEY, array: $this->config);

		$this->now = 1795593600;
		$this->assertSame(expected: 0, actual: $this->digest(failures: [])->run(now: $this->now));
		$this->assertSame(expected: '2026-11-25', actual: $this->config[FormFailureDigest::SENT_KEY]);
		$this->assertSame(expected: [], actual: $this->sent);
	}//end testNothingBeforeSevenAndNothingOnAQuietDay()

	/**
	 * A portal without adminGroups, and an unknown portal, go to the instance administrators.
	 *
	 * @return void
	 */
	public function testAPortalWithoutAdminGroupsGoesToTheInstanceAdministrators(): void {
		$failures = [
			['code' => 'OF-AAAA', 'portal' => 'noord', 'route' => 'melding', 'title' => '', 'status' => 500, 'at' => $this->now],
			['code' => 'OF-BBBB', 'portal' => 'weg', 'route' => 'x', 'title' => 'X', 'status' => 503, 'at' => $this->now],
		];

		$this->assertSame(expected: 2, actual: $this->digest(failures: $failures)->run(now: $this->now));
		$this->assertSame(expected: [['root@example.nl'], ['root@example.nl']], actual: array_column($this->sent, 'to'));
		$this->assertSame(expected: 'melding (melding): 1 × OF-AAAA', actual: $this->sent[0]['lines'][1]);
		$this->assertSame(expected: 'Form submits that failed: weg', actual: $this->sent[1]['subject']);
	}//end testAPortalWithoutAdminGroupsGoesToTheInstanceAdministrators()

	/**
	 * A mail server that throws counts no mail, and the job never throws.
	 *
	 * @return void
	 */
	public function testAFailedMailIsNotCountedAndTheJobDoesNotThrow(): void {
		$failure = [['code' => 'OF-1A2B', 'portal' => 'zuid', 'route' => 'woo', 'title' => 'Woo', 'status' => 503, 'at' => $this->now]];
		$this->assertSame(expected: 0, actual: $this->digest(failures: $failure, mailFails: true)->run(now: $this->now));

		$digest = $this->createMock(originalClassName: FormFailureDigest::class);
		$digest->expects($this->once())->method('run')->with(1234)->willThrowException(new RuntimeException('boom'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturn(1234);
		$job = new FormFailureDigestJob(time: $time, digest: $digest, logger: $logger);
		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, null);
		$this->assertSame(expected: FormFailureDigestJob::INTERVAL, actual: 3600);
	}//end testAFailedMailIsNotCountedAndTheJobDoesNotThrow()

	/**
	 * Grouping keeps the order of arrival per portal and per form.
	 *
	 * @return void
	 */
	public function testFailuresAreGroupedPerPortalAndForm(): void {
		$grouped = FormFailureDigest::perPortal(
			failures: [
				['code' => 'OF-1', 'portal' => 'a', 'route' => 'r1', 'title' => 'R1', 'status' => 503, 'at' => 1],
				['code' => 'OF-2', 'portal' => 'b', 'route' => 'r1', 'title' => '', 'status' => 503, 'at' => 2],
				['code' => 'OF-3', 'portal' => 'a', 'route' => 'r1', 'title' => 'R1', 'status' => 503, 'at' => 3],
			]
		);

		$this->assertSame(
			expected: ['a' => ['r1' => ['title' => 'R1', 'codes' => ['OF-1', 'OF-3']]], 'b' => ['r1' => ['title' => 'r1', 'codes' => ['OF-2']]]],
			actual: $grouped
		);
		$this->assertSame(expected: ['R1 (r1): 2 × OF-1, OF-3'], actual: FormFailureDigestMail::lines(forms: $grouped['a']));
	}//end testFailuresAreGroupedPerPortalAndForm()
}//end class
