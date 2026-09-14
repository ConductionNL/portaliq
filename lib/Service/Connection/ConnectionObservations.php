<?php

/**
 * Portaliq connection observations.
 *
 * Turns an outcome portaliq already has, such as the saved geography settings,
 * a database refresh result or a broker's HTTP answer, into the status and
 * message integriq's connection registry shows (hydra change
 * connection-registry, design D4 and D6). Pure: it holds no state, reads
 * nothing and sends nothing, so every mapping is testable without a double.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Connection
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-002-a-geography-save-refreshes-and-a-refresh-or-a-failed-open-reports
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Connection;

/**
 * Maps outcomes to connection statuses and messages.
 *
 * Every method answers `[status, message]`, or null when the outcome says
 * nothing about the connection (it is about one login) and so must not be
 * reported.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-002-a-geography-save-refreshes-and-a-refresh-or-a-failed-open-reports
 */
class ConnectionObservations {

	/**
	 * The longest failure reason a message carries.
	 *
	 * @var int
	 */
	public const REASON_LIMIT = 160;

	/**
	 * How a message names each geography provider.
	 *
	 * @var array<string, string>
	 */
	public const PROVIDER_LABELS = [
		'dbip'    => 'DB-IP Lite',
		'maxmind' => 'MaxMind',
	];

	/**
	 * HTTP statuses that say the broker refused this portal's client.
	 *
	 * @var array<int, int>
	 */
	public const REFUSED_STATUSES = [401, 403];

	/**
	 * OAuth error codes that say the broker refused this portal's client.
	 *
	 * RFC 6749 section 5.2 lets a broker answer `invalid_client` with a 400
	 * when the secret travels in the body, as it does here.
	 *
	 * @var array<int, string>
	 */
	public const REFUSED_OAUTH_ERRORS = ['invalid_client', 'unauthorized_client'];

	/**
	 * The message for a switched-off geography.
	 *
	 * @var string
	 */
	public const GEO_OFF = 'Geography is switched off. No database is fetched and no region is stored.';

	/**
	 * What the saved geography settings and the installed database say.
	 *
	 * @param array<string, mixed> $settings The result of GeoSettings::toArray().
	 * @param array<string, mixed> $status   The result of GeoRefreshService::status().
	 *
	 * @return array{0: string, 1: string} The status and the message.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-002-a-geography-save-refreshes-and-a-refresh-or-a-failed-open-reports
	 */
	public function geoSettings(array $settings, array $status): array {
		$provider = (string) ($settings['provider'] ?? '');
		if (array_key_exists($provider, self::PROVIDER_LABELS) === false) {
			return ['unconfigured', self::GEO_OFF];
		}

		if ($provider === 'maxmind'
			&& ((string) ($settings['maxmindAccountId'] ?? '') === '' || ($settings['maxmindLicenseKeySet'] ?? false) !== true)
		) {
			return ['unconfigured', 'MaxMind needs an account id and a licence key before a database can be fetched.'];
		}

		if (($status['present'] ?? false) !== true) {
			return ['unconfigured', 'No database is installed yet. Run occ portaliq:traffic:geo-refresh to fetch one now.'];
		}

		$metadata  = (array) ($status['metadata'] ?? []);
		$installed = (string) ($metadata['provider'] ?? '');
		if ($installed !== '' && $installed !== $provider) {
			return [
				'limited',
				'Regions still come from the ' . (self::PROVIDER_LABELS[$installed] ?? $installed) . ' database. '
				. 'Run occ portaliq:traffic:geo-refresh to fetch ' . self::PROVIDER_LABELS[$provider] . '.',
			];
		}

		$fetchedAt = (string) ($metadata['fetchedAt'] ?? '');
		if ($fetchedAt === '') {
			return ['configured', 'A ' . self::PROVIDER_LABELS[$provider] . ' database is installed.'];
		}

		return ['configured', 'A ' . self::PROVIDER_LABELS[$provider] . ' database is installed, fetched ' . $fetchedAt . '.'];
	}//end geoSettings()

