<?php

/**
 * ConnectionReporter unit tests.
 *
 * The reporter tells integriq's connection registry what portaliq can see
 * about its geography database and its login brokers. Every test guards one
 * way it could quietly stop telling the truth: a report sent before the
 * refresh that retires it, a busy login page flooding the registry, a
 * listener's failure turned into a failed save, or an event sent when
 * integriq is not installed.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service\Connection
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-002-a-geography-save-refreshes-and-a-refresh-or-a-failed-open-reports
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-003-a-broker-call-reports-what-the-broker-answered-throttled
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Connection;

use OCA\Integriq\Event\ConnectionRefreshRequestedEvent;
use OCA\Integriq\Event\ConnectionStatusReportedEvent;
use OCA\Portaliq\Service\Connection\ConnectionReporter;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Unit tests for ConnectionReporter.
 *
 * @covers \OCA\Portaliq\Service\Connection\ConnectionReporter
 */
class ConnectionReporterTest extends TestCase {

	/**
	 * A start time for the clock.
	 *
	 * @var int
	 */
	private const T0 = 1788000000;

	/**
	 * Mocked event dispatcher.
	 *
	 * @var IEventDispatcher&MockObject
	 */
	private IEventDispatcher&MockObject $dispatcher;

	/**
	 * Mocked app config over $stored.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mocked clock over $now.
	 *
	 * @var ITimeFactory&MockObject
	 */
	private ITimeFactory&MockObject $clock;

	/**
	 * Mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Every event handed to the dispatcher, in order.
	 *
	 * @var array<int, Event>
	 */
	private array $sent = [];

	/**
	 * The app-config values the double holds.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * The time the clock answers.
	 *
	 * @var int
	 */
	private int $now = self::T0;

	/**
	 * Set up the fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->sent   = [];
		$this->stored = [];
		$this->now    = self::T0;

		$this->dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->sent[] = $event;
			}
		);

		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->stored[$key] ?? $default)
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$key] = $value;
				return true;
			}
		);
		$this->appConfig->method('deleteKey')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->stored[$key]);
			}
		);

		$this->clock = $this->createMock(originalClassName: ITimeFactory::class);
		$this->clock->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * The reporter as production builds it.
	 *
	 * @return ConnectionReporter
	 */
	private function reporter(): ConnectionReporter {
		return new ConnectionReporter(
			eventDispatcher: $this->dispatcher,
			appConfig: $this->appConfig,
			timeFactory: $this->clock,
			logger: $this->logger,
		);
	}//end reporter()

	/**
	 * The reporter as it behaves on an instance without integriq.
	 *
	 * Only the class lookup is replaced. The stubs make both event classes
	 * resolvable in this process, so absence is simulated at the one seam
	 * that asks.
	 *
	 * @return ConnectionReporter
	 */
	private function reporterWithoutIntegriq(): ConnectionReporter {
		return new class($this->dispatcher, $this->appConfig, $this->clock, $this->logger) extends ConnectionReporter {

			/**
			 * Integriq is not installed, so no class resolves.
			 *
			 * @param string $eventClass The class name asked for.
			 *
			 * @return string|null Always null.
			 */
			protected function resolveEventClass(string $eventClass): ?string {
				return null;
			}//end resolveEventClass()
		};
	}//end reporterWithoutIntegriq()

	/**
	 * Everything sent, as "refresh:key" or "report:key:status".
	 *
	 * @return array<int, string>
	 */
	private function sentSummary(): array {
		return array_map(
			static function (Event $event): string {
				if ($event instanceof ConnectionStatusReportedEvent) {
					return 'report:' . $event->key . ':' . $event->status;
				}

				if ($event instanceof ConnectionRefreshRequestedEvent) {
					return 'refresh:' . $event->key;
				}

				return get_class($event);
			},
			$this->sent
		);
	}//end sentSummary()

