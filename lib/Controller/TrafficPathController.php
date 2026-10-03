<?php

/**
 * Portaliq Traffic Path Controller
 *
 * The path explorer's one endpoint (portal-traffic-path-explorer): the
 * steps visitors took from a starting point or to an ending point, for a
 * portal, a period and a segment.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
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
 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-paths-endpoint-must-count-each-visits-own-path-from-the-raw-events
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Service\Traffic\TrafficPaths;
use OCA\Portaliq\Service\Traffic\TrafficPathService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * ADMIN ONLY, BY OMISSION, like the summary and the export beside it. The
 * paths are read from raw events, which are closer to a single visitor
 * than any daily figure, so this is an operator's surface and nobody
 * else's.
 *
 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-paths-endpoint-must-count-each-visits-own-path-from-the-raw-events
 */
class TrafficPathController extends Controller {

	/**
	 * The most days one request spans, the same as the export.
	 */
	public const MAX_DAYS = 366;

	/**
	 * The steps shown when the request names none.
	 */
	public const DEFAULT_STEPS = 3;

	/**
	 * The longest page path accepted as a starting or ending point.
	 */
	private const MAX_PATH = 2048;

	/**
	 * Constructor.
	 *
	 * @param string             $appName The app id.
	 * @param IRequest           $request The request.
	 * @param TrafficPathService $paths   Reads and counts the paths.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TrafficPathService $paths,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()


	/**
	 * The steps visitors took, from a start point or to an end point.
	 *
	 * @param string $portal  The portal slug.
	 * @param string $from    The first day, YYYY-MM-DD.
	 * @param string $to      The last day, YYYY-MM-DD.
	 * @param string $segment The segment id, '' for all visits.
	 * @param string $mode    `start` (forward) or `end` (backward); '' for start.
	 * @param string $anchor  A page path, '' for the start or end of a visit.
	 * @param string $steps   1 to 10 steps after the start or end point; '' for 3.
	 * @param string $trail   A JSON list with the chosen page per step, null for none; '' for none at all.
	 *
	 * @return JSONResponse The explorer, or a 400/404 with a reason.
	 *
	 * @auth admin-only raw traffic events are an operator's surface, the same posture as the summary and the export
	 *
	 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-paths-endpoint-must-count-each-visits-own-path-from-the-raw-events
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- one argument per
	 * query parameter, which is how the framework hands them over.
	 */
	public function paths(
		string $portal = '',
		string $from = '',
		string $to = '',
		string $segment = '',
		string $mode = '',
		string $anchor = '',
		string $steps = '',
		string $trail = ''
	): JSONResponse {
		if ($mode === '') {
			$mode = 'start';
		}

		if ($steps === '') {
			$steps = (string)self::DEFAULT_STEPS;
		}

		$chosen = $this->trail(value: $trail, steps: (int)$steps);

		$reason = $this->refusal(portal: $portal, from: $from, to: $to, segment: $segment)
			?? $this->explorerRefusal(mode: $mode, anchor: $anchor, steps: $steps, trail: $chosen);
		if ($reason !== null) {
			return $this->answer(data: ['error' => $reason], status: Http::STATUS_BAD_REQUEST);
		}

		$result = $this->paths->explore(
			portal: $portal,
			from: $from,
			to: $to,
			segment: $segment,
			mode: $mode,
			anchor: $anchor,
			steps: (int)$steps,
			trail: (array)$chosen
		);

		if (($result['error'] ?? null) === 'unknown-portal') {
			return $this->answer(data: $result, status: Http::STATUS_NOT_FOUND);
		}

		if (isset($result['error']) === true) {
			return $this->answer(data: $result, status: Http::STATUS_BAD_REQUEST);
		}

		return $this->answer(data: $result, status: Http::STATUS_OK);
	}//end paths()


