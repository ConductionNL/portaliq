<?php

/**
 * Portaliq Row Action Inputs (case-actions-row-inputs-and-conditions)
 *
 * What an endpoint row action may ask beyond a plain confirmation:
 *
 *  - `rowInputs: {from, into}`: the row field `from` lists the inputs for
 *    that one row (`{name, label, required, type}`); the values travel in the
 *    body key `into`. The server accepts a value only for a name the row it
 *    read declares.
 *  - `availableWhen: {field, equals}` with `unavailableReasonField`: the
 *    action is offered only on rows where the field equals the value, and the
 *    server refuses a forward on any other row.
 *  - `confirmText` and `successText`: the contributing app's own words, kept
 *    unchanged.
 *
 * Everything here fails closed: a key that does not fit is dropped, and a row
 * input list that cannot be read offers no inputs at all.
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
 * @spec openspec/changes/case-actions-row-inputs-and-conditions/specs/portal-row-action-inputs/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Normalises and applies the input and availability keys of an endpoint row action.
 *
 * @spec openspec/changes/case-actions-row-inputs-and-conditions/specs/portal-row-action-inputs/spec.md
 */
class RowActionInputs {
	private const FIELD_NAME = '/^[a-zA-Z][a-zA-Z0-9_]*$/';

	private const TYPES = ['text', 'date'];

	private const MAX_INPUTS = 20;

	private const MAX_TEXT = 500;

	private const MAX_VALUE = 2000;

	/**
	 * Keep the well-formed input and availability keys of an endpoint row
	 * action; drop each key that does not fit. Any other kind of action loses
	 * all of them.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/case-actions-row-inputs-and-conditions/tasks.md#t01
	 */
	public function normaliseAction(array $action): array {
		$isEndpoint = ((new RowActionResolver())->isEndpointRowAction(action: $action) === true);
		$keys       = ['rowInputs', 'availableWhen', 'unavailableReasonField', 'confirmText', 'successText'];
		if ($isEndpoint === false) {
			return array_diff_key($action, array_flip($keys));
		}

		$whitelist = array_values(array_filter((array)($action['fields'] ?? []), 'is_string'));

		$action = $this->rowInputs(action: $action, whitelist: $whitelist);
		$action = $this->availabilityKeys(action: $action);
		foreach (['confirmText', 'successText'] as $textKey) {
			if (array_key_exists($textKey, $action) === true) {
				$text = $action[$textKey];
				if (is_string($text) === false || trim($text) === '' || mb_strlen($text) > self::MAX_TEXT) {
					unset($action[$textKey]);
				}
			}
		}

		return $action;
	}//end normaliseAction()

	/**
	 * Whether the action is available on this row. An action without
	 * `availableWhen` is available on every row.
	 *
	 * @param array<string, mixed> $action The normalised action.
	 * @param array<string, mixed> $row    The row as read under the resident's scope.
	 *
	 * @return array{available: bool, reason: string}
	 *
	 * @spec openspec/changes/case-actions-row-inputs-and-conditions/tasks.md#t02
	 */
	public function availability(array $action, array $row = []): array {
		$when = ($action['availableWhen'] ?? null);
		if (is_array($when) === false) {
			return ['available' => true, 'reason' => ''];
		}

		if (($row[$when['field']] ?? null) === $when['equals']) {
			return ['available' => true, 'reason' => ''];
		}

		$reason = '';
		$field  = ($action['unavailableReasonField'] ?? null);
		if (is_string($field) === true && is_string($row[$field] ?? null) === true) {
			$reason = mb_substr(trim($row[$field]), 0, self::MAX_TEXT);
		}

		return ['available' => false, 'reason' => $reason];
	}//end availability()

	/**
	 * The inputs the row declares: only well-formed descriptors, so a row that
	 * lies in shape asks for nothing.
	 *
	 * @param array<string, mixed> $action The normalised action.
	 * @param array<string, mixed> $row    The row.
	 *
	 * @return array<int, array{name: string, label: string, required: bool, type: string}>
	 *
	 * @spec openspec/changes/case-actions-row-inputs-and-conditions/tasks.md#t02
	 */
	public function declaredInputs(array $action, array $row): array {
		$config = ($action['rowInputs'] ?? null);
		if (is_array($config) === false) {
			return [];
		}

		$out  = [];
		$seen = [];
		foreach (array_slice(array_values((array)($row[$config['from']] ?? [])), 0, self::MAX_INPUTS) as $descriptor) {
			$name = null;
			if (is_array($descriptor) === true) {
				$name = ($descriptor['name'] ?? null);
			}

			if (is_string($name) === false || preg_match(self::FIELD_NAME, $name) !== 1 || isset($seen[$name]) === true) {
				continue;
			}

			$seen[$name] = true;
			$type        = ($descriptor['type'] ?? 'text');
			$label       = $name;
			if (is_string($descriptor['label'] ?? null) === true) {
				$label = mb_substr((string)$descriptor['label'], 0, 120);
			}

			if (in_array($type, self::TYPES, true) === false) {
				$type = 'text';
			}


			$out[]       = ['name' => $name, 'label' => $label, 'required' => (($descriptor['required'] ?? false) === true), 'type' => $type];
		}

		return $out;
	}//end declaredInputs()

