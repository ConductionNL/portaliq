<?php

/**
 * Portaliq Instance Loopback
 *
 * The one way portaliq sends a request to its own Nextcloud instance: the task
 * seam on openregister, an endpoint action on a leaf app, the availability
 * probe. Those calls used the instance's public absolute URL, and behind a
 * port mapping (docker -p 8090:80), a reverse proxy or split DNS that address
 * does not answer from inside the server: every call failed with cURL error 7
 * and residents read "Uw taken konden niet worden geladen".
 *
 * Resolution order, per call:
 *  1. the internal base URL an administrator configured (app config
 *     `portaliq` / `internal_base_url`), when it is a valid http(s) address;
 *  2. otherwise the absolute URL, exactly as before;
 *  3. when (2) fails before any HTTP answer (DNS, refused, unreachable, a
 *     connect timeout), ONE retry against `http://127.0.0.1` with the same
 *     path and the original `Host` header, so trusted_domains and routing
 *     still match. A real HTTP answer, whatever its status, is never retried.
 *
 * The address that worked is remembered for the rest of this PHP request
 * (the service is shared per request) and never across requests.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/instance-loopback-self-calls/specs/instance-loopback/spec.md#requirement-every-call-to-this-instance-goes-through-one-loopback-service
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\AppInfo\Application;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends a request to this instance, with a configured address, the absolute
 * URL and a loopback fallback.
 *
 * @spec openspec/changes/instance-loopback-self-calls/specs/instance-loopback/spec.md#requirement-every-call-to-this-instance-goes-through-one-loopback-service
 */
class InstanceLoopback {
	/**
	 * The app config key an administrator sets the internal base URL under.
	 */
	public const CONFIG_KEY = 'internal_base_url';

	/**
	 * The origin of the loopback retry. Plain http: the request never leaves
	 * the server, and a webserver inside a container rarely has a certificate
	 * for 127.0.0.1.
	 */
	private const LOOPBACK_ORIGIN = 'http://127.0.0.1';

	/**
	 * cURL errors raised before a connection exists: couldn't resolve proxy (5),
	 * couldn't resolve host (6), couldn't connect (7), TLS handshake failed (35).
	 * Nothing of the request reached the server, so a retry cannot apply it twice.
	 */
	private const CONNECT_PHASE_ERRORS = [5, 6, 7, 35];

	/**
	 * cURL's timeout error. Before a connection it is a connect timeout and
	 * safe to retry; after one the request may have been applied, so it is not.
	 */
	private const CURL_TIMEOUT = 28;

	/**
	 * The address that answered in this request: 'absolute' or 'loopback'.
	 * Null until a call has gone through.
	 *
	 * @var string|null
	 */
	private ?string $workingRoute = null;

