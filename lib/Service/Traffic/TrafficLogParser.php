<?php

/**
 * Portaliq Traffic Log Parser.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-an-access-log-must-import-as-page-views-without-assets-or-bots
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * One access log line as a page view, or nothing.
 *
 * Two formats: the Apache and Nginx "combined" line, and one JSON object
 * per line as Nginx's `log_format ... escape=json` and most log shippers
 * write it. A line is a page view only when it was a successful GET of a
 * page: another method, a redirect or an error, and anything that looks
 * like an asset (a stylesheet, a script, an image, a font) is skipped.
 * Bots are skipped by the same classifier the collector uses.
 *
 * Pure. The importer decides what to do with the result.
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-an-access-log-must-import-as-page-views-without-assets-or-bots
 */
class TrafficLogParser {

	/**
	 * The formats.
	 *
	 * @var string[]
	 */
	public const FORMATS = ['combined', 'json'];

	/**
	 * Path extensions that are assets, not pages.
	 *
	 * @var string[]
	 */
	public const ASSET_EXTENSIONS = [
		'css', 'js', 'mjs', 'map', 'json', 'xml', 'txt', 'ico', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif',
		'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp4', 'webm', 'mp3', 'wasm',
	];

	/**
	 * The combined log format.
	 */
	private const COMBINED = '/^(?<ip>\S+) \S+ \S+ \[(?<time>[^\]]+)\] "(?<request>[^"]*)" (?<status>\d{3}) (?<bytes>\S+)'
		. '(?: "(?<referrer>[^"]*)" "(?<agent>[^"]*)")?/';

	/**
	 * Constructor.
	 *
	 * @param UserAgentClassifier $agents Tells a bot from a browser.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly UserAgentClassifier $agents = new UserAgentClassifier(),
	) {
	}

	/**
	 * Parse one line.
	 *
	 * @param string $line   The line.
	 * @param string $format `combined` or `json`.
	 *
	 * @return array{ip: string, userAgent: string, timestamp: string, path: string, referrer: string}|null The page view, or null to skip.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-an-access-log-must-import-as-page-views-without-assets-or-bots
	 */
	public function parse(string $line, string $format): ?array {
		$fields = $this->fields(line: trim($line), format: $format);
		if ($fields === null) {
			return null;
		}

		$path = $this->pathOf(target: $fields['target']);
		$timestamp = $this->timestamp(value: $fields['time']);
		if ($this->isPageView(fields: $fields, path: $path) === false || $timestamp === null) {
			return null;
		}

		$referrer = $fields['referrer'];
		if ($referrer === '-') {
			$referrer = '';
		}

		return [
			'ip' => $fields['ip'],
			'userAgent' => $fields['agent'],
			'timestamp' => $timestamp,
			'path' => $path,
			'referrer' => $referrer,
		];
	}

	/**
	 * The fields of a line in either format, or null for a line that is
	 * empty or does not parse.
	 *
	 * @param string $line   The trimmed line.
	 * @param string $format `combined` or `json`.
	 *
	 * @return array{ip: string, time: string, method: string, target: string, status: int, referrer: string, agent: string}|null The fields.
	 */
	private function fields(string $line, string $format): ?array {
		if ($line === '') {
			return null;
		}

		if ($format === 'json') {
			return $this->json(line: $line);
		}

		return $this->combined(line: $line);
	}

	/**
	 * Whether the fields describe a person viewing a page: a successful
	 * GET of a path that is not an asset, by something that is not a bot.
	 *
	 * @param array<string, mixed> $fields The fields.
	 * @param string               $path   The request path.
	 *
	 * @return bool True for a page view.
	 */
	private function isPageView(array $fields, string $path): bool {
		if ($fields['method'] !== 'GET' || $fields['status'] < 200 || $fields['status'] >= 300) {
			return false;
		}

		if ($path === '' || $this->isAsset(path: $path) === true) {
			return false;
		}

		return $this->agents->classify(userAgent: (string)$fields['agent'])['bot'] === false;
	}

