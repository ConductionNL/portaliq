<?php

/**
 * Portaliq On-Case Config Normaliser
 *
 * Sanitises the `onCase` declaration a domain app puts on a create action so
 * the action can be offered from a case page: which of the action's own
 * cross-references names the case, where it sits in the order of the extra
 * actions, the window it stays open for, and the sentence shown once it has
 * closed. A malformed declaration drops the key and keeps the action — unlike
 * `crossRefs`, this block is not a guard, so dropping it closes no surface; it
 * only stops the action being offered on the case page.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://portaliq.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Normalise the `onCase` declaration on one action.
 *
 * @spec openspec/changes/case-page-objection-and-complaint/specs/portal-contribution-contract/spec.md
 */
class OnCaseConfigNormaliser {

	/**
	 * The manifest key carrying the declaration.
	 */
	public const KEY = 'onCase';

	/**
	 * Keep `onCase` when it is well formed, otherwise drop the key and keep the
	 * action. Runs after the cross-ref normaliser, so `crossRefs` is the
	 * sanitised map whose keys a `crossRef` must name.
	 *
	 * @param array<string, mixed> $action The action, with its `crossRefs` already normalised.
	 *
	 * @return array<string, mixed> The action, with a sound `onCase` kept or the key dropped.
	 *
	 * @spec openspec/changes/case-page-objection-and-complaint/specs/portal-contribution-contract/spec.md
	 */
	public function normaliseAction(array $action): array {
		if (array_key_exists(self::KEY, $action) === false) {
			return $action;
		}

		$config = $this->config(declared: $action[self::KEY], crossRefs: ($action['crossRefs'] ?? []));
		if ($config === null) {
			unset($action[self::KEY]);
			return $action;
		}

		$action[self::KEY] = $config;
		return $action;
	}//end normaliseAction()

	/**
	 * The sanitised declaration, or null when any part of it is unusable.
	 *
	 * @param mixed $declared  The declaration as authored.
	 * @param mixed $crossRefs The action's normalised cross-references.
	 *
	 * @return array<string, mixed>|null The sanitised declaration, or null.
	 */
	private function config(mixed $declared, mixed $crossRefs): ?array {
		if (is_array($declared) === false) {
			return null;
		}

		$crossRef = $this->crossRefOf(declared: $declared, crossRefs: $crossRefs);
		if ($crossRef === null) {
			return null;
		}

		$window = $this->orderedWindow(declared: $declared);
		if ($window === null) {
			return null;
		}

		$config = ['crossRef' => $crossRef, 'order' => $declared['order'], 'window' => $window];

		return $this->withClosedText(config: $config, declared: $declared);
	}//end config()

	/**
	 * The cross-reference the declaration names, when the action has it.
	 *
	 * @param array<string, mixed> $declared  The declaration as authored.
	 * @param mixed                $crossRefs The action's normalised cross-references.
	 *
	 * @return string|null
	 */
	private function crossRefOf(array $declared, mixed $crossRefs): string|null {
		$crossRef = ($declared['crossRef'] ?? null);
		if (is_string($crossRef) === false || is_array($crossRefs) === false || array_key_exists($crossRef, $crossRefs) === false) {
			return null;
		}

		return $crossRef;
	}//end crossRefOf()

	/**
	 * The declaration's window, when it also declares a whole-number order.
	 *
	 * @param array<string, mixed> $declared The declaration as authored.
	 *
	 * @return array<string, mixed>|null
	 */
	private function orderedWindow(array $declared): array|null {
		if (is_int($declared['order'] ?? null) === false) {
			return null;
		}

		return $this->window(declared: ($declared['window'] ?? null));
	}//end orderedWindow()

	/**
	 * The configuration with the declared closed text, or null when that text
	 * is not a string.
	 *
	 * @param array<string, mixed> $config   The configuration so far.
	 * @param array<string, mixed> $declared The declaration as authored.
	 *
	 * @return array<string, mixed>|null
	 */
	private function withClosedText(array $config, array $declared): array|null {
		$closedText = ($declared['closedText'] ?? null);
		if ($closedText !== null) {
			if (is_string($closedText) === false) {
				return null;
			}

			$config['closedText'] = $closedText;
		}

		return $config;
	}//end withClosedText()

	/**
	 * The sanitised window: a field name to count from and a positive number of
	 * days, or null when either is unusable.
	 *
	 * @param mixed $declared The `window` block as authored.
	 *
	 * @return array{from: string, days: int}|null The window, or null.
	 */
	private function window(mixed $declared): ?array {
		if (is_array($declared) === false) {
			return null;
		}

		$from = ($declared['from'] ?? null);
		if (is_string($from) === false || trim($from) === '') {
			return null;
		}

		$days = ($declared['days'] ?? null);
		if (is_int($days) === false || $days < 1) {
			return null;
		}

		return ['from' => $from, 'days' => $days];
	}//end window()
}//end class
