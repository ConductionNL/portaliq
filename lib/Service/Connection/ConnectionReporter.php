<?php

/**
 * Portaliq connection reporter.
 *
 * Tells integriq's connection registry what only portaliq can see about its
 * outside connections: the visitor geography database and the login brokers.
 * Integriq owns the rows the Integrations page lists and works out each status
 * itself (hydra change connection-registry, design D4). Portaliq asks for a
 * fresh resolve after a geography save, and reports what a save, a refresh or
 * a broker call met.
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

use OCA\Portaliq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends connection reports and refresh requests to integriq.
 *
 * A save refreshes before it reports. Under hydra#674 a refresh retires the
 * observations older than itself, so a report sent before the refresh would
 * be retired by it. A report from a visitor request is throttled, so a busy
 * portal sends at most one per window.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-002-a-geography-save-refreshes-and-a-refresh-or-a-failed-open-reports
 */
class ConnectionReporter {

	/**
	 * The app id integriq keys the rows by.
	 *
	 * @var string
	 */
	public const APP_ID = Application::APP_ID;

	/**
	 * Integriq's report event (ADR-041). Named by string so portaliq stays
	 * installable without integriq: the class only exists when integriq does.
	 *
	 * @var string
	 */
	public const STATUS_EVENT = 'OCA\Integriq\Event\ConnectionStatusReportedEvent';

	/**
	 * Integriq's refresh event. Named by string for the same reason.
	 *
	 * @var string
	 */
	public const REFRESH_EVENT = 'OCA\Integriq\Event\ConnectionRefreshRequestedEvent';

	/**
	 * The geography database key in lib/Settings/connections.json.
	 *
	 * @var string
	 */
	public const KEY_GEO = 'geo-db';

	/**
	 * The login brokers key in lib/Settings/connections.json.
	 *
	 * @var string
	 */
	public const KEY_OIDC = 'oidc';

	/**
	 * The keys `lib/Settings/connections.json` declares, in declared order.
	 *
	 * A unit test keeps the two equal.
	 *
	 * @var array<int, string>
	 */
	public const KEYS = [self::KEY_GEO, self::KEY_OIDC];

	/**
	 * Prefix of the app-config key that remembers the last report per connection.
	 *
	 * @var string
	 */
	public const MEMORY_KEY_PREFIX = 'connection_report_';

	/**
	 * Seconds after which the same status is reported again from a visitor request.
	 *
	 * @var int
	 */
	public const REPEAT_SECONDS = 3600;

	/**
	 * Seconds that must pass before a different status is reported from a visitor request.
	 *
	 * @var int
	 */
	public const CHANGE_SECONDS = 300;

