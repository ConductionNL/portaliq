<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\BackgroundJob;

use OCA\Portaliq\BackgroundJob\NotificationDispatchJob;
use OCA\Portaliq\Service\Notifications\NotificationChannels;
use OCA\Portaliq\Service\Notifications\PushDeliveryService;
use OCA\Portaliq\Service\PortalDeepLinkBuilder;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\Notifications\PortalNoticeLanguage;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalResolver;
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
		// A FOREIGN contributing app: the dispatch job is fleet-generic, and
		// naming portaliq here would make the "never leak the app id" assertion
		// indistinguishable from the portal's own route in the deep link
		// (WOO-570).
		'appId' => 'procest',
		'ruleKey' => 'message.created',
	];

	/**
	 * Every translation call the job makes: [locale, key, parameters]. The
	 * privacy contract is asserted on THIS — what reaches the l10n layer is
	 * the only thing that can reach the mail — not on the rendered text.
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<int, mixed>}>
	 */
	private array $translated = [];

	private function l10nFactory(): IFactory {
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturnCallback(
			function (string $app, $long = null, $locale = null) {
				$l10n = $this->createMock(IL10N::class);
				$l10n->method('t')->willReturnCallback(
					function (string $text, $parameters = []) use ($long) {
						$this->translated[] = [(string)$long, $text, (array)$parameters];

						return '[' . $long . '] ' . vsprintf($text, $parameters);
					}
				);

				return $l10n;
			}
		);

		return $factory;
	}//end l10nFactory()

	/**
	 * Portaliq's notice language over the stub translations, for an
	 * organisation whose portal names the given locale first (or none).
	 *
	 * @param string|null $portalLocale The portal's first locale, or null for no portal.
	 *
	 * @return PortalNoticeLanguage
	 */
	private function language(?string $portalLocale = null): PortalNoticeLanguage {
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolveByOrganisation')->willReturn($portalLocale === null ? null : ['locales' => [$portalLocale]]);

		return new PortalNoticeLanguage($this->l10nFactory(), $portals);
	}//end language()

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

	/**
	 * The REAL deep-link builder over a route table rendered the way Nextcloud
	 * renders it without pretty URLs (`/index.php` in front) — the default of
	 * many installations and the case the old `getAbsoluteURL('/portal')` got
	 * wrong: it produced a path no deployment serves, so the only link in the
	 * mail was a 404 (WOO-570). Using the real builder here keeps this test
	 * honest about what the resident receives.
	 */
	private function deepLinks(): PortalDeepLinkBuilder {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnCallback(
			static function (string $route, array $arguments = []): string {
				// Only the site's route answers the site's path: a link built
				// from any other route would show up in the asserted body.
				if ($route !== 'portaliq.portalPage.site') {
					return '/index.php/apps/portaliq/' . $route;
				}

				$path = '/index.php/apps/portaliq/site';
				if ($arguments === []) {
					return $path;
				}

				return $path . '?' . http_build_query($arguments);
			}
		);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path) => 'https://cloud.example' . $path
		);

		return new PortalDeepLinkBuilder($urlGenerator);
	}//end deepLinks()

	private function config(int $threshold = 3): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn((string)$threshold);

		return $config;
	}//end config()

	/**
	 * A reader stub: `portalAccount` lookups return the given account row;
	 * `portalNotification` lookups return the given prior-history rows.
	 */
	private function reader(?array $account, array $notificationHistory = [], array $subscriptions = []): PortalObjectReader {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			function (
				string $register,
				string $schema,
				string $scopeField,
				string $subjectRef,
				string $organisation = '',
				int $limit = 200,
			) use ($account, $notificationHistory, $subscriptions) {
				if ($schema === 'portalAccount') {
					return ($account === null) ? [] : [$account];
				}

				if ($schema === 'pushSubscription') {
					return $subscriptions;
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
			$this->language(),
			$this->deepLinks(),
			$this->config(),
			$this->createMock(LoggerInterface::class)
		);

		$this->invokeRun($job, self::ARGUMENT);

		// Content-free: only the org name and deep link appear — never the
		// rule key, app id, or any case content.
		$this->assertSame(['supplier@example.org'], $captured['to']);
		$this->assertStringContainsString('Test Org', $captured['subject']);
		$this->assertStringContainsString('Test Org', $captured['body']);
		$this->assertStringContainsString('https://cloud.example/index.php/apps/portaliq/site?org=org-1', $captured['body']);
		$this->assertStringNotContainsString('message.created', $captured['subject'] . $captured['body']);
		// The CONTRIBUTING app, the rule key and the case content must not be
		// named — that is what "content-free" protects. A ban on words in the
		// rendered text cannot prove that (the deep link legitimately contains
		// portaliq's own route, and the job never receives the appId as text
		// anyway), so the contract is asserted STRUCTURALLY: the only values
		// that ever reach the translation layer are the organisation name and
		// the deep link (review of WOO-570 / #498).
		$this->assertNotSame([], $this->translated);
		foreach ($this->translated as [$locale, $key, $parameters]) {
			$this->assertSame('nl', $locale);
			$this->assertContains(
				$parameters,
				[['Test Org'], ['Test Org', 'https://cloud.example/index.php/apps/portaliq/site?org=org-1']],
				'translation "' . $key . '" received a parameter that is neither the organisation name nor the deep link'
			);
		}
		// One language, the portal's: Dutch when the portal names none.
		$this->assertStringStartsWith('[nl] ', $captured['subject']);
		$this->assertStringStartsWith('[nl] ', $captured['body']);
		$this->assertStringNotContainsString('[en] ', $captured['subject'] . $captured['body']);
		$this->assertStringNotContainsString(' / ', $captured['subject']);

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

	/**
	 * The e-mail is written once, in the language of the resident's portal.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/resident-sees-words-not-codes/specs/portal-notifications-and-preferences/spec.md#requirement-a-receipt-a-notification-mail-and-a-task-notice-are-written-in-the-portals-language-only
	 */
	public function testTheEmailIsInThePortalsLanguageOnly(): void {
		$created = [];
		$updated = [];
		$captured = [];
		$job = new NotificationDispatchJob(
			$this->timeFactory(),
			$this->reader(account: ['@self' => ['id' => 'account-1'], 'email' => 'supplier@example.org']),
			$this->writer(created: $created, updated: $updated),
			$this->orgConfig(),
			$this->mailer(captured: $captured, outcome: true),
			$this->language(portalLocale: 'en'),
			$this->deepLinks(),
			$this->config(),
			$this->createMock(LoggerInterface::class)
		);

		$this->invokeRun($job, self::ARGUMENT);

		$this->assertSame('[en] You have a new message in the portal of Test Org', $captured['subject']);
		$this->assertStringStartsWith('[en] ', $captured['body']);
		$this->assertStringNotContainsString('[nl] ', $captured['subject'] . $captured['body']);
		$this->assertSame(['en'], array_values(array_unique(array_column($this->translated, 0))));
	}//end testTheEmailIsInThePortalsLanguageOnly()

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
			$this->language(),
			$this->deepLinks(),
			$this->config(),
			$this->createMock(LoggerInterface::class)
		);

		$this->invokeRun($job, self::ARGUMENT);

		$this->assertCount(0, $created);
		$this->assertArrayNotHasKey('to', $captured);

	}//end testNoAccountFoundSkipsSilentlyWithoutSendingOrLogging()

	/**
	 * notification-preferences-per-role: an account that opted out of the
	 * email channel is skipped the SAME way a missing account is — no send,
	 * no `portalNotification` row, and (unlike a missing email address)
	 * nothing that could ever count toward `needsAlternativeContact`.
	 *
	 * @return void
	 */
	public function testAccountOptedOutOfEmailChannelSkipsSilentlyWithoutSendingOrLogging(): void {
		$account = ['@self' => ['id' => 'account-1'], 'email' => 'supplier@example.org', 'notificationChannels' => ['email' => false]];

		$created = [];
		$updated = [];
		$captured = [];

		$job = new NotificationDispatchJob(
			$this->timeFactory(),
			$this->reader(account: $account),
			$this->writer(created: $created, updated: $updated),
			$this->orgConfig(),
			$this->mailer(captured: $captured, outcome: true),
			$this->language(),
			$this->deepLinks(),
			$this->config(),
			$this->createMock(LoggerInterface::class)
		);

		$this->invokeRun($job, self::ARGUMENT);

		$this->assertCount(expectedCount: 0, haystack: $created);
		$this->assertCount(expectedCount: 0, haystack: $updated);
		$this->assertArrayNotHasKey(key: 'to', array: $captured);

	}//end testAccountOptedOutOfEmailChannelSkipsSilentlyWithoutSendingOrLogging()

	/**
	 * A missing `notificationChannels` key — every account that existed
	 * before this property did — is opted IN (fail-open), so dispatch
	 * proceeds exactly as it always has. This is the same fixture as
	 * `testSendsAContentFreeEmailAndLogsASentAttempt`, unmodified, asserted
	 * again here to pin the regression this change must never cause.
	 *
	 * @return void
	 */
	public function testAnAccountWithNoChannelPreferenceIsSentToAsBefore(): void {
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
			$this->language(),
			$this->deepLinks(),
			$this->config(),
			$this->createMock(LoggerInterface::class)
		);

		$this->invokeRun($job, self::ARGUMENT);

		$this->assertSame(expected: ['supplier@example.org'], actual: $captured['to']);
		$this->assertCount(expectedCount: 1, haystack: $created);
		$this->assertSame(expected: 'sent', actual: $created[0]['data']['status']);

	}//end testAnAccountWithNoChannelPreferenceIsSentToAsBefore()

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
			$this->language(),
			$this->deepLinks(),
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
			$this->language(),
			$this->deepLinks(),
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
			$this->language(),
			$this->deepLinks(),
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
			$this->language(),
			$this->deepLinks(),
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
			$this->language(),
			$this->deepLinks(),
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
			$this->language(),
			$this->deepLinks(),
			$this->config(),
			$this->createMock(LoggerInterface::class)
		);

		$this->invokeRun($job, self::ARGUMENT);
		$this->addToAssertionCount(1);

	}//end testAnExceptionAnywhereIsNeverPropagated()

	/**
	 * A job over the given account, capturing mail, log rows and pushes.
	 *
	 * @param array<string, mixed>             $account       The account.
	 * @param array<int, array<string, mixed>> $created       Captured log rows.
	 * @param array<string, mixed>             $captured      Captured mail.
	 * @param array<int, array<string, mixed>> $pushes        Captured pushes.
	 * @param array<int, array<string, mixed>> $subscriptions The account's push subscriptions.
	 * @param bool                             $canDeliver    Whether the bound transport reaches a device.
	 *
	 * @return NotificationDispatchJob
	 */
	private function jobWithPush(array $account, array &$created, array &$captured, array &$pushes, array $subscriptions = [], bool $canDeliver = true): NotificationDispatchJob {
		$updated = [];
		$push = $this->createMock(PushDeliveryService::class);
		$push->method('canDeliver')->willReturn($canDeliver);
		$push->method('deliver')->willReturnCallback(
			function (string $subjectRef, string $title, string $body) use (&$pushes): bool {
				$pushes[] = compact('subjectRef', 'title', 'body');
				return true;
			}
		);

		return new NotificationDispatchJob(
			$this->timeFactory(),
			$this->reader(account: $account, subscriptions: $subscriptions),
			$this->writer(created: $created, updated: $updated),
			$this->orgConfig(),
			$this->mailer(captured: $captured, outcome: true),
			$this->language(),
			$this->deepLinks(),
			$this->config(),
			$this->createMock(LoggerInterface::class),
			new NotificationChannels(push: $push, reader: $this->reader(account: $account, subscriptions: $subscriptions))
		);
	}//end jobWithPush()

	/**
	 * A change rule's e-mail names the collection label and links to the
	 * record, and carries no field value (REQ-NAP-005, REQ-NAP-006).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-e-mail-says-what-kind-of-thing-happened-and-nothing-more-req-nap-006
	 */
	public function testChangeRuleTextCarriesNoCaseContent(): void {
		$created = [];
		$captured = [];
		$pushes = [];
		$job = $this->jobWithPush(account: ['@self' => ['id' => 'account-1'], 'email' => 'r@example.org'], created: $created, captured: $captured, pushes: $pushes);

		$this->invokeRun($job, ['ruleKey' => 'case.updated', 'record' => ['app' => 'dossiq', 'collection' => 'mijnZaken', 'id' => 'z-1', 'label' => 'Mijn zaken']] + self::ARGUMENT);

		$this->assertStringContainsString('Something changed on your Mijn zaken in the portal of Test Org', $captured['body']);
		$this->assertStringContainsString('?org=org-1#open=dossiq/mijnZaken/z-1', $captured['body']);
		$this->assertStringNotContainsString('new message', $captured['subject'].$captured['body']);
		$this->assertStringNotContainsString('Afgewezen', $captured['subject'].$captured['body']);
	}//end testChangeRuleTextCarriesNoCaseContent()

	/**
	 * E-mail off for case changes: no e-mail for a change rule.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-resident-chooses-per-kind-and-per-channel-req-nap-007
	 */
	public function testKindEmailOffSendsNoEmail(): void {
		$created = [];
		$captured = [];
		$pushes = [];
		$account = ['@self' => ['id' => 'account-1'], 'email' => 'r@example.org', 'notificationPreferences' => ['case.updated' => ['email' => false]]];
		$job = $this->jobWithPush(account: $account, created: $created, captured: $captured, pushes: $pushes);

		$this->invokeRun($job, ['ruleKey' => 'case.updated', 'record' => ['app' => 'dossiq', 'collection' => 'mijnZaken', 'id' => 'z-1', 'label' => 'Mijn zaken']] + self::ARGUMENT);

		$this->assertArrayNotHasKey('to', $captured);
		$this->assertSame([], $created);

		// The same account still gets e-mail for a new message.
		$this->invokeRun($job, self::ARGUMENT);
		$this->assertSame(['r@example.org'], $captured['to']);
	}//end testKindEmailOffSendsNoEmail()

	/**
	 * Push on for new messages, with a registered device: a push, logged with
	 * channel `push`. Without a device, no push is attempted.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-resident-chooses-per-kind-and-per-channel-req-nap-007
	 */
	public function testKindPushOnSendsAPush(): void {
		$created = [];
		$captured = [];
		$pushes = [];
		$account = ['@self' => ['id' => 'account-1'], 'email' => 'r@example.org', 'notificationPreferences' => ['message.created' => ['email' => false, 'push' => true]]];
		$job = $this->jobWithPush(account: $account, created: $created, captured: $captured, pushes: $pushes, subscriptions: [['endpoint' => 'https://push.example/1']]);

		$this->invokeRun($job, self::ARGUMENT);

		$this->assertArrayNotHasKey('to', $captured, 'e-mail is off for messages');
		$this->assertCount(1, $pushes);
		$this->assertSame('s1', $pushes[0]['subjectRef']);
		$this->assertStringContainsString('Test Org', $pushes[0]['body']);
		$this->assertCount(1, $created);
		$this->assertSame('push', $created[0]['data']['channel']);
		$this->assertSame('sent', $created[0]['data']['status']);

		$none = [];
		$noPush = [];
		$noMail = [];
		$withoutDevice = $this->jobWithPush(account: $account, created: $none, captured: $noMail, pushes: $noPush);
		$this->invokeRun($withoutDevice, self::ARGUMENT);
		$this->assertSame([], $noPush);
	}//end testKindPushOnSendsAPush()

	/**
	 * The account-wide e-mail opt-out still wins over a kind's e-mail choice.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-resident-chooses-per-kind-and-per-channel-req-nap-007
	 */
	public function testGlobalEmailOptOutStillWins(): void {
		$created = [];
		$captured = [];
		$pushes = [];
		$account = ['@self' => ['id' => 'account-1'], 'email' => 'r@example.org', 'notificationChannels' => ['email' => false], 'notificationPreferences' => ['message.created' => ['email' => true]]];
		$job = $this->jobWithPush(account: $account, created: $created, captured: $captured, pushes: $pushes);

		$this->invokeRun($job, self::ARGUMENT);

		$this->assertArrayNotHasKey('to', $captured);
	}//end testGlobalEmailOptOutStillWins()

	/**
	 * While the bound push transport cannot reach a device (the interim
	 * logging sender), no push is attempted and none is recorded as sent;
	 * e-mail still goes out.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-resident-chooses-per-kind-and-per-channel-req-nap-007
	 */
	public function testNoPushIsRecordedWhileTheTransportCannotDeliver(): void {
		$created = [];
		$captured = [];
		$pushes = [];
		$account = ['@self' => ['id' => 'account-1'], 'email' => 'r@example.org', 'notificationPreferences' => ['message.created' => ['push' => true]]];
		$job = $this->jobWithPush(account: $account, created: $created, captured: $captured, pushes: $pushes, subscriptions: [['endpoint' => 'https://push.example/1']], canDeliver: false);

		$this->invokeRun($job, self::ARGUMENT);

		$this->assertSame([], $pushes);
		$this->assertSame(['r@example.org'], $captured['to']);
		foreach ($created as $row) {
			$this->assertNotSame('push', $row['data']['channel'] ?? null);
		}
	}//end testNoPushIsRecordedWhileTheTransportCannotDeliver()
}//end class