	/**
	 * A geography save refreshes first and reports second, as portaliq, for geo-db.
	 *
	 * Under hydra#674 a refresh retires every older observation, so the other
	 * order would have integriq throw the report away.
	 *
	 * @return void
	 */
	public function testAGeographySaveRefreshesBeforeItReports(): void {
		$sent = $this->reporter()->geoSettingsSaved(settings: ['provider' => 'none'], status: ['present' => false]);

		$this->assertTrue(condition: $sent);
		$this->assertSame(expected: ['refresh:geo-db', 'report:geo-db:unconfigured'], actual: $this->sentSummary());
		$this->assertSame(expected: 'portaliq', actual: $this->sent[0]->app);
		$this->assertSame(expected: 'portaliq', actual: $this->sent[1]->app);
		$this->assertStringStartsWith(prefix: 'Geography is switched off.', string: $this->sent[1]->message);
	}//end testAGeographySaveRefreshesBeforeItReports()

	/**
	 * A save reports at once, even right after a throttled report with another status.
	 *
	 * An admin who fixed the setting must not wait out a window a visitor started.
	 *
	 * @return void
	 */
	public function testASaveIgnoresTheThrottle(): void {
		$reporter = $this->reporter();

		$this->assertTrue(condition: $reporter->geoDatabaseUnreadable());
		$this->now += 10;
		$this->assertTrue(
			condition: $reporter->geoSettingsSaved(
				settings: ['provider' => 'dbip'],
				status: ['present' => true, 'metadata' => ['provider' => 'dbip', 'fetchedAt' => '2026-09-01T00:00:00Z']]
			)
		);

		$this->assertSame(
			expected: ['report:geo-db:error', 'refresh:geo-db', 'report:geo-db:configured'],
			actual: $this->sentSummary()
		);
	}//end testASaveIgnoresTheThrottle()

	/**
	 * A refresh reports its outcome without a refresh event: it changes no settings.
	 *
	 * @return void
	 */
	public function testARefreshReportsWithoutARefreshEvent(): void {
		$this->assertTrue(
			condition: $this->reporter()->geoRefreshed(
				result: ['status' => 'failed', 'provider' => 'dbip', 'message' => 'Refresh from dbip failed: no route.']
			)
		);

		$this->assertSame(expected: ['report:geo-db:error'], actual: $this->sentSummary());
		$this->assertSame(expected: 'Refresh from dbip failed: no route.', actual: $this->sent[0]->message);
	}//end testARefreshReportsWithoutARefreshEvent()

	/**
	 * A busy login page reports once per window with the same status.
	 *
	 * @return void
	 */
	public function testTheSameStatusReportsOncePerHour(): void {
		$reporter = $this->reporter();
		$endpoint = 'https://broker.example/token';

		for ($login = 0; $login < 50; $login++) {
			$reporter->oidcExchangeAnswered(tokenEndpoint: $endpoint, httpStatus: 200, oauthError: '', hasToken: true);
			$this->now += 60;
		}

		// 50 minutes passed: still one.
		$this->assertSame(expected: ['report:oidc:configured'], actual: $this->sentSummary());

		$this->now = (self::T0 + ConnectionReporter::REPEAT_SECONDS);
		$this->assertTrue(condition: $reporter->oidcExchangeAnswered(tokenEndpoint: $endpoint, httpStatus: 200, oauthError: '', hasToken: true));
		$this->assertCount(expectedCount: 2, haystack: $this->sent);
	}//end testTheSameStatusReportsOncePerHour()

	/**
	 * A different status waits five minutes, so two brokers that disagree cannot flip the row per login.
	 *
	 * @return void
	 */
	public function testADifferentStatusWaitsFiveMinutes(): void {
		$reporter = $this->reporter();

		$this->assertTrue(condition: $reporter->oidcDiscoveryFailed(issuer: 'https://down.example', answered: false));
		$this->now += (ConnectionReporter::CHANGE_SECONDS - 1);
		$this->assertFalse(
			condition: $reporter->oidcExchangeAnswered(tokenEndpoint: 'https://up.example/token', httpStatus: 200, oauthError: '', hasToken: true)
		);
		$this->now += 1;
		$this->assertTrue(
			condition: $reporter->oidcExchangeAnswered(tokenEndpoint: 'https://up.example/token', httpStatus: 200, oauthError: '', hasToken: true)
		);

		$this->assertSame(expected: ['report:oidc:error', 'report:oidc:configured'], actual: $this->sentSummary());
	}//end testADifferentStatusWaitsFiveMinutes()

