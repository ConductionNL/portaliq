<?php

/**
 * ConnectionObservations unit tests.
 *
 * Each mapping decides what an admin reads on the Integrations page. A wrong
 * one is a row that says Configured while regions stay empty, or Error after
 * one resident let a code expire.
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

use OCA\Portaliq\Service\Connection\ConnectionObservations;
use OCA\Portaliq\Service\Traffic\Geo\GeoSettings;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ConnectionObservations.
 *
 * @covers \OCA\Portaliq\Service\Connection\ConnectionObservations
 */
class ConnectionObservationsTest extends TestCase {

	/**
	 * A database status with a file from one provider.
	 *
	 * @param string $provider  The provider the file came from.
	 * @param string $fetchedAt When it was fetched.
	 *
	 * @return array<string, mixed>
	 */
	private function installed(string $provider, string $fetchedAt = '2026-09-01T03:00:00Z'): array {
		return ['present' => true, 'metadata' => ['provider' => $provider, 'fetchedAt' => $fetchedAt]];
	}//end installed()

	/**
	 * Provider none is switched off, not simulated, whatever is installed.
	 *
	 * @return void
	 */
	public function testProviderNoneReadsSwitchedOff(): void {
		$this->assertSame(
			expected: ['unconfigured', ConnectionObservations::GEO_OFF],
			actual: (new ConnectionObservations())->geoSettings(settings: ['provider' => 'none'], status: $this->installed(provider: 'dbip'))
		);
	}//end testProviderNoneReadsSwitchedOff()

	/**
	 * MaxMind without both credentials cannot fetch, so it is not configured.
	 *
	 * @return void
	 */
	public function testMaxMindWithoutCredentialsIsNotConfigured(): void {
		$observations = new ConnectionObservations();

		$incomplete = [
			['maxmindAccountId' => '', 'maxmindLicenseKeySet' => true],
			['maxmindAccountId' => '42', 'maxmindLicenseKeySet' => false],
		];
		foreach ($incomplete as $credentials) {
			[$status, $message] = $observations->geoSettings(
				settings: ['provider' => 'maxmind'] + $credentials,
				status: $this->installed(provider: 'maxmind')
			);
			$this->assertSame(expected: 'unconfigured', actual: $status);
			$this->assertStringContainsString(needle: 'account id and a licence key', haystack: $message);
		}
	}//end testMaxMindWithoutCredentialsIsNotConfigured()

	/**
	 * No installed file reads not configured, and names the command that fetches one.
	 *
	 * @return void
	 */
	public function testNoDatabaseIsNotConfigured(): void {
		[$status, $message] = (new ConnectionObservations())->geoSettings(settings: ['provider' => 'dbip'], status: ['present' => false]);

		$this->assertSame(expected: 'unconfigured', actual: $status);
		$this->assertStringContainsString(needle: 'occ portaliq:traffic:geo-refresh', haystack: $message);
	}//end testNoDatabaseIsNotConfigured()

	/**
	 * A file from the other provider still works, in part: limited, naming where regions come from.
	 *
	 * @return void
	 */
	public function testAFileFromTheOtherProviderReadsLimited(): void {
		$this->assertSame(
			expected: ['limited', 'Regions still come from the DB-IP Lite database. Run occ portaliq:traffic:geo-refresh to fetch MaxMind.'],
			actual: (new ConnectionObservations())->geoSettings(
				settings: ['provider' => 'maxmind', 'maxmindAccountId' => '42', 'maxmindLicenseKeySet' => true],
				status: $this->installed(provider: 'dbip')
			)
		);
	}//end testAFileFromTheOtherProviderReadsLimited()

	/**
	 * A file from the chosen provider reads configured, with when it was fetched.
	 *
	 * @return void
	 */
	public function testAFileFromTheChosenProviderReadsConfigured(): void {
		$observations = new ConnectionObservations();

		$this->assertSame(
			expected: ['configured', 'A DB-IP Lite database is installed, fetched 2026-09-01T03:00:00Z.'],
			actual: $observations->geoSettings(settings: ['provider' => 'dbip'], status: $this->installed(provider: 'dbip'))
		);
		$this->assertSame(
			expected: ['configured', 'A DB-IP Lite database is installed.'],
			actual: $observations->geoSettings(settings: ['provider' => 'dbip'], status: $this->installed(provider: 'dbip', fetchedAt: ''))
		);
	}//end testAFileFromTheChosenProviderReadsConfigured()

