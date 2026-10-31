<?php

/**
 * Portaliq Loopback Origins
 *
 * The addresses InstanceLoopback retries on when the instance's absolute URL
 * does not answer from inside the server. In order:
 *  1. the address and port the web server took the current request on, as
 *     PHP sees them (`SERVER_ADDR`, `SERVER_PORT`). A web server in a sidecar
 *     or another container that listens on 8080 is reached here;
 *  2. `127.0.0.1` on that same port;
 *  3. the origin of the `overwrite.cli.url` system setting;
 *  4. `http://127.0.0.1`.
 * Duplicates and the absolute URL's own origin are left out.
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
 * @spec openspec/changes/loopback-tries-the-address-php-was-reached-on/specs/instance-loopback/spec.md#requirement-a-transport-failure-on-the-absolute-url-is-retried-on-the-addresses-this-server-answers-on
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCP\IConfig;
use OCP\IRequest;

/**
 * Lists the origins this server may answer on from inside itself.
 *
 * @spec openspec/changes/loopback-tries-the-address-php-was-reached-on/specs/instance-loopback/spec.md#requirement-a-transport-failure-on-the-absolute-url-is-retried-on-the-addresses-this-server-answers-on
 */
class LoopbackOrigins {
	/**
	 * The last resort. Plain http: the request never leaves the server, and
	 * a webserver inside a container rarely has a certificate for 127.0.0.1.
	 */
	public const LOCALHOST = 'http://127.0.0.1';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current request, read for its server variables.
	 * @param IConfig $config Holds `overwrite.cli.url`.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly IConfig $config,
	) {
	}//end __construct()

	/**
	 * The origins to try, in order, without the absolute URL's own origin.
	 *
	 * @param string $absolute The absolute URL that did not answer.
	 *
	 * @return list<string> Origins as scheme://host[:port], no trailing slash.
	 *
	 * @spec openspec/changes/loopback-tries-the-address-php-was-reached-on/specs/instance-loopback/spec.md#requirement-a-transport-failure-on-the-absolute-url-is-retried-on-the-addresses-this-server-answers-on
	 */
	public function candidates(string $absolute): array {
		$port = $this->serverPort();
		$localOnPort = '';
		if ($port !== null) {
			$localOnPort = $this->localScheme() . '://127.0.0.1:' . $port;
		}

		$candidates = [
			$this->serverAddressOrigin(port: $port),
			$localOnPort,
			$this->origin(url: $this->config->getSystemValueString('overwrite.cli.url', '')),
			self::LOCALHOST,
		];

		$skip = $this->origin(url: $absolute);
		$origins = [];
		foreach ($candidates as $candidate) {
			if ($candidate !== '' && $candidate !== $skip && in_array($candidate, $origins, true) === false) {
				$origins[] = $candidate;
			}
		}

		return $origins;
	}//end candidates()

	/**
	 * The origin of the address and port the web server took this request
	 * on, or '' when PHP was not reached through a web server (cron, occ).
	 *
	 * @param int|null $port The server port.
	 *
	 * @return string
	 */
	private function serverAddressOrigin(?int $port): string {
		$address = trim((string)($this->serverValue(key: 'SERVER_ADDR')));
		if ($port === null || $address === '' || filter_var($address, FILTER_VALIDATE_IP) === false) {
			return '';
		}

		if (str_contains($address, ':') === true) {
			$address = '[' . $address . ']';
		}

		return $this->localScheme() . '://' . $address . ':' . $port;
	}//end serverAddressOrigin()

	/**
	 * The port the web server took this request on, or null without one.
	 *
	 * @return int|null
	 */
	private function serverPort(): ?int {
		$port = $this->serverValue(key: 'SERVER_PORT');
		if (is_numeric($port) === false) {
			return null;
		}

		$port = (int)$port;
		if ($port < 1 || $port > 65535) {
			return null;
		}

		return $port;
	}//end serverPort()

	/**
	 * The scheme the web server itself speaks: https when it says so, else http.
	 *
	 * @return string
	 */
	private function localScheme(): string {
		$https = strtolower((string)$this->serverValue(key: 'HTTPS'));
		if ($https !== '' && $https !== 'off') {
			return 'https';
		}

		return 'http';
	}//end localScheme()

	/**
	 * One scalar server variable of the current request, or null. IRequest
	 * documents them as the magic `server` property, which the static
	 * analysers do not read on an interface, so the magic getter is called
	 * by name.
	 *
	 * @param string $key The variable name.
	 *
	 * @return string|int|null
	 */
	private function serverValue(string $key): string|int|null {
		$server = [];
		if (method_exists($this->request, '__get') === true) {
			$server = $this->request->__get('server');
		}

		$value = null;
		if (is_array($server) === true) {
			$value = ($server[$key] ?? null);
		}

		if (is_string($value) === true || is_int($value) === true) {
			return $value;
		}

		return null;
	}//end serverValue()

	/**
	 * The scheme://host[:port] of an http(s) URL, lower-case scheme and host,
	 * or '' when it is not one.
	 *
	 * @param string $url The URL.
	 *
	 * @return string
	 */
	private function origin(string $url): string {
		$parts = parse_url(trim($url));
		if ($parts === false) {
			return '';
		}

		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		$host = strtolower((string)($parts['host'] ?? ''));
		if (in_array($scheme, ['http', 'https'], true) === false || $host === '') {
			return '';
		}

		$port = '';
		if (isset($parts['port']) === true) {
			$port = ':' . $parts['port'];
		}

		return $scheme . '://' . $host . $port;
	}//end origin()
}//end class
