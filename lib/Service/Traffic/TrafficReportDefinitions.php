<?php

/**
 * Portaliq Traffic Report Definitions.
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
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-scheduled-report-must-be-sent-once-per-period
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * Normalises what a portal wrote under `traffic.reports`, `traffic.alerts`
 * and `traffic.rollupOf` into what the report job acts on.
 *
 * The same posture as the outcome definitions: a definition that cannot
 * be acted on is dropped, not repaired. A report with no recipient would
 * be computed for nobody; an alert on a metric this app does not count
 * would never fire and never say why.
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-scheduled-report-must-be-sent-once-per-period
 */
class TrafficReportDefinitions {

	/**
	 * The cadences a report may have.
	 *
	 * @var string[]
	 */
	public const CADENCES = ['daily', 'weekly', 'monthly'];

	/**
	 * The sections a report may carry, in the order they are rendered.
	 *
	 * @var string[]
	 */
	public const SECTIONS = ['overview', 'pages', 'sources', 'visitors', 'goals', 'funnels', 'forms'];

	/**
	 * The metrics an alert may watch, beside `goal:<id>`.
	 *
	 * @var string[]
	 */
	public const METRICS = ['pageViews', 'sessions', 'visitors', 'notFound'];

	/**
	 * The comparisons.
	 *
	 * @var string[]
	 */
	public const COMPARISONS = ['above', 'below', 'changeAbove'];

	/**
	 * The alert periods.
	 *
	 * @var string[]
	 */
	public const PERIODS = ['day', 'week'];

	/**
	 * The most reports, alerts, recipients or members one portal declares.
	 */
	public const MAX = 20;

	/**
	 * The portal's reports, each `{id, name, cadence, recipients, sections}`.
	 *
	 * @param mixed $value The configured list.
	 *
	 * @return array<int, array<string, mixed>> The usable reports.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-scheduled-report-must-be-sent-once-per-period
	 */
	public function reports(mixed $value): array {
		$out = [];
		foreach ($this->rows(value: $value) as $row) {
			$id = $this->token(value: ($row['id'] ?? null));
			$cadence = (string)($row['cadence'] ?? '');
			$recipients = $this->recipients(value: ($row['recipients'] ?? null));
			if ($id === '' || isset($out[$id]) === true || in_array($cadence, self::CADENCES, true) === false || $recipients === []) {
				continue;
			}

			$sections = $this->names(value: ($row['sections'] ?? null), known: self::SECTIONS);
			if ($sections === []) {
				$sections = ['overview'];
			}

			$out[$id] = [
				'id' => $id,
				'name' => $this->name(value: ($row['name'] ?? null), fallback: $id),
				'cadence' => $cadence,
				'recipients' => $recipients,
				'sections' => $sections,
			];
		}

		return array_values($out);
	}

	/**
	 * The portal's alerts, each `{id, name, metric, comparison, threshold, period, recipients}`.
	 *
	 * @param mixed                            $value The configured list.
	 * @param array<int, array<string, mixed>> $goals The portal's resolved goals, so `goal:<id>` can be checked.
	 *
	 * @return array<int, array<string, mixed>> The usable alerts.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-an-alert-must-fire-once-per-period
	 */
	public function alerts(mixed $value, array $goals = []): array {
		$goalIds = [];
		foreach ($goals as $goal) {
			if (is_array($goal) === true && isset($goal['id']) === true) {
				$goalIds[(string)$goal['id']] = true;
			}
		}

		$out = [];
		foreach ($this->rows(value: $value) as $row) {
			$id = $this->token(value: ($row['id'] ?? null));
			if ($id === '' || isset($out[$id]) === true || $this->usableAlert(row: $row, goalIds: $goalIds) === false) {
				continue;
			}

			$out[$id] = [
				'id' => $id,
				'name' => $this->name(value: ($row['name'] ?? null), fallback: $id),
				'metric' => (string)$row['metric'],
				'comparison' => (string)$row['comparison'],
				'threshold' => (float)$row['threshold'],
				'period' => (string)($row['period'] ?? 'day'),
				'recipients' => $this->recipients(value: ($row['recipients'] ?? null)),
			];
		}

		return array_values($out);
	}