	/**
	 * The pure outcome mapper.
	 *
	 * @var ConnectionObservations
	 */
	private readonly ConnectionObservations $observations;

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher Sends the integriq events (ADR-041).
	 * @param IAppConfig       $appConfig       Keeps the report memory.
	 * @param ITimeFactory     $timeFactory     Tells the time for the report memory.
	 * @param LoggerInterface  $logger          Records what could not be sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-002-a-geography-save-refreshes-and-a-refresh-or-a-failed-open-reports
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
		$this->observations = new ConnectionObservations();
	}//end __construct()

	/**
	 * After a geography settings save: refresh, then report what the saved settings say.
	 *
	 * Clears the report memory first, so the save reports at once. Never
	 * throws, and does nothing without integriq.
	 *
	 * @param array<string, mixed> $settings The result of GeoSettings::toArray() after the save.
	 * @param array<string, mixed> $status   The result of GeoRefreshService::status().
	 *
	 * @return bool True when the report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-002-a-geography-save-refreshes-and-a-refresh-or-a-failed-open-reports
	 */
	public function geoSettingsSaved(array $settings, array $status): bool {
		if ($this->refresh(key: self::KEY_GEO) === false) {
			return false;
		}

		$this->forget(key: self::KEY_GEO);

		return $this->reportNow(
			key: self::KEY_GEO,
			observe: fn (): array => $this->observations->geoSettings(settings: $settings, status: $status)
		);
	}//end geoSettingsSaved()

	/**
	 * After a geography refresh: report its outcome.
	 *
	 * A refresh runs from the monthly job, the first-download job or occ, so
	 * it reports at once. It changes no settings, so it sends no refresh.
	 *
	 * @param array<string, mixed> $result The result of GeoRefreshService::refresh().
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-002-a-geography-save-refreshes-and-a-refresh-or-a-failed-open-reports
	 */
	public function geoRefreshed(array $result): bool {
		return $this->reportNow(
			key: self::KEY_GEO,
			observe: fn (): ?array => $this->observations->geoRefresh(result: $result)
		);
	}//end geoRefreshed()

	/**
	 * Report that the installed geography database cannot be opened. Throttled.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-002-a-geography-save-refreshes-and-a-refresh-or-a-failed-open-reports
	 */
	public function geoDatabaseUnreadable(): bool {
		return $this->reportThrottled(
			key: self::KEY_GEO,
			observe: fn (): array => $this->observations->geoUnreadable()
		);
	}//end geoDatabaseUnreadable()

	/**
	 * Report a failed broker discovery request. Throttled.
	 *
	 * @param string $issuer   The broker's issuer URL.
	 * @param bool   $answered Whether the broker answered at all.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-003-a-broker-call-reports-what-the-broker-answered-throttled
	 */
	public function oidcDiscoveryFailed(string $issuer, bool $answered): bool {
		return $this->reportThrottled(
			key: self::KEY_OIDC,
			observe: fn (): array => $this->observations->oidcDiscoveryFailed(issuer: $issuer, answered: $answered)
		);
	}//end oidcDiscoveryFailed()

	/**
	 * Report what a broker answered to the code exchange. Throttled.
	 *
	 * @param string   $tokenEndpoint The token endpoint called.
	 * @param int|null $httpStatus    The answer's HTTP status, or null when nothing answered.
	 * @param string   $oauthError    The OAuth `error` code in the answer, or ''.
	 * @param bool     $hasToken      Whether a 2xx answer carried a token response.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-003-a-broker-call-reports-what-the-broker-answered-throttled
	 */
	public function oidcExchangeAnswered(string $tokenEndpoint, ?int $httpStatus, string $oauthError, bool $hasToken): bool {
		return $this->reportThrottled(
			key: self::KEY_OIDC,
			observe: fn (): ?array => $this->observations->oidcExchange(
				tokenEndpoint: $tokenEndpoint,
				httpStatus: $httpStatus,
				oauthError: $oauthError,
				hasToken: $hasToken
			)
		);
	}//end oidcExchangeAnswered()

	/**
	 * The event class to instantiate, or null when integriq does not ship it.
	 *
	 * @param string $eventClass The fully qualified class name, without a leading backslash.
	 *
	 * @return string|null The class name to instantiate, or null when absent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-002-a-geography-save-refreshes-and-a-refresh-or-a-failed-open-reports
	 */
	protected function resolveEventClass(string $eventClass): ?string {
		$qualified = '\\' . $eventClass;
		if (class_exists($qualified) === false) {
			return null;
		}

		return $qualified;
	}//end resolveEventClass()

	/**
	 * Ask integriq to resolve one connection again.
	 *
	 * @param string $key The connection key.
	 *
	 * @return bool True when the event was dispatched.
	 */
	private function refresh(string $key): bool {
		$eventClass = $this->resolveEventClass(eventClass: self::REFRESH_EVENT);
		if ($eventClass === null) {
			return false;
		}

		return $this->send(
			key: $key,
			build: static fn (): object => new $eventClass(app: self::APP_ID, key: $key)
		);
	}//end refresh()

	/**
	 * Observe and send one report without the throttle, and record it.
	 *
	 * @param string                                          $key     One of {@see self::KEYS}.
	 * @param callable(): (array{0: string, 1: string}|null) $observe Works out the status and message, or null to report nothing.
	 *
	 * @return bool True when a report was sent.
	 */
	private function reportNow(string $key, callable $observe): bool {
		return $this->observeAndSend(key: $key, observe: $observe, throttled: false);
	}//end reportNow()

	/**
	 * Observe and send one report when the throttle allows it, and record it.
	 *
	 * @param string                                          $key     One of {@see self::KEYS}.
	 * @param callable(): (array{0: string, 1: string}|null) $observe Works out the status and message, or null to report nothing.
	 *
	 * @return bool True when a report was sent.
	 */
	private function reportThrottled(string $key, callable $observe): bool {
		return $this->observeAndSend(key: $key, observe: $observe, throttled: true);
	}//end reportThrottled()

	/**
	 * Observe, optionally throttle, send and record one status report. Never throws.
	 *
	 * Without integriq the class check fails first, so nothing is read,
	 * stored, sent or logged.
	 *
	 * @param string                                          $key       One of {@see self::KEYS}.
	 * @param callable(): (array{0: string, 1: string}|null) $observe   Works out the status and message, or null to report nothing.
	 * @param bool                                            $throttled Whether the report memory may hold it back.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) -- private, and reached only
	 * through reportNow() and reportThrottled(), which name the two modes.
	 */
	private function observeAndSend(string $key, callable $observe, bool $throttled): bool {
		$eventClass = $this->resolveEventClass(eventClass: self::STATUS_EVENT);
		if ($eventClass === null) {
			return false;
		}

		try {
			$observed = $observe();
			if ($observed === null) {
				return false;
			}

			[$status, $message] = $observed;

			$now = $this->timeFactory->getTime();
			if ($throttled === true && $this->isDue(key: $key, status: $status, now: $now) === false) {
				return false;
			}

			$sent = $this->send(
				key: $key,
				build: static fn (): object => new $eventClass(
					app: self::APP_ID,
					key: $key,
					status: $status,
					message: $message,
				)
			);
			if ($sent === true) {
				$this->appConfig->setValueString(self::APP_ID, self::MEMORY_KEY_PREFIX . $key, $status . '|' . $now);
			}

			return $sent;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Portaliq: could not report a connection to integriq',
				['key' => $key, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try
	}//end observeAndSend()

	/**
	 * Whether the report memory allows a report with this status now.
	 *
	 * A different status waits five minutes after the last report, so two
	 * brokers that disagree cannot report on every login. The same status
	 * reports again after an hour.
	 *
	 * @param string $key    The connection key.
	 * @param string $status The status observed.
	 * @param int    $now    The current Unix time.
	 *
	 * @return bool
	 */
	private function isDue(string $key, string $status, int $now): bool {
		$memory = $this->appConfig->getValueString(self::APP_ID, self::MEMORY_KEY_PREFIX . $key, '');
		$parts  = explode('|', $memory, 2);
		if (count($parts) !== 2 || ctype_digit($parts[1]) === false) {
			return true;
		}

		$elapsed = ($now - (int) $parts[1]);
		if ($parts[0] === $status) {
			return $elapsed >= self::REPEAT_SECONDS;
		}

		return $elapsed >= self::CHANGE_SECONDS;
	}//end isDue()

	/**
	 * Clear the report memory of one connection.
	 *
	 * @param string $key The connection key.
	 *
	 * @return void
	 */
	private function forget(string $key): void {
		try {
			$this->appConfig->deleteKey(self::APP_ID, self::MEMORY_KEY_PREFIX . $key);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Portaliq: could not clear a connection report memory',
				['key' => $key, 'exception' => $e->getMessage()]
			);
		}
	}//end forget()

	/**
	 * Build and dispatch one event, swallowing anything a listener throws.
	 *
	 * @param string             $key   The connection the event is about, for the log.
	 * @param callable(): object $build Builds the event.
	 *
	 * @return bool True when the event was dispatched without an exception.
	 */
	private function send(string $key, callable $build): bool {
		try {
			$event = $build();
			if (($event instanceof Event) === false) {
				return false;
			}

			$this->eventDispatcher->dispatchTyped($event);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Portaliq: could not send a connection event to integriq',
				['key' => $key, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end send()
}//end class