	/**
	 * A JSON answer nobody caches.
	 *
	 * @param array<string, mixed> $data   The body.
	 * @param int                  $status The status.
	 *
	 * @return JSONResponse The response.
	 */
	private function answer(array $data, int $status): JSONResponse {
		$response = new JSONResponse($data, $status);
		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end answer()


	/**
	 * Why the portal, period or segment is refused, or null.
	 *
	 * @param string $portal  The portal slug.
	 * @param string $from    The first day.
	 * @param string $to      The last day.
	 * @param string $segment The segment id.
	 *
	 * @return string|null The reason.
	 */
	private function refusal(string $portal, string $from, string $to, string $segment): ?string {
		if (preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,127}$/', $portal) !== 1) {
			return 'missing-portal';
		}

		if ($this->isDay(value: $from) === false || $this->isDay(value: $to) === false || $from > $to) {
			return 'invalid-range';
		}

		if ((strtotime($to) - strtotime($from)) / 86400 >= self::MAX_DAYS) {
			return 'range-too-long';
		}

		if ($segment !== '' && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $segment) !== 1) {
			return 'invalid-segment';
		}

		return null;
	}//end refusal()


	/**
	 * Why the explorer's own parameters are refused, or null.
	 *
	 * @param string                   $mode   The mode.
	 * @param string                   $anchor The page, or ''.
	 * @param string                   $steps  The steps, as sent.
	 * @param array<int, ?string>|null $trail  The parsed trail, null when it did not parse.
	 *
	 * @return string|null The reason.
	 */
	private function explorerRefusal(string $mode, string $anchor, string $steps, ?array $trail): ?string {
		if (in_array($mode, TrafficPaths::MODES, true) === false) {
			return 'invalid-mode';
		}

		if ($anchor !== '' && $this->isPath(value: $anchor) === false) {
			return 'invalid-anchor';
		}

		if (preg_match('/^\d{1,2}$/', $steps) !== 1 || (int)$steps < 1 || (int)$steps > TrafficPaths::MAX_STEPS) {
			return 'invalid-steps';
		}

		if ($trail === null) {
			return 'invalid-trail';
		}

		return null;
	}//end explorerRefusal()


	/**
	 * The trail parsed from JSON, or null when it is not a list of at most
	 * `steps + 1` page paths and nulls.
	 *
	 * @param string $value The JSON, or ''.
	 * @param int    $steps The steps after step 0.
	 *
	 * @return array<int, ?string>|null The trail.
	 */
	private function trail(string $value, int $steps): ?array {
		if ($value === '') {
			return [];
		}

		$parsed = json_decode($value, true);
		if (is_array($parsed) === false || array_is_list($parsed) === false || count($parsed) > $steps + 1) {
			return null;
		}

		foreach ($parsed as $choice) {
			if ($this->isChoice(choice: $choice) === false) {
				return null;
			}
		}

		return $parsed;
	}//end trail()


	/**
	 * Whether one trail entry is "no choice" (null or '') or a page path.
	 *
	 * @param mixed $choice The entry.
	 *
	 * @return bool True when it is.
	 */
	private function isChoice(mixed $choice): bool {
		if ($choice === null || $choice === '') {
			return true;
		}

		return is_string($choice) === true && $this->isPath(value: $choice) === true;
	}//end isChoice()


	/**
	 * Whether a value is a page path: starts with a slash, no control
	 * characters, not absurdly long.
	 *
	 * @param string $value The value.
	 *
	 * @return bool True when it is.
	 */
	private function isPath(string $value): bool {
		return strlen($value) <= self::MAX_PATH && preg_match('/^\/[^\x00-\x1F\x7F]*$/u', $value) === 1;
	}//end isPath()


	/**
	 * Whether a value is a YYYY-MM-DD date.
	 *
	 * @param string $value The value.
	 *
	 * @return bool True when it is.
	 */
	private function isDay(string $value): bool {
		return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false;
	}//end isDay()
}//end class
