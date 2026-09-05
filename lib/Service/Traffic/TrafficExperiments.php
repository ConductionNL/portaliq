<?php

/**
 * Portaliq Traffic Experiments.
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
 * Page experiments (portal-traffic-experiments): what a portal declared
 * under `traffic.experiments`, and what its sessions say about them.
 *
 * THE CLIENT PICKS, THE SERVER COUNTS. A visitor is put on a variant in
 * the browser, because that is where the page is changed; every event of
 * that session then carries `experiment` and `variant` in its params. The
 * aggregation reads the tag on the FIRST tagged event of a session and
 * asks whether the session met the experiment's goal. Two proportions,
 * one z-test, and a winner only when the difference is unlikely to be
 * chance AND each variant has seen enough sessions for the test to mean
 * anything. Under thirty sessions per variant the answer is "not enough
 * data", never a winner, because a 2 against 0 is a coin, not a result.
 *
 * A stopped experiment keeps its rows (the result is the point of having
 * run it) but counts no session tagged after the moment it stopped. What
 * a portal may declare is TrafficExperimentDefinitions' business; this
 * class only counts.
 *
 * Pure: definitions and sessions in, rows out.
 *
 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-page-experiment-must-be-evaluated-per-session-against-its-goal
 */
class TrafficExperiments {

	/**
	 * Sessions each variant needs before a winner may be named.
	 */
	public const MIN_SESSIONS = 30;

	/**
	 * The confidence a difference needs before a winner is named.
	 */
	public const WINNING_CONFIDENCE = 0.95;

