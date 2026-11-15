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
 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-declare-a-timed-task-driven-by-five-endpoint-actions
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Resolves each timed-task block against the contribution's endpoint actions.
 *
 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-declare-a-timed-task-driven-by-five-endpoint-actions
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
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-collection-must-be-able-to-declare-a-timed-task-driven-by-five-endpoint-actions
	 */
	public function resolve(array $collections, array $actions): array {
		$endpointIds = $this->endpointActionIds(actions: $actions);
		foreach ($collections as $index => $collection) {
			$collections[$index] = $this->resolved(collection: $collection, endpointIds: $endpointIds);
		}

		return $collections;
	}//end resolve()

	/**
	 * One collection: a timed task keeps its sound block or loses it with its
	 * kind; any other collection loses a stray block.
	 *
	 * @param array<string, mixed> $collection  The collection.
	 * @param array<int, string>   $endpointIds The endpoint action ids in reach.
	 *
	 * @return array<string, mixed>
	 */
	private function resolved(array $collection, array $endpointIds): array {
		if (($collection['kind'] ?? null) !== self::KIND) {
			unset($collection['timedTask']);
			return $collection;
		}

		$block = $this->soundBlock(declared: ($collection['timedTask'] ?? null), endpointIds: $endpointIds);
		if ($block === null) {
			unset($collection['timedTask'], $collection['kind']);
			return $collection;
		}

		$collection['timedTask'] = $block;
		return $collection;
	}//end resolved()

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
			$block[$step] = ($declared[$step] ?? null);
		}

		if ($this->allEndpoints(block: $block, endpointIds: $endpointIds) === false) {
			return null;
		}

		return $block;
	}//end soundBlock()

	/**
	 * Whether every step names an endpoint action in reach.
	 *
	 * @param array<string, mixed> $block       The steps and their declared action ids.
	 * @param array<int, string>   $endpointIds The endpoint action ids in reach.
	 *
	 * @return bool
	 */
	private function allEndpoints(array $block, array $endpointIds): bool {
		foreach ($block as $actionId) {
			if (is_string($actionId) === false || in_array($actionId, $endpointIds, true) === false) {
				return false;
			}
		}

		return true;
	}//end allEndpoints()

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
			if ($this->isEndpointAction(action: $action) === true) {
				$ids[] = $action['id'];
			}
		}

		return $ids;
	}//end endpointActionIds()

	/**
	 * Whether an action has an id and forwards to an instance-local endpoint.
	 *
	 * @param array<string, mixed> $action The sanitised action.
	 *
	 * @return bool
	 */
	private function isEndpointAction(array $action): bool {
		$id = ($action['id'] ?? null);

		return is_string($id) === true && $id !== '' && $this->isLocalPath(endpoint: ($action['endpoint'] ?? null)) === true;
	}//end isEndpointAction()

	/**
	 * Whether an endpoint is a path on this instance: one leading slash, no scheme.
	 *
	 * @param mixed $endpoint The declared endpoint.
	 *
	 * @return bool
	 */
	private function isLocalPath(mixed $endpoint): bool {
		return is_string($endpoint) === true && str_starts_with($endpoint, '/') === true
			&& str_starts_with($endpoint, '//') === false && str_contains($endpoint, '://') === false;
	}//end isLocalPath()
}//end class
