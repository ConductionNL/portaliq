<?php

/**
 * Portaliq Internal Base URL
 *
 * The optional address an administrator gives for calls to this instance
 * (app config `portaliq` / `internal_base_url`). InstanceLoopback reads it;
 * the admin settings store it. Both go through the same validation, so an
 * address the settings refuse is also never used when it gets in another
 * way (occ, a database edit).
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
 * @spec openspec/changes/instance-loopback-self-calls/specs/instance-loopback/spec.md#requirement-an-administrator-can-name-the-internal-address
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Reads, validates and stores the internal address for calls to this instance.
 *
 * @spec openspec/changes/instance-loopback-self-calls/specs/instance-loopback/spec.md#requirement-an-administrator-can-name-the-internal-address
 */
class InternalBaseUrl {
	/**
	 * The app config key the address is stored under.
	 */
	public const CONFIG_KEY = 'internal_base_url';

	/**
	 * Parts of a URL the address may not carry.
	 */
	private const FORBIDDEN_PARTS = ['user', 'pass', 'query', 'fragment'];

	/**
	 * Whether an invalid stored value was already reported in this request.
	 *
	 * @var boolean
	 */
	private bool $warnedInvalid = false;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Holds the address.
	 * @param LoggerInterface $logger Reports an invalid stored value.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The stored value as it is, valid or not. For the admin form.
	 *
	 * @return string
	 */
	public function stored(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, '');
	}//end stored()

	/**
	 * The address to use, normalised, or '' when none is set or the stored
	 * value is invalid. An invalid value is reported once per request and
	 * then ignored, so the default behaviour still works.
	 *
	 * @return string
	 */
	public function configured(): string {
		$raw = trim($this->stored());
		if ($raw === '') {
			return '';
		}

		$normalised = $this->normalise(value: $raw);
		if ($normalised !== null) {
			return $normalised;
		}

		if ($this->warnedInvalid === false) {
			$this->warnedInvalid = true;
			$this->logger->warning(
				'[InstanceLoopback] Ignoring the invalid internal_base_url setting; calls use the absolute URL.'
				. ' Use an http(s) address without credentials, query or "..".',
				['app' => Application::APP_ID]
			);
		}

		return '';
	}//end configured()

	/**
	 * Store an address after validation. An empty value clears it.
	 *
	 * @param string $value The address the administrator entered.
	 *
	 * @return bool False when the address was refused and nothing was stored.
	 */
	public function store(string $value): bool {
		$normalised = $this->normalise(value: $value);
		if ($normalised === null) {
			return false;
		}

		$this->appConfig->setValueString(Application::APP_ID, self::CONFIG_KEY, $normalised);
		return true;
	}//end store()

	/**
	 * Validate and normalise an address. Returns '' for an empty value, the
	 * address without a trailing slash when valid, and null when invalid.
	 *
	 * Valid: an http or https scheme, a host, an optional port and an
	 * optional plain path (the web root). Refused: credentials, a query, a
	 * fragment, percent-encoding, backslashes, whitespace and `.` or `..`
	 * path segments.
	 *
	 * @param string $value The candidate address.
	 *
	 * @return string|null
	 */
	public function normalise(string $value): ?string {
		$value = trim($value);
		if ($value === '') {
			return '';
		}

		$parts = parse_url($value);
		if (preg_match('/[\s\\\\%]/', $value) === 1 || $parts === false || $this->isAcceptable(parts: $parts) === false) {
			return null;
		}

		$port = '';
		if (isset($parts['port']) === true) {
			$port = ':' . $parts['port'];
		}

		return strtolower((string)$parts['scheme']) . '://' . $parts['host'] . $port . rtrim((string)($parts['path'] ?? ''), '/');
	}//end normalise()

	/**
	 * Whether parsed URL parts make an acceptable address: http(s), a host,
	 * no forbidden part and a plain path.
	 *
	 * @param array<string, int|string> $parts The parse_url() result.
	 *
	 * @return bool
	 */
	private function isAcceptable(array $parts): bool {
		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		if (in_array($scheme, ['http', 'https'], true) === false || (string)($parts['host'] ?? '') === '') {
			return false;
		}

		if (array_intersect(self::FORBIDDEN_PARTS, array_keys($parts)) !== []) {
			return false;
		}

		return $this->isPlainPath(path: (string)($parts['path'] ?? ''));
	}//end isAcceptable()

	/**
	 * Whether a path is a plain web root: letters, digits and `-_.~/`, with
	 * no `.` or `..` segment.
	 *
	 * @param string $path The path part of the address.
	 *
	 * @return bool
	 */
	private function isPlainPath(string $path): bool {
		if (preg_match('#^[A-Za-z0-9._~/-]*$#', $path) !== 1) {
			return false;
		}

		$segments = explode('/', $path);

		return in_array('.', $segments, true) === false && in_array('..', $segments, true) === false;
	}//end isPlainPath()
}//end class
