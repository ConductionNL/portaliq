<?php

/**
 * Portaliq Traffic Segments.
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
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-segment-must-be-a-saved-filter-over-sessions
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * A segment is a saved filter over sessions: a name and a list of
 * conditions, all of which must hold for a session to belong to it.
 *
 * Normalised ONCE, at configuration time, and refused there. A condition
 * on a dimension this app does not know is not "nothing matches", it is
 * a segment that would silently report zero for the rest of its life, so
 * the whole segment is dropped and the resolved block the Traffic page
 * reads simply does not list it. The admin form still shows the record
 * as written.
 *
 * Matching is per SESSION, on the first non-empty value of a dimension
 * across the session's events, the same rule the dimension counts use.
 * Three dimensions are not fields: `visitorType` (new or returning, only
 * known with a persisted client id), `userRef-present` (whether any event
 * carried an account reference) and `goal:<id>` (whether the session met
 * that goal). Each evaluates to a string so the four operators apply to
 * everything alike.
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-segment-must-be-a-saved-filter-over-sessions
 */
class TrafficSegments {

	/**
	 * The event fields a condition may read.
	 *
	 * @var string[]
	 */
	public const FIELD_DIMENSIONS = [
		'channel',
		'deviceType',
		'browser',
		'os',
		'language',
		'region',
		'campaign',
		'source',
		'medium',
		'referrerHost',
		'pageReferrer',
	];

	/**
	 * The derived dimensions, evaluated from the session rather than read
	 * off an event.
	 *
	 * @var string[]
	 */
	public const DERIVED_DIMENSIONS = ['userRef-present', 'visitorType'];

	/**
	 * The prefix of a goal dimension: `goal:<id>`.
	 */
	public const GOAL_PREFIX = 'goal:';

	/**
	 * The operators.
	 *
	 * @var string[]
	 */
	public const OPERATORS = ['is', 'isNot', 'contains', 'startsWith'];

	/**
	 * The most segments one portal declares, and the most conditions one
	 * segment carries.
	 */
	public const MAX_SEGMENTS = 20;

	/**
	 * The most conditions one segment carries.
	 */
	public const MAX_CONDITIONS = 10;

	/**
	 * Constructor.
	 *
	 * @param TrafficMatch $match Evaluates a goal's match against an event.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TrafficMatch $match = new TrafficMatch(),
	) {
	}

	/**
	 * The usable segments from what a portal wrote under `traffic.segments`.
	 *
	 * @param mixed                            $value The configured list.
	 * @param array<int, array<string, mixed>> $goals The portal's resolved goals, so a `goal:<id>` condition can be checked.
	 *
	 * @return array<int, array{id: string, name: string, conditions: array<int, array{dimension: string, operator: string, value: string}>}> The segments.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-segment-must-be-a-saved-filter-over-sessions
	 */
	public function definitions(mixed $value, array $goals = []): array {
		if (is_array($value) === false) {
			return [];
		}

		$goalIds = [];
		foreach ($goals as $goal) {
			if (is_array($goal) === true && isset($goal['id']) === true) {
				$goalIds[(string)$goal['id']] = true;
			}
		}

		$out = [];
		foreach (array_slice(array_values($value), 0, self::MAX_SEGMENTS) as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$id = (string)($row['id'] ?? '');
			if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) !== 1 || isset($out[$id]) === true) {
				continue;
			}

			$conditions = $this->conditions(value: ($row['conditions'] ?? null), goalIds: $goalIds);
			if ($conditions === null) {
				continue;
			}

