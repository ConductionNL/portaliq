<?php

/**
 * The four places that hand an outcome to the connection reporter.
 *
 * The reporter is an optional constructor argument everywhere, so a caller
 * that stops calling it fails nowhere else: the row just stops changing on
 * some other instance. Each test drives the real caller with a reporter
 * double and checks what it was handed, and that the caller's own answer did
 * not change.
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

use OCA\Portaliq\Service\Connection\ConnectionReporter;
use OCA\Portaliq\Service\OidcClientService;
use OCA\Portaliq\Service\PageEditorService;
use OCA\Portaliq\Service\SettingsService;
use OCA\Portaliq\Service\Traffic\Geo\DbIpLiteProvider;
use OCA\Portaliq\Service\Traffic\Geo\GeoDatabaseStore;
use OCA\Portaliq\Service\Traffic\Geo\GeoRefreshService;
use OCA\Portaliq\Service\Traffic\Geo\GeoSettings;
use OCA\Portaliq\Service\Traffic\Geo\MaxMindProvider;
use OCA\Portaliq\Service\Traffic\Geo\MmdbGeoResolver;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\ITempManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Drives each caller of ConnectionReporter.
 *
 * @coversNothing
 */
class ConnectionReportCallersTest extends TestCase {

	/**
	 * The issuer the broker tests use.
	 *
	 * @var string
	 */
	private const ISSUER = 'https://idp.example/realms/portal';

	/**
	 * The token endpoint the broker tests use.
	 *
	 * @var string
	 */
	private const TOKEN_ENDPOINT = 'https://idp.example/realms/portal/token';

	/**
	 * Temporary files to remove.
	 *
	 * @var array<int, string>
	 */
	private array $files = [];

	/**
	 * Remove temporary files.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->files as $file) {
			@unlink($file);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * A reporter double that exposes only the method under watch.
	 *
	 * @param string $method The reporter method a test watches.
	 *
	 * @return ConnectionReporter&MockObject
	 */
	private function reporter(string $method): ConnectionReporter&MockObject {
		return $this->getMockBuilder(ConnectionReporter::class)
			->disableOriginalConstructor()
			->onlyMethods([$method])
			->getMock();
	}//end reporter()

	/**
	 * A settings service over an in-memory app config, as an administrator.
	 *
	 * @param ConnectionReporter $reporter The reporter double.
	 *
	 * @return SettingsService
	 */
	private function settingsService(ConnectionReporter $reporter): SettingsService {
		$stored    = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$stored): string {
				return ($stored[$key] ?? $default);
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$stored): bool {
				$stored[$key] = $value;
				return true;
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);

