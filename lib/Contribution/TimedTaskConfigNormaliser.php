<?php

/**
 * Portaliq Timed Task Configuration Normaliser (portal-take-assessment)
 *
 * A collection with `kind: timedTask` names five endpoint actions of its own
 * contribution in a `timedTask` block: `available`, `start`, `answer`,
 * `submit` and `result`. The portal drives a timed attempt through them; the
 * leaf app behind them keeps every rule. A block that names a missing,
 * trust-dropped or non-endpoint action is removed together with the kind, so
 * the collection falls back to an ordinary list instead of a screen whose
 * buttons lead nowhere.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/changes/portal-take-assessment/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-declare-a-timed-task-driven-by-five-endpoint-actions
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Resolves each timed-task block against the contribution's endpoint actions.
 *
 * @spec openspec/changes/portal-take-assessment/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-declare-a-timed-task-driven-by-five-endpoint-actions
 */
class TimedTaskConfigNormaliser {
	/**
	 * The collection kind this normaliser owns.
	 */
	public const KIND = 'timedTask';

	/**
	 * The steps a timed task names, each an endpoint action id.
	 */
	public const STEPS = ['available', 'start', 'answer', 'submit', 'result'];

	/**
	 * Keep each sound timed-task block; drop a broken one with its kind.
	 *
	 * @param array<int, array<string, mixed>> $collections The sanitised collections.
	 * @param array<int, array<string, mixed>> $actions The sanitised, trust-filtered actions.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-take-assessment/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-declare-a-timed-task-driven-by-five-endpoint-actions
	 */
	public function resolve(array $collections, array $actions): array {
		$endpointIds = $this->endpointActionIds(actions: $actions);
		foreach ($collections as $index => $collection) {
			if (($collection['kind'] ?? null) !== self::KIND && array_key_exists('timedTask', $collection) === false) {
				continue;
			}

			$block = $this->soundBlock(declared: ($collection['timedTask'] ?? null), endpointIds: $endpointIds);
			if (($collection['kind'] ?? null) !== self::KIND || $block === null) {
				unset($collection['timedTask']);
				if (($collection['kind'] ?? null) === self::KIND) {
					unset($collection['kind']);
				}

				$collections[$index] = $collection;
				continue;
			}

			$collection['timedTask'] = $block;
			$collections[$index] = $collection;
		}

		return $collections;
	}//end resolve()

	/**
	 * The block with exactly the five steps, each naming an endpoint action,
	 * or null.
	 *
	 * @param mixed $declared The declared block.
	 * @param array<int, string> $endpointIds The endpoint action ids in reach.
	 *
	 * @return array<string, string>|null
	 */
	private function soundBlock(mixed $declared, array $endpointIds): ?array {
		if (is_array($declared) === false) {
			return null;
		}

		$block = [];
		foreach (self::STEPS as $step) {
			$actionId = ($declared[$step] ?? null);
			if (is_string($actionId) === false || in_array($actionId, $endpointIds, true) === false) {
				return null;
			}

			$block[$step] = $actionId;
		}

		return $block;
	}//end soundBlock()

	/**
	 * The ids of the actions that forward to an instance-local endpoint.
	 *
	 * @param array<int, array<string, mixed>> $actions The sanitised actions.
	 *
	 * @return array<int, string>
	 */
	private function endpointActionIds(array $actions): array {
		$ids = [];
		foreach ($actions as $action) {
			$id = ($action['id'] ?? null);
			$endpoint = ($action['endpoint'] ?? null);
			if (is_string($id) === true && $id !== ''
				&& is_string($endpoint) === true && str_starts_with($endpoint, '/') === true
				&& str_starts_with($endpoint, '//') === false && str_contains($endpoint, '://') === false
			) {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end endpointActionIds()
}//end class