	/**
	 * An answer about one login reports nothing and leaves the memory alone.
	 *
	 * @return void
	 */
	public function testAnExpiredCodeReportsNothing(): void {
		$this->assertFalse(
			condition: $this->reporter()->oidcExchangeAnswered(
				tokenEndpoint: 'https://broker.example/token',
				httpStatus: 400,
				oauthError: 'invalid_grant',
				hasToken: false
			)
		);

		$this->assertSame(expected: [], actual: $this->sent);
		$this->assertSame(expected: [], actual: $this->stored);
	}//end testAnExpiredCodeReportsNothing()

	/**
	 * Without integriq nothing is sent, stored or logged.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqNothingIsSentStoredOrLogged(): void {
		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$this->logger->expects($this->never())->method('warning');
		$this->appConfig->expects($this->never())->method('setValueString');
		$this->appConfig->expects($this->never())->method('deleteKey');

		$reporter = $this->reporterWithoutIntegriq();

		$this->assertFalse(condition: $reporter->geoSettingsSaved(settings: ['provider' => 'none'], status: []));
		$this->assertFalse(condition: $reporter->geoRefreshed(result: ['status' => 'disabled']));
		$this->assertFalse(condition: $reporter->geoDatabaseUnreadable());
		$this->assertFalse(condition: $reporter->oidcDiscoveryFailed(issuer: 'https://broker.example', answered: false));
		$this->assertFalse(
			condition: $reporter->oidcExchangeAnswered(tokenEndpoint: 'https://broker.example/token', httpStatus: 200, oauthError: '', hasToken: true)
		);
	}//end testWithoutIntegriqNothingIsSentStoredOrLogged()

	/**
	 * A listener that throws never escapes into the save, refresh or login.
	 *
	 * @return void
	 */
	public function testAThrowingListenerNeverEscapes(): void {
		$dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willThrowException(new RuntimeException('registry down'));
		$this->logger->expects($this->exactly(count: 2))->method('warning')
			->with($this->stringContains(string: 'could not send'), $this->arrayHasKey(key: 'key'));

		$reporter = new ConnectionReporter(eventDispatcher: $dispatcher, appConfig: $this->appConfig, timeFactory: $this->clock, logger: $this->logger);

		$this->assertFalse(condition: $reporter->geoRefreshed(result: ['status' => 'disabled']));
		$this->assertFalse(condition: $reporter->oidcDiscoveryFailed(issuer: 'https://broker.example', answered: true));
		$this->assertSame(expected: [], actual: $this->stored, message: 'a report that was not sent must not start a window');
	}//end testAThrowingListenerNeverEscapes()

	/**
	 * The class lookup answers null for a class nobody ships, and the class for a stub.
	 *
	 * This is the real guard, not the test double above.
	 *
	 * @return void
	 */
	public function testTheLookupAnswersNullForAnAbsentClass(): void {
		$method = new ReflectionMethod(ConnectionReporter::class, 'resolveEventClass');

		$this->assertNull(actual: $method->invoke($this->reporter(), 'OCA\\Nobody\\Event\\ShipsThisEvent'));
		$this->assertSame(
			expected: '\\' . ConnectionReporter::STATUS_EVENT,
			actual: $method->invoke($this->reporter(), ConnectionReporter::STATUS_EVENT)
		);
	}//end testTheLookupAnswersNullForAnAbsentClass()

	/**
	 * The event names are the ones integriq ships.
	 *
	 * A string class name is exactly the reference that rots into a silent
	 * no-op after a rename, so it is compared to the stubs' real names.
	 *
	 * @return void
	 */
	public function testTheEventNamesAreTheContractNames(): void {
		$this->assertSame(expected: ConnectionStatusReportedEvent::class, actual: ConnectionReporter::STATUS_EVENT);
		$this->assertSame(expected: ConnectionRefreshRequestedEvent::class, actual: ConnectionReporter::REFRESH_EVENT);
	}//end testTheEventNamesAreTheContractNames()

	/**
	 * The keys the reporter sends are exactly the ones the declaration lists.
	 *
	 * Integriq refuses a report for a key nobody declared, with only a warning in its log.
	 *
	 * @return void
	 */
	public function testTheKeysAreTheDeclaredOnes(): void {
		$declaration = json_decode(
			(string) file_get_contents(dirname(__DIR__, 4) . '/lib/Settings/connections.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);

		$this->assertSame(expected: array_column($declaration['connections'], 'key'), actual: ConnectionReporter::KEYS);
	}//end testTheKeysAreTheDeclaredOnes()
}//end class