	/**
	 * Constructor.
	 *
	 * @param TrafficMatch $match Evaluates a goal's match against one event.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TrafficMatch $match = new TrafficMatch(),
	) {
	}

	/**
	 * Whether a posted tag names a running experiment and one of its
	 * variants. The validator strips any other tag.
	 *
	 * @param array<string, mixed> $config     The resolved configuration.
	 * @param string               $experiment The experiment id on the event.
	 * @param string               $variant    The variant id on the event.
	 *
	 * @return bool True when the tag may be stored.
	 *
	 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-page-experiment-must-be-evaluated-per-session-against-its-goal
	 */
	public function acceptsTag(array $config, string $experiment, string $variant): bool {
		foreach ((array)($config['experiments'] ?? []) as $definition) {
			if (is_array($definition) === false || ($definition['id'] ?? '') !== $experiment || ($definition['status'] ?? '') !== 'running') {
				continue;
			}

			foreach ((array)($definition['variants'] ?? []) as $candidate) {
				if (is_array($candidate) === true && ($candidate['id'] ?? '') === $variant) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * The experiment rows for the rollup, in the portal's order.
	 *
	 * @param array<int, array<string, mixed>> $experiments The resolved experiments.
	 * @param array<int, array<string, mixed>> $sessions    The day's sessions.
	 * @param array<int, array<string, mixed>> $goals       The resolved goals, for the conversions.
	 *
	 * @return array<int, array<string, mixed>> Rows of `{id, name, status, variants: [{id, name, sessions, conversions, rate}], winner, confidence}`.
	 *
	 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-page-experiment-must-be-evaluated-per-session-against-its-goal
	 */
	public function rows(array $experiments, array $sessions, array $goals): array {
		$byId = [];
		foreach ($goals as $goal) {
			if (is_array($goal) === true && isset($goal['id']) === true) {
				$byId[(string)$goal['id']] = $goal;
			}
		}

		$out = [];
		foreach ($experiments as $experiment) {
			$variants = $this->counts(experiment: $experiment, sessions: $sessions, goal: ($byId[(string)$experiment['goal']] ?? null));
			$out[] = ['id' => $experiment['id'], 'name' => $experiment['name'], 'status' => $experiment['status'], 'variants' => $variants]
				+ $this->verdict(variants: $variants);
		}

		return $out;
	}

	/**
	 * One experiment's variant rows over the sessions.
	 *
	 * @param array<string, mixed>             $experiment The experiment.
	 * @param array<int, array<string, mixed>> $sessions   The sessions.
	 * @param array<string, mixed>|null        $goal       The resolved goal, or null for none.
	 *
	 * @return array<int, array<string, mixed>> Rows of `{id, name, sessions, conversions, rate}`.
	 */
	private function counts(array $experiment, array $sessions, ?array $goal): array {
		$counts = [];
		foreach ($experiment['variants'] as $variant) {
			$counts[$variant['id']] = ['id' => $variant['id'], 'name' => $variant['name'], 'sessions' => 0, 'conversions' => 0, 'rate' => 0.0];
		}

		foreach ($sessions as $session) {
			$variantId = $this->variantOf(experiment: $experiment, session: $session);
			if ($variantId === '' || isset($counts[$variantId]) === false) {
				continue;
			}

			$counts[$variantId]['sessions']++;
			$counts[$variantId]['conversions'] += (int)($goal !== null && $this->converted(goal: $goal, session: $session));
		}

		$variants = [];
		foreach ($counts as $row) {
			$row['rate'] = $this->rate(numerator: $row['conversions'], denominator: $row['sessions']);
			$variants[] = $row;
		}

		return $variants;
	}

	/**
	 * The winner and the confidence for a set of variant rows.
	 *
	 * The two best rates are compared. A winner is named only when every
	 * variant has MIN_SESSIONS sessions and the two-proportion test puts
	 * the difference at WINNING_CONFIDENCE or above; otherwise the winner
	 * is '' and the confidence still says how far apart the two are.
	 *
	 * @param array<int, array<string, mixed>> $variants Rows with `id`, `sessions`, `conversions`.
	 *
	 * @return array{winner: string, confidence: float} The verdict.
	 *
	 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-winner-must-only-be-named-with-enough-sessions-and-a-significant-difference
	 */
	public function verdict(array $variants): array {
		if (count($variants) < 2) {
			return ['winner' => '', 'confidence' => 0.0];
		}

		$ranked = array_values($variants);
		usort($ranked, static fn (array $a, array $b): int => [
			(float)($b['conversions'] ?? 0) / max(1, (int)($b['sessions'] ?? 0)),
			(int)($b['sessions'] ?? 0),
		] <=> [
			(float)($a['conversions'] ?? 0) / max(1, (int)($a['sessions'] ?? 0)),
			(int)($a['sessions'] ?? 0),
		]);

		$confidence = $this->zTest(
			conversionsA: (int)($ranked[0]['conversions'] ?? 0),
			sessionsA: (int)($ranked[0]['sessions'] ?? 0),
			conversionsB: (int)($ranked[1]['conversions'] ?? 0),
			sessionsB: (int)($ranked[1]['sessions'] ?? 0)
		);

		$enough = true;
		foreach ($variants as $variant) {
			$enough = $enough && (int)($variant['sessions'] ?? 0) >= self::MIN_SESSIONS;
		}

		$winner = '';
		if ($enough === true && $confidence >= self::WINNING_CONFIDENCE) {
			$winner = (string)($ranked[0]['id'] ?? '');
		}

		return ['winner' => $winner, 'confidence' => $confidence];
	}

	/**
	 * The two-proportion z-test as a two-sided confidence: how sure one can
	 * be that the two rates differ, between 0 and 1, three decimals.
	 *
	 * Pooled proportion, standard error from it, z, then the two-sided
	 * p-value from the normal distribution; the confidence is 1 - p. With
	 * no sessions on either side, or identical rates, it is 0.
	 *
	 * @param int $conversionsA Conversions of the first variant.
	 * @param int $sessionsA    Sessions of the first variant.
	 * @param int $conversionsB Conversions of the second variant.
	 * @param int $sessionsB    Sessions of the second variant.
	 *
	 * @return float The confidence.
	 *
	 * @spec openspec/changes/portal-traffic-experiments/specs/portal-traffic-experiments/spec.md#requirement-a-winner-must-only-be-named-with-enough-sessions-and-a-significant-difference
	 */
	public function zTest(int $conversionsA, int $sessionsA, int $conversionsB, int $sessionsB): float {
		if ($sessionsA <= 0 || $sessionsB <= 0) {
			return 0.0;
		}

		$pooled = ($conversionsA + $conversionsB) / ($sessionsA + $sessionsB);
		$error = sqrt($pooled * (1 - $pooled) * ((1 / $sessionsA) + (1 / $sessionsB)));
		if ($error <= 0.0) {
			return 0.0;
		}

		$score = abs(($conversionsA / $sessionsA) - ($conversionsB / $sessionsB)) / $error;
		$pValue = 2 * (1 - $this->normalCdf(score: $score));

		return round(max(0.0, min(1.0, 1 - $pValue)), 3);
	}

	/**
	 * The variant a session was on: the tag of its first event that names
	 * this experiment, or '' when it never took part. For a stopped
	 * experiment an event after the stop moment does not count.
	 *
	 * @param array<string, mixed> $experiment The experiment.
	 * @param array<string, mixed> $session    The session.
	 *
	 * @return string The variant id, or ''.
	 */
	private function variantOf(array $experiment, array $session): string {
		foreach (($session['events'] ?? []) as $event) {
			$params = $event['params'] ?? [];
			if (is_array($params) === false || (string)($params['experiment'] ?? '') !== (string)$experiment['id']) {
				continue;
			}

			$stoppedAt = (string)$experiment['stoppedAt'];
			if ($experiment['status'] === 'stopped' && $stoppedAt !== '' && (string)($event['occurredAt'] ?? '') > $stoppedAt) {
				return '';
			}

			return (string)($params['variant'] ?? '');
		}

		return '';
	}

	/**
	 * Whether a session met a goal at least once.
	 *
	 * @param array<string, mixed> $goal    The resolved goal.
	 * @param array<string, mixed> $session The session.
	 *
	 * @return bool True when any event matches.
	 */
	private function converted(array $goal, array $session): bool {
		foreach (($session['events'] ?? []) as $event) {
			if ($this->match->matches(match: (array)$goal['match'], event: $event, type: (string)$goal['type']) === true) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A rate, three decimals; 0 with nothing to divide by.
	 *
	 * @param int $numerator   The conversions.
	 * @param int $denominator The sessions.
	 *
	 * @return float The rate.
	 */
	private function rate(int $numerator, int $denominator): float {
		if ($denominator <= 0) {
			return 0.0;
		}

		return round($numerator / $denominator, 3);
	}

	/**
	 * The standard normal cumulative distribution at z, through the
	 * Abramowitz and Stegun approximation of the error function (7.1.26,
	 * absolute error under 1.5e-7), which is more precision than any
	 * conversion count deserves.
	 *
	 * @param float $score The standard score.
	 *
	 * @return float The probability mass below the score.
	 */
	private function normalCdf(float $score): float {
		$arg = $score / M_SQRT2;
		$sign = 1.0;
		if ($arg < 0) {
			$sign = -1.0;
			$arg = -$arg;
		}

		$step = 1.0 / (1.0 + 0.3275911 * $arg);
		$poly = ((((1.061405429 * $step - 1.453152027) * $step) + 1.421413741) * $step - 0.284496736) * $step + 0.254829592;
		$erf = 1.0 - $poly * $step * exp(-$arg * $arg);

		return 0.5 * (1.0 + $sign * $erf);
	}
}
