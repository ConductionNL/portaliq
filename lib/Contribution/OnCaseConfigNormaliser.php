<?php

/**
 * Portaliq On-Case Config Normaliser
 *
 * Sanitises the `onCase` key a case app puts on a create action that should
 * sit on the page of the case it is about: a bezwaar against a decision, a
 * klacht about how a case went. The key only adds a placement; the guard on
 * the reference stays `crossRefs`, which is why `onCase` must name one of the
 * action's own declared cross references.
 *
 * Fail closed means dropping the key and keeping the action. Unlike
 * `crossRefs`, nothing is guarded by `onCase` itself, so a malformed one only
 * leaves the action where it was.
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
 * @spec openspec/changes/case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-a-create-action-may-declare-a-place-on-the-case-page-req-coc-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Validates the per-action `onCase` placement, fail closed.
 *
 * @spec openspec/changes/case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-a-create-action-may-declare-a-place-on-the-case-page-req-coc-001
 */
class OnCaseConfigNormaliser {
	/**
	 * The manifest key carrying the placement.
	 */
	public const KEY = 'onCase';

	/**
	 * A field name on the case: the same plain identifier rule every other
	 * field reference in the contract follows.
	 */
	private const FIELD_NAME = '/^[a-zA-Z][a-zA-Z0-9_]*$/';

	/**
	 * Keep a well formed `onCase` on a create action, drop it otherwise.
	 *
	 * Called by the cross-reference normaliser, so `crossRefs` is already
	 * the sanitised guard the placement has to point at.
	 *
	 * @param array<string, mixed> $action The action, its `crossRefs` already normalised.
	 *
	 * @return array<string, mixed> The action, with or without `onCase`.
	 *
	 * @spec openspec/changes/case-page-objection-and-complaint/tasks.md#t01
	 */
	public function normaliseAction(array $action): array {
		if (array_key_exists(self::KEY, $action) === false) {
			return $action;
		}

		$placement = $this->placement(action: $action);
		unset($action[self::KEY]);
		if ($placement !== null) {
			$action[self::KEY] = $placement;
		}

		return $action;
	}//end normaliseAction()

	/**
	 * The sanitised placement, or null when any part of it is malformed.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/case-page-objection-and-complaint/tasks.md#t01
	 */
	private function placement(array $action): ?array {
		$declared = $action[self::KEY];
		if (($action['type'] ?? '') !== 'create' || is_array($declared) === false) {
			return null;
		}

		// The placement rides on the guard: a crossRef the action does not
		// declare would put a button on the case page with nothing checking
		// that the case is the resident's own.
		$crossRef  = ($declared['crossRef'] ?? null);
		$crossRefs = ($action[CrossRefConfigNormaliser::KEY] ?? null);
		if (is_string($crossRef) === false || is_array($crossRefs) === false
			|| is_array(($crossRefs[$crossRef] ?? null)) === false
		) {
			return null;
		}

		return $this->optionalKeys(declared: $declared, placement: ['crossRef' => $crossRef, 'order' => 0]);
	}//end placement()

	/**
	 * Add `order`, `window` and `closedText` when declared, or null when one is malformed.
	 *
	 * @param array<string, mixed> $declared  The declared placement.
	 * @param array<string, mixed> $placement The placement built so far.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/case-page-objection-and-complaint/tasks.md#t01
	 */
	private function optionalKeys(array $declared, array $placement): ?array {
		$checks = [
			'order' => static fn (mixed $value): bool => is_int($value),
			'window' => fn (mixed $value): bool => $this->window(window: $value) !== null,
			'closedText' => static fn (mixed $value): bool => is_string($value),
		];
		foreach ($checks as $key => $check) {
			if (array_key_exists($key, $declared) === false) {
				continue;
			}

			if ($check($declared[$key]) === false) {
				return null;
			}

			$placement[$key] = $declared[$key];
			if ($key === 'window') {
				$placement[$key] = $this->window(window: $declared[$key]);
			}
		}

		return $placement;
	}//end optionalKeys()

	/**
	 * A window `{from, days}`: a case field name and a positive whole number.
	 *
	 * @param mixed $window The declared window.
	 *
	 * @return array{from: string, days: int}|null
	 *
	 * @spec openspec/changes/case-page-objection-and-complaint/tasks.md#t01
	 */
	private function window(mixed $window): ?array {
		if (is_array($window) === false) {
			return null;
		}

		$from = ($window['from'] ?? null);
		$days = ($window['days'] ?? null);
		if (is_string($from) === false || preg_match(self::FIELD_NAME, $from) !== 1
			|| is_int($days) === false || $days < 1
		) {
			return null;
		}

		return ['from' => $from, 'days' => $days];
	}//end window()
}//end class