	/**
	 * The slugs a roll-up portal sums, without itself and without repeats.
	 *
	 * @param mixed  $value The configured list.
	 * @param string $self  The portal's own slug.
	 *
	 * @return string[] The member slugs.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
	 */
	public function rollupOf(mixed $value, string $self): array {
		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach (array_slice(array_values($value), 0, self::MAX) as $slug) {
			if (is_string($slug) === false || preg_match('/^[a-z0-9][a-z0-9-]{0,127}$/', $slug) !== 1 || $slug === $self) {
				continue;
			}

			$out[$slug] = true;
		}

		return array_keys($out);
	}

	/**
	 * Whether an alert row has everything the job needs: a known metric,
	 * comparison and period, a numeric threshold and someone to tell.
	 *
	 * @param array<string, mixed> $row     The configured alert.
	 * @param array<string, true>  $goalIds The portal's goal ids.
	 *
	 * @return bool True when usable.
	 */
	private function usableAlert(array $row, array $goalIds): bool {
		if (is_numeric($row['threshold'] ?? null) === false || $this->recipients(value: ($row['recipients'] ?? null)) === []) {
			return false;
		}

		if ($this->knowsMetric(metric: (string)($row['metric'] ?? ''), goalIds: $goalIds) === false) {
			return false;
		}

		return in_array((string)($row['comparison'] ?? ''), self::COMPARISONS, true) === true
			&& in_array((string)($row['period'] ?? 'day'), self::PERIODS, true) === true;
	}

	/**
	 * Whether an alert metric is one this app counts.
	 *
	 * @param string              $metric  The metric.
	 * @param array<string, true> $goalIds The portal's goal ids.
	 *
	 * @return bool True when known.
	 */
	private function knowsMetric(string $metric, array $goalIds): bool {
		if (in_array($metric, self::METRICS, true) === true) {
			return true;
		}

		if (str_starts_with($metric, TrafficSegments::GOAL_PREFIX) === true) {
			return isset($goalIds[substr($metric, strlen(TrafficSegments::GOAL_PREFIX))]);
		}

		return false;
	}

	/**
	 * The recipients: Nextcloud user ids or e-mail addresses, bounded.
	 *
	 * @param mixed $value The configured list.
	 *
	 * @return string[] The recipients.
	 */
	private function recipients(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach (array_slice(array_values($value), 0, self::MAX) as $recipient) {
			if (is_string($recipient) === false) {
				continue;
			}

			$recipient = trim($recipient);
			if ($recipient === '' || mb_strlen($recipient) > 254 || preg_match('/[\s,;<>]/', $recipient) === 1) {
				continue;
			}

			$out[$recipient] = true;
		}

		return array_keys($out);
	}

	/**
	 * The known names from a list, in the known order.
	 *
	 * @param mixed    $value The configured list.
	 * @param string[] $known The names this app knows.
	 *
	 * @return string[] The names.
	 */
	private function names(mixed $value, array $known): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter($known, static fn (string $name): bool => in_array($name, $value, true)));
	}

	/**
	 * The list's rows that are maps, bounded.
	 *
	 * @param mixed $value The configured list.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rows(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_slice(array_values(array_filter($value, static fn ($row): bool => is_array($row))), 0, self::MAX);
	}

	/**
	 * A bounded token id, or ''.
	 *
	 * @param mixed $value The configured id.
	 *
	 * @return string The id.
	 */
	private function token(mixed $value): string {
		if (is_string($value) === false || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) !== 1) {
			return '';
		}

		return $value;
	}

	/**
	 * A bounded display name, falling back when none was given.
	 *
	 * @param mixed  $value    The configured name.
	 * @param string $fallback What to call it otherwise.
	 *
	 * @return string The name.
	 */
	private function name(mixed $value, string $fallback): string {
		if (is_string($value) === false || trim($value) === '') {
			return $fallback;
		}

		return mb_substr(trim($value), 0, 128);
	}
}