		$geoRefresh = $this->createMock(GeoRefreshService::class);
		$geoRefresh->method('status')->willReturn(['provider' => 'none', 'present' => false, 'path' => null, 'metadata' => []]);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);

		return new SettingsService(
			$appConfig,
			$appManager,
			$this->createMock(ContainerInterface::class),
			$groupManager,
			$session,
			$this->createMock(LoggerInterface::class),
			$this->createMock(PageEditorService::class),
			new GeoSettings($appConfig),
			$geoRefresh,
			$reporter
		);
	}//end settingsService()

	/**
	 * A geography save hands the SAVED settings and the database status to the reporter.
	 *
	 * @return void
	 */
	public function testAGeographySaveHandsTheSavedSettingsToTheReporter(): void {
		$reporter = $this->reporter(method: 'geoSettingsSaved');
		$reporter->expects($this->once())->method('geoSettingsSaved')
			->with(
				$this->callback(static fn (array $settings): bool => $settings['provider'] === 'none' && array_key_exists('maxmindLicenseKey', $settings) === false),
				$this->callback(static fn (array $status): bool => $status['present'] === false)
			)
			->willReturn(true);

		$result = $this->settingsService(reporter: $reporter)->updateSettings(data: [SettingsService::GEO_KEY => ['provider' => 'none']]);

		$this->assertSame(expected: 'none', actual: $result[SettingsService::GEO_KEY]['provider']);
	}//end testAGeographySaveHandsTheSavedSettingsToTheReporter()

	/**
	 * A save without a geography block sends nothing: it touched no connection.
	 *
	 * @return void
	 */
	public function testASaveWithoutGeographySendsNothing(): void {
		$reporter = $this->reporter(method: 'geoSettingsSaved');
		$reporter->expects($this->never())->method('geoSettingsSaved');

		$this->settingsService(reporter: $reporter)->updateSettings(data: ['register' => 'portaliq']);
	}//end testASaveWithoutGeographySendsNothing()

	/**
	 * A refresh hands its own result to the reporter and returns it unchanged.
	 *
	 * @return void
	 */
	public function testARefreshHandsItsResultToTheReporter(): void {
		$settings = $this->createMock(GeoSettings::class);
		$settings->method('provider')->willReturn('none');

		$reported = [];
		$reporter = $this->reporter(method: 'geoRefreshed');
		$reporter->expects($this->once())->method('geoRefreshed')->willReturnCallback(
			static function (array $result) use (&$reported): bool {
				$reported = $result;
				return true;
			}
		);

		$service = new GeoRefreshService(
			$settings,
			$this->createMock(GeoDatabaseStore::class),
			$this->createMock(DbIpLiteProvider::class),
			$this->createMock(MaxMindProvider::class),
			$this->createMock(ITempManager::class),
			$this->createMock(IJobList::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(LoggerInterface::class),
			$reporter
		);

		$result = $service->refresh();

		$this->assertSame(expected: 'disabled', actual: $result['status']);
		$this->assertSame(expected: $result, actual: $reported);
	}//end testARefreshHandsItsResultToTheReporter()

	/**
	 * A file that will not open is reported once per process, however many visitors arrive.
	 *
	 * @return void
	 */
	public function testAnUnreadableDatabaseIsReportedOncePerProcess(): void {
		$path          = (string) tempnam(sys_get_temp_dir(), 'geo');
		$this->files[] = $path;
		file_put_contents($path, 'this is not a MaxMind database');

		$settings = $this->createMock(GeoSettings::class);
		$settings->method('provider')->willReturn('dbip');
		$store = $this->createMock(GeoDatabaseStore::class);
		$store->method('databasePath')->willReturn($path);

		$reporter = $this->reporter(method: 'geoDatabaseUnreadable');
		$reporter->expects($this->once())->method('geoDatabaseUnreadable')->willReturn(true);

		$resolver = new MmdbGeoResolver(
			$settings,
			$store,
			$this->createMock(GeoRefreshService::class),
			$this->createMock(LoggerInterface::class),
			$reporter
		);

		$this->assertNull(actual: $resolver->resolve(address: '81.2.69.142', granularity: 'country'));
		$this->assertNull(actual: $resolver->resolve(address: '81.2.69.143', granularity: 'region'));
	}//end testAnUnreadableDatabaseIsReportedOncePerProcess()

	/**
	 * An OIDC client whose HTTP client answers with $get and $post.
	 *
	 * @param ConnectionReporter $reporter The reporter double.
	 * @param callable|null      $get      Answers a GET, or null when none is expected.
	 * @param callable|null      $post     Answers a POST, or null when none is expected.
	 * @param array|null         $cached   What the discovery cache already holds.
	 *
	 * @return OidcClientService
	 */
	private function oidc(ConnectionReporter $reporter, ?callable $get = null, ?callable $post = null, ?array $cached = null): OidcClientService {
		$client = $this->createMock(IClient::class);
		if ($get !== null) {
			$client->method('get')->willReturnCallback($get);
		}

		if ($post !== null) {
			$client->method('post')->willReturnCallback($post);
		}

		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn($cached);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		return new OidcClientService($clientService, $cacheFactory, $this->createMock(LoggerInterface::class), $reporter);
	}//end oidc()

	/**
	 * A response double.
	 *
	 * @param int    $status The HTTP status.
	 * @param string $body   The body.
	 *
	 * @return IResponse
	 */
	private function response(int $status, string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn($body);

		return $response;
	}//end response()

	/**
	 * A discovery request that throws, or answers without endpoints, is reported with the issuer.
	 *
	 * @return void
	 */
	public function testAFailedDiscoveryIsReported(): void {
		$calls    = [];
		$reporter = $this->reporter(method: 'oidcDiscoveryFailed');
		$reporter->expects($this->exactly(2))->method('oidcDiscoveryFailed')->willReturnCallback(
			static function (string $issuer, bool $answered) use (&$calls): bool {
				$calls[] = [$issuer, $answered];
				return true;
			}
		);

		$down = $this->oidc(reporter: $reporter, get: static fn () => throw new RuntimeException('timed out'));
		$this->assertNull(actual: $down->discover(issuer: self::ISSUER));

		$partial = $this->oidc(reporter: $reporter, get: fn () => $this->response(status: 200, body: '{"authorization_endpoint": "https://idp.example/auth"}'));
		$this->assertNull(actual: $partial->discover(issuer: self::ISSUER));

		$this->assertSame(expected: [[self::ISSUER, false], [self::ISSUER, true]], actual: $calls);
	}//end testAFailedDiscoveryIsReported()

	/**
	 * A cached discovery document makes no call, so it reports nothing.
	 *
	 * @return void
	 */
	public function testACachedDiscoveryReportsNothing(): void {
		$reporter = $this->reporter(method: 'oidcDiscoveryFailed');
		$reporter->expects($this->never())->method('oidcDiscoveryFailed');

		$cached = ['authorization_endpoint' => 'a', 'token_endpoint' => 't', 'jwks_uri' => 'j'];
		$this->assertSame(expected: $cached, actual: $this->oidc(reporter: $reporter, cached: $cached)->discover(issuer: self::ISSUER));
	}//end testACachedDiscoveryReportsNothing()

	/**
	 * The code exchange hands the broker's answer to the reporter, and still fails closed.
	 *
	 * @return void
	 */
	public function testTheCodeExchangeHandsTheAnswerToTheReporter(): void {
		$calls    = [];
		$reporter = $this->reporter(method: 'oidcExchangeAnswered');
		$reporter->expects($this->exactly(3))->method('oidcExchangeAnswered')->willReturnCallback(
			static function (string $tokenEndpoint, ?int $httpStatus, string $oauthError, bool $hasToken) use (&$calls): bool {
				$calls[] = [$tokenEndpoint, $httpStatus, $oauthError, $hasToken];
				return true;
			}
		);

		$expired = $this->oidc(reporter: $reporter, post: fn () => $this->response(status: 400, body: '{"error": "invalid_grant"}'));
		$this->assertNull(actual: $expired->exchangeCode(self::TOKEN_ENDPOINT, 'code', 'verifier', 'client', 'secret', 'https://portal.example/cb'));

		$token = $this->oidc(reporter: $reporter, post: fn () => $this->response(status: 200, body: '{"id_token": "a.b.c", "access_token": "x"}'));
		$this->assertSame(
			expected: ['id_token' => 'a.b.c', 'access_token' => 'x'],
			actual: $token->exchangeCode(self::TOKEN_ENDPOINT, 'code', 'verifier', 'client', 'secret', 'https://portal.example/cb')
		);

		$down = $this->oidc(reporter: $reporter, post: static fn () => throw new RuntimeException('connection refused'));
		$this->assertNull(actual: $down->exchangeCode(self::TOKEN_ENDPOINT, 'code', 'verifier', 'client', 'secret', 'https://portal.example/cb'));

		$this->assertSame(
			expected: [
				[self::TOKEN_ENDPOINT, 400, 'invalid_grant', false],
				[self::TOKEN_ENDPOINT, 200, '', true],
				[self::TOKEN_ENDPOINT, null, '', false],
			],
			actual: $calls
		);
	}//end testTheCodeExchangeHandsTheAnswerToTheReporter()
}//end class
