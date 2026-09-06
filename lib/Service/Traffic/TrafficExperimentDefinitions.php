<?php

/**
 * Portaliq Traffic Experiment Definitions.
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
 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-page-experiment-must-be-evaluated-per-session-against-its-goal
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * Normalises what a portal wrote under `traffic.experiments` into what
 * the client acts on and the aggregation counts
 * (portal-traffic-experiments).
 *
 * A definition that cannot run is DROPPED, not repaired, on the same
 * reasoning as the goals: an experiment without an id or a page, or with
 * fewer than two usable variants. A DRAFT is dropped too. The resolved
 * block is served to every visitor by the content API, and a draft's
 * variant text is copy nobody approved yet; it stays in the portal
 * record and reaches nobody until its status says running.
 *
 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-page-experiment-must-be-evaluated-per-session-against-its-goal
 */
class TrafficExperimentDefinitions {

	/**
	 * The statuses an experiment may have.
	 *
	 * @var string[]
	 */
	public const STATUSES = ['draft', 'running', 'stopped'];

	/**
	 * The most experiments a portal declares.
	 */
	public const MAX_EXPERIMENTS = 20;

	/**
	 * The most variants one experiment carries.
	 */
	public const MAX_VARIANTS = 10;

	/**
	 * The most text changes one variant carries.
	 */
	public const MAX_CHANGES = 20;

	/**
	 * The longest name kept.
	 */
	private const MAX_NAME = 128;

	/**
	 * The longest route and selector kept.
	 */
	private const MAX_ROUTE = 256;

	/**
	 * The longest replacement text kept.
	 */
	private const MAX_TEXT = 1024;

	/**
	 * The portal's usable experiments, drafts left out.
	 *
	 * @param mixed $value The configured list.
	 *
	 * @return array<int, array<string, mixed>> Each `{id, name, status, page, variants, goal, startedAt, stoppedAt}`.
	 *
	 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-page-experiment-must-be-evaluated-per-session-against-its-goal
	 */
	public function definitions(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach (array_slice(array_values($value), 0, self::MAX_EXPERIMENTS) as $row) {
			$experiment = $this->definition(row: $row);
			if ($experiment === null || isset($out[$experiment['id']]) === true) {
				continue;
			}

			$out[$experiment['id']] = $experiment;
		}

		return array_values($out);
	}

	/**
	 * One experiment from the configured row, or null when unusable.
	 *
	 * @param mixed $row The configured row.
	 *
	 * @return array<string, mixed>|null The experiment.
	 */
	private function definition(mixed $row): ?array {
		if (is_array($row) === false) {
			return null;
		}

		$id = $this->token(value: ($row['id'] ?? null));
		$status = (string)($row['status'] ?? 'draft');
		$page = $this->route(value: ($row['page'] ?? null));
		if ($id === '' || $page === '' || in_array($status, self::STATUSES, true) === false || $status === 'draft') {
			return null;
		}

		$variants = $this->variants(value: ($row['variants'] ?? null), page: $page);
		if (count($variants) < 2) {
			return null;
		}

		return [
			'id' => $id,
			'name' => $this->text(value: ($row['name'] ?? null), max: self::MAX_NAME, fallback: $id),
			'status' => $status,
			'page' => $page,
			'variants' => $variants,
			'goal' => $this->token(value: ($row['goal'] ?? null)),
			'startedAt' => $this->instant(value: ($row['startedAt'] ?? null)),
			'stoppedAt' => $this->instant(value: ($row['stoppedAt'] ?? null)),
		];
	}

	/**
	 * The usable variants of an experiment: an id, a name, a positive
	 * weight, and a page route (not the experiment's own page) or text
	 * changes. A control variant that changes nothing carries an empty
	 * `changes` list, which is a usable variant.
	 *
	 * @param mixed  $value The configured list.
	 * @param string $page  The experiment's page, which a variant route may not repeat.
	 *
	 * @return array<int, array<string, mixed>> The variants.
	 */
	private function variants(mixed $value, string $page): array {
		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach (array_slice(array_values($value), 0, self::MAX_VARIANTS) as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$id = $this->token(value: ($row['id'] ?? null));
			if ($id === '' || isset($out[$id]) === true) {
				continue;
			}

			$variant = [
				'id' => $id,
				'name' => $this->text(value: ($row['name'] ?? null), max: self::MAX_NAME, fallback: $id),
				'weight' => $this->weight(value: ($row['weight'] ?? null)),
				'changes' => $this->changes(value: ($row['changes'] ?? null)),
			];
			$route = $this->route(value: ($row['pageRoute'] ?? null));
			if ($route !== '' && $route !== $page) {
				unset($variant['changes']);
				$variant['pageRoute'] = $route;
			}

			$out[$id] = $variant;
		}

		return array_values($out);
	}

	/**
	 * The text changes of a variant, each a selector and the text.
	 *
	 * @param mixed $value The configured list.
	 *
	 * @return array<int, array{selector: string, text: string}> The changes.
	 */
	private function changes(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach (array_slice(array_values($value), 0, self::MAX_CHANGES) as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$selector = $this->text(value: ($row['selector'] ?? null), max: self::MAX_ROUTE, fallback: '');
			if ($selector === '') {
				continue;
			}

			$out[] = ['selector' => $selector, 'text' => $this->text(value: ($row['text'] ?? null), max: self::MAX_TEXT, fallback: '')];
		}

		return $out;
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
	 * An in-site route: starts with a slash, no query, trailing slash
	 * dropped; '' when unusable.
	 *
	 * @param mixed $value The configured route.
	 *
	 * @return string The route.
	 */
	private function route(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		$route = mb_substr(trim($value), 0, self::MAX_ROUTE);
		if (preg_match('#^/[^?\#]*$#', $route) !== 1) {
			return '';
		}

		if ($route !== '/' && str_ends_with($route, '/') === true) {
			$route = rtrim($route, '/');
		}

		return $route;
	}

	/**
	 * A bounded string, or the fallback.
	 *
	 * @param mixed  $value    The configured value.
	 * @param int    $max      The most characters kept.
	 * @param string $fallback What to use when there is none.
	 *
	 * @return string The string.
	 */
	private function text(mixed $value, int $max, string $fallback): string {
		if (is_string($value) === false || trim($value) === '') {
			return $fallback;
		}

		return mb_substr(trim($value), 0, $max);
	}

	/**
	 * A positive weight, else 1.
	 *
	 * @param mixed $value The configured weight.
	 *
	 * @return float The weight.
	 */
	private function weight(mixed $value): float {
		if (is_numeric($value) === true && (float)$value > 0) {
			return (float)$value;
		}

		return 1.0;
	}

	/**
	 * An instant as the ISO 8601 UTC string the events are compared with,
	 * or '' when none was given or it does not parse.
	 *
	 * @param mixed $value The configured instant.
	 *
	 * @return string The instant.
	 */
	private function instant(mixed $value): string {
		if (is_string($value) === false || trim($value) === '') {
			return '';
		}

		$stamp = strtotime(trim($value));
		if ($stamp === false) {
			return '';
		}

		return gmdate('Y-m-d\TH:i:s', $stamp) . '.000Z';
	}
}