	/**
	 * The body value for `into`: the submitted values for exactly the names
	 * the row declares; any other name is dropped. Names of required inputs
	 * left empty come back as errors.
	 *
	 * @param array<string, mixed> $action    The normalised action.
	 * @param array<string, mixed> $row       The row.
	 * @param mixed                $submitted What the browser sent under `into`.
	 *
	 * @return array{values: array<string, string>, errors: array<string, string>}
	 *
	 * @spec openspec/changes/case-actions-row-inputs-and-conditions/tasks.md#t02
	 */
	public function collect(array $action, array $row, mixed $submitted): array {
		$asked = [];
		if (is_array($submitted) === true) {
			$asked = $submitted;
		}

		$values = [];
		$errors = [];
		foreach ($this->declaredInputs(action: $action, row: $row) as $input) {
			$value = $asked[$input['name']] ?? null;
			$text  = '';
			if (is_scalar($value) === true) {
				$text = mb_substr(trim((string)$value), 0, self::MAX_VALUE);
			}

			if ($text === '') {
				if ($input['required'] === true) {
					$errors[$input['name']] = 'required';
				}

				continue;
			}

			$values[$input['name']] = $text;
		}

		return ['values' => $values, 'errors' => $errors];
	}//end collect()

	/**
	 * Keep `rowInputs` only as `{from, into}`: both plain names, `into` not
	 * one of the action's own fields.
	 *
	 * @param array<string, mixed> $action    The action.
	 * @param array<int, string>   $whitelist The action's `fields`.
	 *
	 * @return array<string, mixed>
	 */
	private function rowInputs(array $action, array $whitelist): array {
		if (array_key_exists('rowInputs', $action) === false) {
			return $action;
		}

		$config = $action['rowInputs'];
		if (is_array($config) === true
			&& $this->isName(value: ($config['from'] ?? null)) === true
			&& $this->isName(value: ($config['into'] ?? null)) === true
			&& in_array($config['into'], $whitelist, true) === false
			&& $config['into'] !== ($action['rowField'] ?? null)
		) {
			$action['rowInputs'] = ['from' => $config['from'], 'into' => $config['into']];
			return $action;
		}

		unset($action['rowInputs']);
		return $action;
	}//end rowInputs()

	/**
	 * Keep `availableWhen` as `{field, equals}` with a scalar value, and
	 * `unavailableReasonField` only beside it.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return array<string, mixed>
	 */
	private function availabilityKeys(array $action): array {
		if (array_key_exists('availableWhen', $action) === true) {
			$action = $this->keepAvailableWhen(action: $action);
		}

		if (array_key_exists('unavailableReasonField', $action) === true
			&& (array_key_exists('availableWhen', $action) === false || $this->isName(value: $action['unavailableReasonField']) === false)
		) {
			unset($action['unavailableReasonField']);
		}

		return $action;
	}//end availabilityKeys()

	/**
	 * Keep `availableWhen` as `{field, equals}` with a scalar value; drop it otherwise.
	 *
	 * @param array<string, mixed> $action The action, declaring `availableWhen`.
	 *
	 * @return array<string, mixed>
	 */
	private function keepAvailableWhen(array $action): array {
		$when = ($action['availableWhen'] ?? null);
		if (is_array($when) === true
			&& $this->isName(value: ($when['field'] ?? null)) === true
			&& array_key_exists('equals', $when) === true
			&& is_scalar($when['equals']) === true
		) {
			$action['availableWhen'] = ['field' => $when['field'], 'equals' => $when['equals']];
			return $action;
		}

		unset($action['availableWhen']);

		return $action;
	}//end keepAvailableWhen()

	/**
	 * @param mixed $value A candidate field name.
	 *
	 * @return bool
	 */
	private function isName(mixed $value): bool {
		return is_string($value) === true && preg_match(self::FIELD_NAME, $value) === 1;
	}//end isName()
}//end class