	/**
	 * The fields of a combined line.
	 *
	 * @param string $line The line.
	 *
	 * @return array{ip: string, time: string, method: string, target: string, status: int, referrer: string, agent: string}|null The fields.
	 */
	private function combined(string $line): ?array {
		if (preg_match(self::COMBINED, $line, $match) !== 1) {
			return null;
		}

		$request = explode(' ', $match['request']);

		return [
			'ip' => $match['ip'],
			'time' => $match['time'],
			'method' => strtoupper($request[0] ?? ''),
			'target' => $request[1] ?? '',
			'status' => (int)$match['status'],
			'referrer' => $match['referrer'] ?? '',
			'agent' => $match['agent'] ?? '',
		];
	}

	/**
	 * The fields of a JSON line, under the names the common shippers use.
	 *
	 * @param string $line The line.
	 *
	 * @return array{ip: string, time: string, method: string, target: string, status: int, referrer: string, agent: string}|null The fields.
	 */
	private function json(string $line): ?array {
		$row = json_decode($line, true);
		if (is_array($row) === false) {
			return null;
		}

		$request = trim((string)$this->first(row: $row, keys: ['request']));
		$method = strtoupper(trim((string)$this->first(row: $row, keys: ['method', 'request_method'])));
		$target = trim((string)$this->first(row: $row, keys: ['path', 'uri', 'request_uri', 'url']));
		if ($request !== '') {
			$parts = explode(' ', $request);
			if ($method === '') {
				$method = strtoupper($parts[0] ?? '');
			}

			if ($target === '') {
				$target = $parts[1] ?? '';
			}
		}

		if ($method === '') {
			$method = 'GET';
		}

		return [
			'ip' => trim((string)$this->first(row: $row, keys: ['remote_addr', 'remoteAddress', 'ip', 'client_ip', 'clientip'])),
			'time' => trim((string)$this->first(row: $row, keys: ['time_local', 'time_iso8601', 'timestamp', 'time', '@timestamp'])),
			'method' => $method,
			'target' => $target,
			'status' => (int)$this->first(row: $row, keys: ['status', 'status_code', 'response']),
			'referrer' => trim((string)$this->first(row: $row, keys: ['http_referer', 'referer', 'referrer'])),
			'agent' => trim((string)$this->first(row: $row, keys: ['http_user_agent', 'user_agent', 'userAgent', 'ua', 'agent'])),
		];
	}

	/**
	 * The first scalar under any of several keys.
	 *
	 * @param array<string, mixed> $row  The row.
	 * @param string[]             $keys The keys, in order.
	 *
	 * @return string|int|float|bool|null The value.
	 */
	private function first(array $row, array $keys): string|int|float|bool|null {
		foreach ($keys as $key) {
			if (isset($row[$key]) === true && is_scalar($row[$key]) === true) {
				return $row[$key];
			}
		}

		return null;
	}

	/**
	 * The path of a request target, query and fragment dropped.
	 *
	 * @param string $target The target, a path or an absolute URL.
	 *
	 * @return string The path, or ''.
	 */
	private function pathOf(string $target): string {
		$target = trim($target);
		if ($target === '') {
			return '';
		}

		if (str_starts_with($target, '/') === false) {
			$path = parse_url($target, PHP_URL_PATH);
			$target = '';
			if (is_string($path) === true) {
				$target = $path;
			}
		}

		$cut = strcspn($target, '?#');

		return mb_substr(substr($target, 0, $cut), 0, 512);
	}

	/**
	 * Whether a path names an asset rather than a page.
	 *
	 * @param string $path The path.
	 *
	 * @return bool True for an asset.
	 */
	private function isAsset(string $path): bool {
		$dot = strrpos($path, '.');
		$slash = strrpos($path, '/');
		if ($dot === false || ($slash !== false && $dot < $slash)) {
			return false;
		}

		return in_array(strtolower(substr($path, $dot + 1)), self::ASSET_EXTENSIONS, true);
	}

	/**
	 * A log timestamp as ISO 8601 UTC.
	 *
	 * @param string $value The `10/Oct/2000:13:55:36 -0700` or ISO form.
	 *
	 * @return string|null The timestamp, or null when unreadable.
	 */
	private function timestamp(string $value): ?string {
		$value = trim($value);
		if ($value === '') {
			return null;
		}

		$parsed = date_create_immutable_from_format('d/M/Y:H:i:s O', $value);
		if ($parsed === false) {
			try {
				$parsed = new DateTimeImmutable($value);
			} catch (Throwable) {
				return null;
			}
		}

		return $parsed->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
	}
}
