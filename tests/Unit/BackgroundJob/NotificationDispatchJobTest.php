<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\BackgroundJob;

use OCA\Portaliq\BackgroundJob\NotificationDispatchJob;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests the async dispatch pipeline: a content-free IMailer call (org name +
 * deep link only, never a rule key, app id, or case content), a NEW
 * `portalNotification` row per attempt (sent | failed, consecutive `attempts`
 * counter carried over from the previous attempt for the same account+ruleKey),
 * and the WMEBV `needsAlternativeContact` fallback flag after N consecutive
 * failures (cleared again on the next successful send).
 *
 * @spec openspec/specs/supplier-portal/spec.md#manifest-notification-rule-keys-drive-an-out-of-band-email
 * @spec openspec/specs/supplier-portal/spec.md#every-dispatch-attempt-is-logged
 * @spec openspec/specs/supplier-portal/spec.md#repeated-failure-flags-an-alternative-contact-fallback
 */
class NotificationDispatchJobTest extends TestCase {
	private const ARGUMENT = [
		'subjectRef' => 's1',
		'organisation' => 'org-1',
		'audience' => 'supplier',
		'appId' => 'portaliq',
		'ruleKey' => 'message.created',
	];

	private function l10nFactory(): IFactory {
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturnCallback(
			function (string $app, $long = null, $locale = null) {
				$l10n = $this->createMock(IL10N::class);
				$l10n->method('t')->willReturnCallback(
					static fn (string $text, $parameters = []) => '[' . $long . '] ' . vsprintf($text, $parameters)
				);

				return $l10n;
			}
		);

		return $factory;
	}//end l10nFactory()

	private function timeFactory(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1700000000);