	/**
	 * Whether an invalid configured address was already reported in this request.
	 *
	 * @var boolean
	 */
	private bool $invalidConfigReported = false;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService Nextcloud's HTTP client factory.
	 * @param IURLGenerator $urlGenerator Builds the instance's absolute URL.
	 * @param IAppConfig $appConfig Holds the optional internal base URL.
	 * @param LoggerInterface $logger Reports the fallback and an invalid setting.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IURLGenerator $urlGenerator,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Send one request to this instance.
	 *
	 * The caller's options travel unchanged, plus `allow_local_address` (the
	 * target is this server by construction) and, on the configured and the
	 * loopback address, the original `Host` header. Callers keep their own
	 * headers: nothing is added that the caller did not set, and nothing of
	 * the incoming request is forwarded.
	 *
	 * @param string $method The HTTP method (GET, POST, PUT, PATCH, DELETE).
	 * @param string $path A path on this instance, as linkToRoute() returns it
	 *                     or as getAbsoluteURL() accepts it.
	 * @param array<string, mixed> $options Nextcloud HTTP client options.
	 *
	 * @return IResponse Any HTTP answer, whatever its status.
	 *
	 * @throws Throwable When no address answered (the first transport failure).
	 *
	 * @spec openspec/changes/instance-loopback-self-calls/specs/instance-loopback/spec.md#requirement-a-transport-failure-on-the-absolute-url-is-retried-once-on-the-loopback
	 */
	public function request(string $method, string $path, array $options = []): IResponse {
		$absolute = $this->urlGenerator->getAbsoluteURL($path);
		$options['nextcloud'] = array_merge((array)($options['nextcloud'] ?? []), ['allow_local_address' => true]);

		$configured = $this->configuredBaseUrl();
		if ($configured !== '') {
			return $this->send(
				method: $method,
				url: $configured . $this->instancePath(absolute: $absolute),
				options: $this->withHost(options: $options, absolute: $absolute)
			);
		}

		if ($this->workingRoute === 'loopback') {
			return $this->sendLoopback(method: $method, absolute: $absolute, options: $options);
		}

		try {
			$response = $this->send(method: $method, url: $absolute, options: $options);
		} catch (Throwable $failure) {
			if ($this->isConnectPhaseFailure(failure: $failure) === false) {
				throw $failure;
			}

			return $this->retryOnLoopback(method: $method, absolute: $absolute, options: $options, failure: $failure);
		}

		$this->workingRoute = 'absolute';
		return $response;
	}//end request()

	/**
	 * The configured internal base URL, normalised, or '' when none is set or
	 * the value is invalid. An invalid value is reported once per request and
	 * then ignored, so the absolute URL and its fallback still work.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/instance-loopback-self-calls/specs/instance-loopback/spec.md#requirement-an-administrator-can-name-the-internal-address
	 */
	public function configuredBaseUrl(): string {
		$raw = trim($this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, ''));
		if ($raw === '') {
			return '';
		}

		$normalised = $this->normaliseBaseUrl(value: $raw);
		if ($normalised === null) {
			if ($this->invalidConfigReported === false) {
				$this->invalidConfigReported = true;
				$this->logger->warning(
					'[InstanceLoopback] Ignoring the invalid internal_base_url setting; calls use the absolute URL. Use an http(s) address without credentials, query or "..".',
					['app' => Application::APP_ID]
				);
			}

			return '';
		}

		return $normalised;
	}//end configuredBaseUrl()

	/**
	 * Validate and normalise an internal base URL. Returns '' for an empty
	 * value (no setting), the address without a trailing slash when valid,
	 * and null when invalid.
	 *
	 * Valid: an http or https scheme, a host, an optional port and an
	 * optional plain path (the web root). Refused: credentials, a query, a
	 * fragment, percent-encoding, backslashes, whitespace and `.` or `..`
	 * path segments.
	 *
	 * @param string $value The candidate address.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/instance-loopback-self-calls/specs/instance-loopback/spec.md#requirement-an-administrator-can-name-the-internal-address
	 */
	public function normaliseBaseUrl(string $value): ?string {
		$value = trim($value);
		if ($value === '') {
			return '';
		}

		if (preg_match('/[\s\\\\%]/', $value) === 1) {
			return null;
		}

		$parts = parse_url($value);
		if ($parts === false) {
			return null;
		}

		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		$host = (string)($parts['host'] ?? '');
		if (in_array($scheme, ['http', 'https'], true) === false || $host === '') {
			return null;
		}

		foreach (['user', 'pass', 'query', 'fragment'] as $forbidden) {
			if (array_key_exists($forbidden, $parts) === true) {
				return null;
			}
		}

		$path = (string)($parts['path'] ?? '');
		if ($this->isPlainPath(path: $path) === false) {
			return null;
		}

		$port = '';
		if (isset($parts['port']) === true) {
			$port = ':' . $parts['port'];
		}

		return $scheme . '://' . $host . $port . rtrim($path, '/');
	}//end normaliseBaseUrl()

	/**
	 * Whether a base URL path is a plain web root: segments of letters,
	 * digits and `-_.~`, none of them `.` or `..`.
	 *
	 * @param string $path The path part of the base URL.
	 *
	 * @return bool
	 */
	private function isPlainPath(string $path): bool {
		if (preg_match('#^[A-Za-z0-9._~/-]*$#', $path) !== 1) {
			return false;
		}

		foreach (explode('/', $path) as $segment) {
			if ($segment === '.' || $segment === '..') {
				return false;
			}
		}

		return true;
	}//end isPlainPath()

	/**
	 * The retry on the loopback after a transport failure on the absolute URL.
	 *
	 * @param string $method The HTTP method.
	 * @param string $absolute The absolute URL that failed.
	 * @param array<string, mixed> $options The client options.
	 * @param Throwable $failure The transport failure on the absolute URL.
	 *
	 * @return IResponse
	 *
	 * @throws Throwable The original failure when the loopback fails too.
	 */
	private function retryOnLoopback(string $method, string $absolute, array $options, Throwable $failure): IResponse {
		try {
			$response = $this->sendLoopback(method: $method, absolute: $absolute, options: $this->rewound(options: $options));
		} catch (Throwable $second) {
			$this->logger->warning(
				'[InstanceLoopback] This instance could not be reached at its absolute URL nor on the loopback. Set internal_base_url to the address the server reaches itself on.',
				[
					'app' => Application::APP_ID,
					'absolute' => $failure->getMessage(),
					'loopback' => $second->getMessage(),
				]
			);

			throw $failure;
		}

		// Remembered for the rest of this request, so this runs, and logs,
		// at most once per request.
		$this->workingRoute = 'loopback';
		$this->logger->info(
			'[InstanceLoopback] The absolute URL did not answer from inside the server; calls to this instance use the loopback for this request. Set internal_base_url to skip the failed attempt.',
			['app' => Application::APP_ID, 'reason' => $failure->getMessage()]
		);

		return $response;
	}//end retryOnLoopback()

	/**
	 * Send the request to the loopback origin with the original Host header.
	 *
	 * @param string $method The HTTP method.
	 * @param string $absolute The absolute URL whose path and host are kept.
	 * @param array<string, mixed> $options The client options.
	 *
	 * @return IResponse
	 */
	private function sendLoopback(string $method, string $absolute, array $options): IResponse {
		return $this->send(
			method: $method,
			url: self::LOOPBACK_ORIGIN . $this->absolutePath(absolute: $absolute),
			options: $this->withHost(options: $options, absolute: $absolute)
		);
	}//end sendLoopback()

	/**
	 * Perform the call with the client method matching the HTTP method.
	 *
	 * @param string $method The HTTP method.
	 * @param string $url The full URL.
	 * @param array<string, mixed> $options The client options.
	 *
	 * @return IResponse
	 */
	private function send(string $method, string $url, array $options): IResponse {
		$client = $this->clientService->newClient();

		return $this->dispatch(client: $client, method: strtoupper($method), url: $url, options: $options);
	}//end send()

	/**
	 * Call the client method for one HTTP method; anything unknown is a POST,
	 * as the action forwarder always treated it.
	 *
	 * @param IClient $client The client.
	 * @param string $method The upper-case HTTP method.
	 * @param string $url The full URL.
	 * @param array<string, mixed> $options The client options.
	 *
	 * @return IResponse
	 */
	private function dispatch(IClient $client, string $method, string $url, array $options): IResponse {
		return match ($method) {
			'GET' => $client->get($url, $options),
			'PUT' => $client->put($url, $options),
			'PATCH' => $client->patch($url, $options),
			'DELETE' => $client->delete($url, $options),
			default => $client->post($url, $options),
		};
	}//end dispatch()

	/**
	 * The options with the `Host` header of the absolute URL, so the request
	 * on another address still matches trusted_domains and the virtual host.
	 *
	 * @param array<string, mixed> $options The client options.
	 * @param string $absolute The absolute URL.
	 *
	 * @return array<string, mixed>
	 */
	private function withHost(array $options, string $absolute): array {
		$host = (string)parse_url($absolute, PHP_URL_HOST);
		if ($host === '') {
			return $options;
		}

		$port = parse_url($absolute, PHP_URL_PORT);
		if (is_int($port) === true) {
			$host .= ':' . $port;
		}

		$headers = (array)($options['headers'] ?? []);
		$headers['Host'] = $host;
		$options['headers'] = $headers;

		return $options;
	}//end withHost()

	/**
	 * Path and query of the absolute URL, web root included.
	 *
	 * @param string $absolute The absolute URL.
	 *
	 * @return string
	 */
	private function absolutePath(string $absolute): string {
		$path = (string)parse_url($absolute, PHP_URL_PATH);
		if ($path === '') {
			$path = '/';
		}

		$query = (string)parse_url($absolute, PHP_URL_QUERY);
		if ($query !== '') {
			return $path . '?' . $query;
		}

		return $path;
	}//end absolutePath()

	/**
	 * Path and query of the absolute URL with this instance's web root taken
	 * off, so the configured base URL (which carries its own web root) can be
	 * put in front.
	 *
	 * @param string $absolute The absolute URL.
	 *
	 * @return string
	 */
	private function instancePath(string $absolute): string {
		$full = $this->absolutePath(absolute: $absolute);
		$root = rtrim((string)parse_url($this->urlGenerator->getAbsoluteURL('/'), PHP_URL_PATH), '/');
		if ($root !== '' && str_starts_with($full, $root . '/') === true) {
			return substr($full, strlen($root));
		}

		return $full;
	}//end instancePath()

	/**
	 * Whether a failure happened before any connection existed, so the
	 * request cannot have reached the server. Read from the cURL error the
	 * client reports: the handler context when the exception carries one,
	 * the "cURL error N:" message otherwise. A failure that carries a
	 * response is never one.
	 *
	 * @param Throwable $failure The failure.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/instance-loopback-self-calls/specs/instance-loopback/spec.md#requirement-a-real-http-answer-is-never-retried
	 */
	private function isConnectPhaseFailure(Throwable $failure): bool {
		if (method_exists($failure, 'getResponse') === true && $failure->getResponse() !== null) {
			return false;
		}

		$context = [];
		if (method_exists($failure, 'getHandlerContext') === true) {
			$context = (array)$failure->getHandlerContext();
		}

		$errno = (int)($context['errno'] ?? 0);
		if ($errno === 0 && preg_match('/cURL error (\d+):/', $failure->getMessage(), $match) === 1) {
			$errno = (int)$match[1];
		}

		if (in_array($errno, self::CONNECT_PHASE_ERRORS, true) === true) {
			return true;
		}

		if ($errno !== self::CURL_TIMEOUT) {
			return false;
		}

		// A timeout counts only when no connection was made: cURL reports
		// connect_time 0, or, without a context, says so in the message.
		if (array_key_exists('connect_time', $context) === true) {
			return (float)$context['connect_time'] === 0.0;
		}

		return str_contains($failure->getMessage(), 'Connection timed out') === true
			|| str_contains($failure->getMessage(), 'Failed to connect') === true;
	}//end isConnectPhaseFailure()

	/**
	 * The options with every stream in the body or a multipart part rewound,
	 * so a retry sends the same bytes the first attempt was handed.
	 *
	 * @param array<string, mixed> $options The client options.
	 *
	 * @return array<string, mixed>
	 */
	private function rewound(array $options): array {
		$this->rewindStream(stream: ($options['body'] ?? null));
		foreach ((array)($options['multipart'] ?? []) as $part) {
			if (is_array($part) === true) {
				$this->rewindStream(stream: ($part['contents'] ?? null));
			}
		}

		return $options;
	}//end rewound()

	/**
	 * Rewind one stream resource when it is seekable; anything else is left alone.
	 *
	 * @param mixed $stream A body or part content.
	 *
	 * @return void
	 */
	private function rewindStream(mixed $stream): void {
		if (is_resource($stream) === true && (bool)(stream_get_meta_data($stream)['seekable'] ?? false) === true) {
			rewind($stream);
		}
	}//end rewindStream()
}//end class