	/**
	 * Every provider GeoSettings accepts has a label or means off.
	 *
	 * A provider added there and not here would read as switched off.
	 *
	 * @return void
	 */
	public function testEveryProviderIsKnown(): void {
		$this->assertSame(
			expected: GeoSettings::PROVIDERS,
			actual: array_merge(['none'], array_keys(ConnectionObservations::PROVIDER_LABELS))
		);
	}//end testEveryProviderIsKnown()

	/**
	 * Each refresh outcome maps to the status the design names; an unknown one reports nothing.
	 *
	 * @return void
	 */
	public function testEachRefreshOutcomeMapsToItsStatus(): void {
		$observations = new ConnectionObservations();
		$long         = 'Refresh from dbip failed: ' . str_repeat('x', 400);

		$this->assertSame(
			expected: ['configured', 'The last refresh installed a new DB-IP Lite database.'],
			actual: $observations->geoRefresh(result: ['status' => 'refreshed', 'provider' => 'dbip'])
		);
		$this->assertSame(
			expected: ['unconfigured', ConnectionObservations::GEO_OFF],
			actual: $observations->geoRefresh(result: ['status' => 'disabled', 'provider' => 'none'])
		);

		[$status, $message] = $observations->geoRefresh(result: ['status' => 'failed', 'provider' => 'dbip', 'message' => $long]);
		$this->assertSame(expected: 'error', actual: $status);
		$this->assertSame(expected: ConnectionObservations::REASON_LIMIT + 3, actual: mb_strlen($message));

		$this->assertSame(expected: ['error', 'The last refresh failed.'], actual: $observations->geoRefresh(result: ['status' => 'failed']));
		$this->assertNull(actual: $observations->geoRefresh(result: ['status' => 'something-new']));
	}//end testEachRefreshOutcomeMapsToItsStatus()

	/**
	 * A broker is named by host only, never by path, query or user info.
	 *
	 * @return void
	 */
	public function testABrokerIsNamedByHostOnly(): void {
		[, $message] = (new ConnectionObservations())->oidcDiscoveryFailed(
			issuer: 'https://user:s3cret@idp.gemeente.example/realms/portal?token=abc',
			answered: false
		);

		$this->assertSame(expected: 'The broker at idp.gemeente.example did not answer the last discovery request.', actual: $message);
		foreach (['s3cret', 'user', 'token', 'abc', 'realms'] as $leak) {
			$this->assertStringNotContainsString(needle: $leak, haystack: $message);
		}
	}//end testABrokerIsNamedByHostOnly()

	/**
	 * Each code exchange answer maps to its status, and an answer about one login reports nothing.
	 *
	 * @return void
	 */
	public function testEachExchangeAnswerMapsToItsStatus(): void {
		$observations = new ConnectionObservations();
		$endpoint     = 'https://idp.example/token';

		$this->assertSame(
			expected: ['configured', 'The broker at idp.example answered the last code exchange.'],
			actual: $observations->oidcExchange(tokenEndpoint: $endpoint, httpStatus: 200, oauthError: '', hasToken: true)
		);
		$this->assertSame(
			expected: ['error', 'The broker at idp.example answered the last code exchange without an ID token.'],
			actual: $observations->oidcExchange(tokenEndpoint: $endpoint, httpStatus: 200, oauthError: '', hasToken: false)
		);
		$this->assertSame(
			expected: ['error', 'The broker at idp.example did not answer the last code exchange.'],
			actual: $observations->oidcExchange(tokenEndpoint: $endpoint, httpStatus: null, oauthError: '', hasToken: false)
		);
		$this->assertSame(
			expected: ['error', 'The broker at idp.example answered HTTP 503 on the last code exchange.'],
			actual: $observations->oidcExchange(tokenEndpoint: $endpoint, httpStatus: 503, oauthError: '', hasToken: false)
		);

		foreach ([[401, ''], [403, ''], [400, 'invalid_client'], [400, 'unauthorized_client']] as [$httpStatus, $oauthError]) {
			[$status, $message] = $observations->oidcExchange(
				tokenEndpoint: $endpoint,
				httpStatus: $httpStatus,
				oauthError: $oauthError,
				hasToken: false
			);
			$this->assertSame(expected: 'error', actual: $status, message: $httpStatus . ' ' . $oauthError);
			$this->assertStringContainsString(needle: 'client credentials', haystack: $message);
		}

		foreach ([[400, 'invalid_grant'], [400, ''], [404, ''], [429, '']] as [$httpStatus, $oauthError]) {
			$this->assertNull(
				actual: $observations->oidcExchange(tokenEndpoint: $endpoint, httpStatus: $httpStatus, oauthError: $oauthError, hasToken: false),
				message: $httpStatus . ' ' . $oauthError . ' is about one login'
			);
		}
	}//end testEachExchangeAnswerMapsToItsStatus()
}//end class