		return $time;
	}//end timeFactory()

	private function orgConfig(): PortalOrganisationConfigService {
		$orgConfig = $this->createMock(PortalOrganisationConfigService::class);
		$orgConfig->method('resolve')->willReturn(['organisationName' => 'Test Org']);

		return $orgConfig;
	}//end orgConfig()

	private function urlGenerator(): IURLGenerator {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path) => 'https://cloud.example' . $path
		);

		return $urlGenerator;
	}//end urlGenerator()

	private function config(int $threshold = 3): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn((string)$threshold);

		return $config;
	}//end config()

	/**
	 * A reader stub: `portalAccount` lookups return the given account row;
	 * `portalNotification` lookups return the given prior-history rows.
	 */
	private function reader(?array $account, array $notificationHistory = []): PortalObjectReader {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			function (
				string $register,
				string $schema,
				string $scopeField,
				string $subjectRef,
				string $organisation = '',
				int $limit = 200,
			) use ($account, $notificationHistory) {
				if ($schema === 'portalAccount') {
					return ($account === null) ? [] : [$account];
				}

				return $notificationHistory;
			}
		);

		return $reader;
	}//end reader()

	/**
	 * A writer capturing createObject()/updateObject() calls into the given
	 * arrays (by reference); createObject always succeeds (returns the data).
	 */
	private function writer(array &$created, array &$updated): PortalObjectWriter {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use (&$created) {
				$created[] = compact('register', 'schema', 'scopeField', 'subjectRef', 'organisation', 'data');
				return $data;
			}
		);
		$writer->method('updateObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data) use (&$updated) {
				$updated[] = compact('register', 'schema', 'scopeField', 'subjectRef', 'organisation', 'id', 'data');
				return $data;
			}
		);

		return $writer;
	}//end writer()

	/**
	 * An IMailer stub capturing the rendered subject/body/recipient; `$outcome`
	 * true = send() returns no failed recipients, false = throws.
	 */
	private function mailer(array &$captured, bool $outcome = true): IMailer {
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturn(true);

		$message = $this->createMock(IMessage::class);
		$message->method('setSubject')->willReturnCallback(
			function (string $subject) use (&$captured, $message) {
				$captured['subject'] = $subject;
				return $message;
			}
		);
		$message->method('setPlainBody')->willReturnCallback(
			function (string $body) use (&$captured, $message) {
				$captured['body'] = $body;
				return $message;
			}
		);
		$message->method('setTo')->willReturnCallback(
			function (array $to) use (&$captured, $message) {
				$captured['to'] = $to;
				return $message;
			}
		);

		$mailer->method('createMessage')->willReturn($message);

		if ($outcome === true) {
			$mailer->method('send')->willReturn([]);
		} else {
			$mailer->method('send')->willThrowException(new RuntimeException('SMTP down'));
		}

		return $mailer;
	}//end mailer()

	private function invokeRun(NotificationDispatchJob $job, mixed $argument): void {
		$method = new ReflectionMethod($job, 'run');
		$method->invoke($job, $argument);
	}//end invokeRun()

	public function testSendsAContentFreeEmailAndLogsASentAttempt(): void {
		$account = ['@self' => ['id' => 'account-1'], 'email' => 'supplier@example.org'];

		$created = [];
		$updated = [];
		$captured = [];

		$job = new NotificationDispatchJob(
			$this->timeFactory(),
			$this->reader(account: $account),
			$this->writer(created: $created, updated: $updated),
			$this->orgConfig(),
			$this->mailer(captured: $captured, outcome: true),
			$this->l10nFactory(),
			$this->urlGenerator(),
			$this->config(),
			$this->createMock(LoggerInterface::class)
		);

		$this->invokeRun($job, self::ARGUMENT);

		// Content-free: only the org name and deep link appear — never the
		// rule key, app id, or any case content.
		$this->assertSame(['supplier@example.org'], $captured['to']);
		$this->assertStringContainsString('Test Org', $captured['subject']);
		$this->assertStringContainsString('Test Org', $captured['body']);
		$this->assertStringContainsString('https://cloud.example/portal?org=org-1', $captured['body']);
		$this->assertStringNotContainsString('message.created', $captured['subject'] . $captured['body']);
		$this->assertStringNotContainsString('portaliq', $captured['subject'] . $captured['body']);
		// Bilingual (NL first, EN second), mirroring SubmissionReceiptService.
		$this->assertStringContainsString('[nl] ', $captured['body']);
		$this->assertStringContainsString('[en] ', $captured['body']);

		$this->assertCount(1, $created);
		$this->assertSame('portalNotification', $created[0]['schema']);
		$this->assertSame('accountRef', $created[0]['scopeField']);
		$this->assertSame('account-1', $created[0]['subjectRef']);
		$this->assertSame('sent', $created[0]['data']['status']);
		$this->assertSame(0, $created[0]['data']['attempts']);
		$this->assertSame('email', $created[0]['data']['channel']);
		$this->assertSame('message.created', $created[0]['data']['ruleKey']);

		// No fallback flag write — nothing failed.
		$this->assertCount(0, $updated);

	}//end testSendsAContentFreeEmailAndLogsASentAttempt()

	public function testNoAccountFoundSkipsSilentlyWithoutSendingOrLogging(): void {
		$created = [];
		$updated = [];
		$captured = [];

		$job = new NotificationDispatchJob(
			$this->timeFactory(),
			$this->reader(account: null),
			$this->writer(created: $created, updated: $updated),
			$this->orgConfig(),
			$this->mailer(captured: $captured, outcome: true),
			$this->l10nFactory(),
			$this->urlGenerator(),
			$this->config(),
			$this->createMock(LoggerInterface::class)
		);

		$this->invokeRun($job, self::ARGUMENT);

		$this->assertCount(0, $created);
		$this->assertArrayNotHasKey('to', $captured);

	}//end testNoAccountFoundSkipsSilentlyWithoutSendingOrLogging()

	public function testNoEmailRecordsAFailedAttemptWithoutSending(): void {
		$account = ['@self' => ['id' => 'account-1'], 'email' => ''];

		$created = [];
		$updated = [];
		$captured = [];

		$job = new NotificationDispatchJob(
			$this->timeFactory(),
			$this->reader(account: $account),
			$this->writer(created: $created, updated: $updated),
			$this->orgConfig(),
			$this->mailer(captured: $captured, outcome: true),
			$this->l10nFactory(),
			$this->urlGenerator(),
			$this->config(),
			$this->createMock(LoggerInterface::class)
		);

		$this->invokeRun($job, self::ARGUMENT);

		$this->assertArrayNotHasKey('to', $captured);
		$this->assertCount(1, $created);
		$this->assertSame('failed', $created[0]['data']['status']);
		$this->assertSame(1, $created[0]['data']['attempts']);

	}//end testNoEmailRecordsAFailedAttemptWithoutSending()

	public function testAFailedSendIncrementsTheStreakCarriedFromPriorHistory(): void {
		$account = ['@self' => ['id' => 'account-1'], 'email' => 'supplier@example.org'];
		$history = [
			['ruleKey' => 'message.created', 'status' => 'failed', 'attempts' => 2, 'lastAttemptAt' => '2026-01-01T00:00:00+00:00'],
		];

		$created = [];
		$updated = [];
		$captured = [];

		$job = new NotificationDispatchJob(
			$this->timeFactory(),
			$this->reader(account: $account, notificationHistory: $history),
			$this->writer(created: $created, updated: $updated),
			$this->orgConfig(),
			$this->mailer(captured: $captured, outcome: false),
			$this->l10nFactory(),
			$this->urlGenerator(),
			$this->config(threshold: 5),
			$this->createMock(LoggerInterface::class)
		);

		$this->invokeRun($job, self::ARGUMENT);

		$this->assertSame('failed', $created[0]['data']['status']);
		$this->assertSame(3, $created[0]['data']['attempts']);
		// Below the (raised) threshold of 5 — no fallback flag yet.
		$this->assertCount(0, $updated);

	}//end testAFailedSendIncrementsTheStreakCarriedFromPriorHistory()

	public function testReachingTheThresholdFlagsNeedsAlternativeContact(): void {
		$account = ['@self' => ['id' => 'account-1'], 'email' => 'supplier@example.org'];
		$history = [
			['ruleKey' => 'message.created', 'status' => 'failed', 'attempts' => 2, 'lastAttemptAt' => '2026-01-01T00:00:00+00:00'],
		];

		$created = [];
		$updated = [];
		$captured = [];

		$job = new NotificationDispatchJob(
			$this->timeFactory(),
			$this->reader(account: $account, notificationHistory: $history),
			$this->writer(created: $created, updated: $updated),
			$this->orgConfig(),
			$this->mailer(captured: $captured, outcome: false),
			$this->l10nFactory(),
			$this->urlGenerator(),
			$this->config(threshold: 3),
			$this->createMock(LoggerInterface::class)
		);

		$this->invokeRun($job, self::ARGUMENT);

		// The 3rd consecutive failure reaches the (default) threshold of 3.
		$this->assertSame(3, $created[0]['data']['attempts']);
		$this->assertCount(1, $updated);
		$this->assertSame('portalAccount', $updated[0]['schema']);
		$this->assertSame('s1', $updated[0]['subjectRef']);
		$this->assertSame('account-1', $updated[0]['id']);
		$this->assertTrue($updated[0]['data']['needsAlternativeContact']);

	}//end testReachingTheThresholdFlagsNeedsAlternativeContact()

	public function testASuccessfulSendClearsAnExistingFallbackFlag(): void {
		$account = ['@self' => ['id' => 'account-1'], 'email' => 'supplier@example.org', 'needsAlternativeContact' => true];

		$created = [];
		$updated = [];
		$captured = [];

		$job = new NotificationDispatchJob(
			$this->timeFactory(),
			$this->reader(account: $account),
			$this->writer(created: $created, updated: $updated),
			$this->orgConfig(),
			$this->mailer(captured: $captured, outcome: true),
			$this->l10nFactory(),
			$this->urlGenerator(),
			$this->config(),
			$this->createMock(LoggerInterface::class)
		);

		$this->invokeRun($job, self::ARGUMENT);

		$this->assertSame('sent', $created[0]['data']['status']);
		$this->assertCount(1, $updated);
		$this->assertFalse($updated[0]['data']['needsAlternativeContact']);

	}//end testASuccessfulSendClearsAnExistingFallbackFlag()

	public function testInvalidArgumentIsIgnoredWithoutThrowing(): void {
		$created = [];
		$updated = [];
		$captured = [];

		$job = new NotificationDispatchJob(
			$this->timeFactory(),
			$this->reader(account: null),
			$this->writer(created: $created, updated: $updated),
			$this->orgConfig(),
			$this->mailer(captured: $captured, outcome: true),
			$this->l10nFactory(),
			$this->urlGenerator(),
			$this->config(),
			$this->createMock(LoggerInterface::class)
		);

		$this->invokeRun($job, 'not-an-array');
		$this->addToAssertionCount(1);

	}//end testInvalidArgumentIsIgnoredWithoutThrowing()

	/**
	 * Every failure mode — here, the account reader throwing — is caught and
	 * logged; the job never lets an exception escape to the NC cron runner.
	 */
	public function testAnExceptionAnywhereIsNeverPropagated(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willThrowException(new RuntimeException('OR is down'));

		$created = [];
		$updated = [];
		$captured = [];

		$job = new NotificationDispatchJob(
			$this->timeFactory(),
			$reader,
			$this->writer(created: $created, updated: $updated),
			$this->orgConfig(),
			$this->mailer(captured: $captured, outcome: true),
			$this->l10nFactory(),
			$this->urlGenerator(),
			$this->config(),
			$this->createMock(LoggerInterface::class)
		);

		$this->invokeRun($job, self::ARGUMENT);
		$this->addToAssertionCount(1);

	}//end testAnExceptionAnywhereIsNeverPropagated()
}//end class
