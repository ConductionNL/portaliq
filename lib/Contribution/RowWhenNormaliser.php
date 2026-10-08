<?php

/**
 * Portaliq Row Condition Normaliser (update-row-action-condition)
 *
 * A row action may say on which rows it applies:
 *
 *     "rowWhen": {"field": "lifecycle", "in": ["booked", "acknowledged"]}
 *
 * The endpoint row actions have had this since contribution-pay-screen; since
 * update-row-action-condition a `type: update` row action may declare it too.
 * This class checks the condition before a renderer reads it. A key other
 * than `field` and `in` is an operator portaliq does not know: it is dropped,
 * the rest stays. On an update action a condition without a field name or
 * without a non-empty list of scalars is dropped whole, so the action is
 * shown on every row as it was without one. On an endpoint row action a
 * malformed condition is left for RowActionResolver, which keeps that action
 * from resolving as a row action.
 *
 * INVARIANT: presentation only. For an update action the condition decides
 * which rows show the button, never whether the update is allowed; the leaf
 * app's lifecycle decides that.
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
 * @spec openspec/changes/update-row-action-condition/specs/portal-contribution-contract/spec.md#requirement-a-malformed-row-condition-must-be-dropped-with-a-warning-req-urc-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

use Psr\Log\LoggerInterface;

/**
 * Checks every action's `rowWhen` and reports what it dropped.
 *
 * @spec openspec/changes/update-row-action-condition/specs/portal-contribution-contract/spec.md#requirement-a-malformed-row-condition-must-be-dropped-with-a-warning-req-urc-002
 */
class RowWhenNormaliser {
	/**
	 * The keys a row condition may hold.
	 */
	private const KNOWN_KEYS = ['field', 'in'];

	/**
	 * A field name the portal may read.
	 */
	private const FIELD_NAME = '/^[a-zA-Z][a-zA-Z0-9_]*$/';

	/**
	 * Normalise a whole contribution's actions, logging each dropped part with
	 * the app that declared it. A contribution without actions is returned as
	 * it is.
	 *
	 * @param array<string, mixed> $contribution The normalised contribution.
	 * @param string               $appId        The app that contributed it.
	 * @param LoggerInterface      $logger       Where a dropped part is reported.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/update-row-action-condition/specs/portal-contribution-contract/spec.md#requirement-a-malformed-row-condition-must-be-dropped-with-a-warning-req-urc-002
	 */
	public function normaliseContribution(array $contribution, string $appId, LoggerInterface $logger): array {
		if (isset($contribution['actions']) === false || is_array($contribution['actions']) === false) {
			return $contribution;
		}

		$result = $this->normalise(actions: $contribution['actions']);
		foreach ($result['dropped'] as $reason) {
			$logger->warning('Portaliq: row condition dropped', ['app' => $appId, 'reason' => $reason]);
		}

		$contribution['actions'] = $result['actions'];

		return $contribution;
	}//end normaliseContribution()

	/**
	 * Normalise the `rowWhen` of every action.
	 *
	 * @param array<int|string, mixed> $actions The sanitised actions.
	 *
	 * @return array{actions: array<int|string, mixed>, dropped: array<int, string>}
	 *
	 * @spec openspec/changes/update-row-action-condition/specs/portal-contribution-contract/spec.md#requirement-a-malformed-row-condition-must-be-dropped-with-a-warning-req-urc-002
	 */
	public function normalise(array $actions): array {
		$dropped = [];
		foreach ($actions as $index => $action) {
			if (is_array($action) === false || array_key_exists('rowWhen', $action) === false) {
				continue;
			}

			$label = (string)($action['id'] ?? '?');
			$isUpdate = (($action['type'] ?? null) === 'update');
			$condition = $action['rowWhen'];

			if (is_array($condition) === true) {
				foreach (array_keys($condition) as $key) {
					if (in_array($key, self::KNOWN_KEYS, true) === false) {
						unset($condition[$key]);
						$dropped[] = sprintf('action "%s": unknown rowWhen operator "%s"', $label, (string)$key);
					}
				}

				$action['rowWhen'] = $condition;
			}

			if ($isUpdate === true && $this->isWellFormed(condition: $condition) === false) {
				unset($action['rowWhen']);
				$dropped[] = sprintf('action "%s": rowWhen needs a field name and a non-empty list of values', $label);
			}

			$actions[$index] = $action;
		}//end foreach

		return ['actions' => $actions, 'dropped' => $dropped];
	}//end normalise()

	/**
	 * Whether a condition is a field name and a non-empty list of scalars.
	 *
	 * @param mixed $condition The declared condition.
	 *
	 * @return bool
	 */
	private function isWellFormed(mixed $condition): bool {
		if (is_array($condition) === false) {
			return false;
		}

		$field = ($condition['field'] ?? null);
		if (is_string($field) === false || preg_match(self::FIELD_NAME, $field) !== 1) {
			return false;
		}

		$allowed = ($condition['in'] ?? null);
		if (is_array($allowed) === false || $allowed === [] || array_is_list($allowed) === false) {
			return false;
		}

		return count(array_filter($allowed, static fn ($value): bool => is_scalar($value) === false)) === 0;
	}//end isWellFormed()
}//end class