	/**
	 * What a geography refresh says about the connection.
	 *
	 * @param array<string, mixed> $result The result of GeoRefreshService::refresh().
	 *
	 * @return array{0: string, 1: string}|null The status and message, or null for an unknown outcome.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-002-a-geography-save-refreshes-and-a-refresh-or-a-failed-open-reports
	 */
	public function geoRefresh(array $result): ?array {
		$provider = (string) ($result['provider'] ?? '');
		$label    = (self::PROVIDER_LABELS[$provider] ?? $provider);
		// GeoRefreshService::failed() already writes a whole sentence that
		// names the provider and the reason, and says the old file stays.
		$reason = $this->shorten(text: (string) ($result['message'] ?? ''));
		if ($reason === '') {
			$reason = 'The last refresh failed.';
		}

		return match ((string) ($result['status'] ?? '')) {
			'refreshed' => ['configured', 'The last refresh installed a new ' . $label . ' database.'],
			'failed' => ['error', $reason],
			'disabled' => ['unconfigured', self::GEO_OFF],
			default => null,
		};
	}//end geoRefresh()

	/**
	 * What an installed database that cannot be opened says.
	 *
	 * The message leaves out the file path and the reader's reason, which
	 * carries it.
	 *
	 * @return array{0: string, 1: string} The status and the message.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-002-a-geography-save-refreshes-and-a-refresh-or-a-failed-open-reports
	 */
	public function geoUnreadable(): array {
		return [
			'error',
			'The installed geography database cannot be opened, so no region is stored. Run occ portaliq:traffic:geo-refresh.',
		];
	}//end geoUnreadable()

	/**
	 * What a failed broker discovery request says.
	 *
	 * @param string $issuer   The broker's issuer URL. Only its host reaches the message.
	 * @param bool   $answered Whether the broker answered at all.
	 *
	 * @return array{0: string, 1: string} The status and the message.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-003-a-broker-call-reports-what-the-broker-answered-throttled
	 */
	public function oidcDiscoveryFailed(string $issuer, bool $answered): array {
		$broker = $this->brokerName(url: $issuer);
		if ($answered === false) {
			return ['error', ucfirst($broker) . ' did not answer the last discovery request.'];
		}

		return ['error', ucfirst($broker) . ' answered discovery without the endpoints a login needs.'];
	}//end oidcDiscoveryFailed()

	/**
	 * What a broker's answer to the code exchange says.
	 *
	 * @param string   $tokenEndpoint The token endpoint called. Only its host reaches the message.
	 * @param int|null $httpStatus    The answer's HTTP status, or null when nothing answered.
	 * @param string   $oauthError    The OAuth `error` code in the answer, or ''.
	 * @param bool     $hasToken      Whether a 2xx answer carried a token response.
	 *
	 * @return array{0: string, 1: string}|null The status and message, or null when the answer is about one login.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-003-a-broker-call-reports-what-the-broker-answered-throttled
	 */
	public function oidcExchange(string $tokenEndpoint, ?int $httpStatus, string $oauthError, bool $hasToken): ?array {
		$broker = ucfirst($this->brokerName(url: $tokenEndpoint));

		if ($httpStatus === null) {
			return ['error', $broker . ' did not answer the last code exchange.'];
		}

		if ($httpStatus >= 200 && $httpStatus < 300) {
			if ($hasToken === false) {
				return ['error', $broker . ' answered the last code exchange without an ID token.'];
			}

			return ['configured', $broker . ' answered the last code exchange.'];
		}

		if (in_array($httpStatus, self::REFUSED_STATUSES, true) === true
			|| in_array($oauthError, self::REFUSED_OAUTH_ERRORS, true) === true
		) {
			return ['error', $broker . ' refused this portal\'s client credentials (HTTP ' . $httpStatus . ').'];
		}

		if ($httpStatus >= 500) {
			return ['error', $broker . ' answered HTTP ' . $httpStatus . ' on the last code exchange.'];
		}

		return null;
	}//end oidcExchange()

	/**
	 * How a message names a broker: its host, and nothing else from the URL.
	 *
	 * A path, a query or user info can carry a secret, and every admin reads the row.
	 *
	 * @param string $url The broker URL.
	 *
	 * @return string `the broker at {host}`, or `the broker` when the URL has no host.
	 */
	private function brokerName(string $url): string {
		$host = parse_url(trim($url), PHP_URL_HOST);
		if (is_string($host) === false || $host === '') {
			return 'the broker';
		}

		return 'the broker at ' . $host;
	}//end brokerName()

	/**
	 * A reason cut to REASON_LIMIT characters.
	 *
	 * @param string $text The reason.
	 *
	 * @return string The reason, cut and trimmed.
	 */
	private function shorten(string $text): string {
		$text = trim($text);
		if (mb_strlen($text) <= self::REASON_LIMIT) {
			return $text;
		}

		return rtrim(mb_substr($text, 0, self::REASON_LIMIT)) . '...';
	}//end shorten()
}//end class