			$name = trim((string)($row['name'] ?? ''));
			$out[$id] = [
				'id' => $id,
				'name' => ($name === '') ? $id : mb_substr($name, 0, 128),
				'conditions' => $conditions,
			];
		}

		return array_values($out);
	}

	/**
	 * Whether a condition's dimension is one this app can evaluate.
	 *
	 * @param string              $dimension The dimension.
	 * @param array<string, true> $goalIds   The portal's goal ids.
	 *
	 * @return bool True when known.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-segment-must-be-a-saved-filter-over-sessions
	 */
	public function knowsDimension(string $dimension, array $goalIds = []): bool {
		if (in_array($dimension, self::FIELD_DIMENSIONS, true) === true || in_array($dimension, self::DERIVED_DIMENSIONS, true) === true) {
			return true;
		}

		if (str_starts_with($dimension, self::GOAL_PREFIX) === true) {
			return isset($goalIds[substr($dimension, strlen(self::GOAL_PREFIX))]);
		}

		return false;
	}

	/**
	 * The sessions that belong to a segment.
	 *
	 * @param array<string, mixed>             $segment  A resolved segment.
	 * @param array<int, array<string, mixed>> $sessions The day's sessions.
	 * @param array<int, array<string, mixed>> $goals    The portal's resolved goals.
	 *
	 * @return array<int, array<string, mixed>> The matching sessions, in order.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-segment-must-be-a-saved-filter-over-sessions
	 */
	public function filter(array $segment, array $sessions, array $goals = []): array {
		return array_values(array_filter(
			$sessions,
			fn (array $session): bool => $this->matches(segment: $segment, session: $session, goals: $goals)
		));
	}

	/**
	 * Whether one session meets every condition of a segment.
	 *
	 * @param array<string, mixed>             $segment The resolved segment.
	 * @param array<string, mixed>             $session The session.
	 * @param array<int, array<string, mixed>> $goals   The portal's resolved goals.
	 *
	 * @return bool True when all conditions hold.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-segment-must-be-a-saved-filter-over-sessions
	 */
	public function matches(array $segment, array $session, array $goals = []): bool {
		foreach (($segment['conditions'] ?? []) as $condition) {
			if (is_array($condition) === false) {
				return false;
			}

			$actual = $this->evaluate(dimension: (string)($condition['dimension'] ?? ''), session: $session, goals: $goals);
			if ($this->holds(operator: (string)($condition['operator'] ?? ''), actual: $actual, expected: (string)($condition['value'] ?? '')) === false) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The normalised conditions, or null when one of them cannot be acted on.
	 *
	 * @param mixed               $value   The configured list.
	 * @param array<string, true> $goalIds The portal's goal ids.
	 *
	 * @return array<int, array{dimension: string, operator: string, value: string}>|null The conditions.
	 */
	private function conditions(mixed $value, array $goalIds): ?array {
		if (is_array($value) === false || $value === []) {
			return null;
		}

		$out = [];
		foreach (array_slice(array_values($value), 0, self::MAX_CONDITIONS) as $condition) {
			if (is_array($condition) === false) {
				return null;
			}

			$dimension = trim((string)($condition['dimension'] ?? ''));
			$operator = trim((string)($condition['operator'] ?? ''));
			$expected = $condition['value'] ?? '';
			if (is_bool($expected) === true) {
				$expected = ($expected === true) ? 'true' : 'false';
			}

			if (is_scalar($expected) === false || $this->knowsDimension(dimension: $dimension, goalIds: $goalIds) === false) {
				return null;
			}

			if (in_array($operator, self::OPERATORS, true) === false) {
				return null;
			}

			$out[] = ['dimension' => $dimension, 'operator' => $operator, 'value' => mb_substr(trim((string)$expected), 0, 256)];
		}

		return $out;
	}

	/**
	 * A session's value for a dimension: a field's first non-empty value,
	 * or `true`/`false` for a derived one.
	 *
	 * @param string                           $dimension The dimension.
	 * @param array<string, mixed>             $session   The session.
	 * @param array<int, array<string, mixed>> $goals     The portal's resolved goals.
	 *
	 * @return string The value, '' when the session carries none.
	 */
	private function evaluate(string $dimension, array $session, array $goals): string {
		$events = $session['events'] ?? [];
		if (is_array($events) === false) {
			$events = [];
		}

		if ($dimension === 'userRef-present') {
			return $this->flag(value: $this->firstOf(events: $events, key: 'userRef') !== '');
		}

		if ($dimension === 'visitorType') {
			return $this->visitorType(session: $session);
		}

		if (str_starts_with($dimension, self::GOAL_PREFIX) === true) {
			return $this->flag(value: $this->metGoal(id: substr($dimension, strlen(self::GOAL_PREFIX)), events: $events, goals: $goals));
		}

		return $this->firstOf(events: $events, key: $dimension);
	}

	/**
	 * Whether the operator holds between what the session has and what
	 * the condition wants. Comparisons ignore case: `Desktop` and
	 * `desktop` are one device.
	 *
	 * @param string $operator The operator.
	 * @param string $actual   The session's value.
	 * @param string $expected The condition's value.
	 *
	 * @return bool True when it holds.
	 */
	private function holds(string $operator, string $actual, string $expected): bool {
		$actual = mb_strtolower($actual);
		$expected = mb_strtolower($expected);

		return match ($operator) {
			'is' => $actual === $expected,
			'isNot' => $actual !== $expected,
			'contains' => $expected !== '' && str_contains($actual, $expected),
			'startsWith' => $expected !== '' && str_starts_with($actual, $expected),
			default => false,
		};
	}

	/**
	 * The first non-empty value of a field across a session's events.
	 *
	 * @param array<int, mixed> $events The events.
	 * @param string            $key    The field.
	 *
	 * @return string The value, or ''.
	 */
	private function firstOf(array $events, string $key): string {
		foreach ($events as $event) {
			if (is_array($event) === false) {
				continue;
			}

			$value = $event[$key] ?? null;
			if (is_scalar($value) === true && trim((string)$value) !== '') {
				return trim((string)$value);
			}
		}

		return '';
	}

	/**
	 * `new` or `returning` from the session's `session_start`, '' when the
	 * visitor cannot say (cookieless, or no start event).
	 *
	 * @param array<string, mixed> $session The session.
	 *
	 * @return string The type.
	 */
	private function visitorType(array $session): string {
		if (str_starts_with((string)($session['visitor'] ?? ''), 'c:') === false) {
			return '';
		}

		foreach (($session['events'] ?? []) as $event) {
			if (is_array($event) === false || ($event['name'] ?? '') !== 'session_start') {
				continue;
			}

			$type = (string)($event['params']['visitorType'] ?? '');
			if ($type === 'new' || (($event['params']['first'] ?? false) === true)) {
				return 'new';
			}

			if ($type === 'returning') {
				return 'returning';
			}
		}

		return '';
	}

	/**
	 * Whether any event of the session met the goal with this id.
	 *
	 * @param string                           $id     The goal id.
	 * @param array<int, mixed>                $events The events.
	 * @param array<int, array<string, mixed>> $goals  The portal's resolved goals.
	 *
	 * @return bool True when met at least once.
	 */
	private function metGoal(string $id, array $events, array $goals): bool {
		foreach ($goals as $goal) {
			if ((string)($goal['id'] ?? '') !== $id || is_array($goal['match'] ?? null) === false) {
				continue;
			}

			foreach ($events as $event) {
				if (is_array($event) === true && $this->match->matches(match: $goal['match'], event: $event, type: (string)($goal['type'] ?? '')) === true) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * A boolean as the string the operators compare.
	 *
	 * @param bool $value The flag.
	 *
	 * @return string `true` or `false`.
	 */
	private function flag(bool $value): string {
		return ($value === true) ? 'true' : 'false';
	}
}
