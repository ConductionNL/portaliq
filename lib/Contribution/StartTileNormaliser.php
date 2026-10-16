<?php

/**
 * Portaliq Start Tile Normaliser (site-nlds-widget-palette, design D6)
 *
 * A create or endpoint action MAY offer itself as a start tile ("Wat wilt u
 * regelen?") with two keys:
 *
 *     "summary": "Bent u het niet eens met een besluit? Maak binnen zes weken bezwaar.",
 *     "audiences": ["citizen"]
 *
 * `summary` is one plain sentence of 1 to 200 characters (decision 127 made
 * it a string; the answer sentence moved to `answerSummary`). `audiences` is
 * the subset of the provider's own audiences the tile is meant for; it is
 * the hint for the way in when a signed-out visitor picks the tile. Anything
 * else is dropped, and both keys are dropped on any other action.
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
 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-contribution-contract/spec.md#requirement-an-action-may-offer-itself-as-a-start-tile-with-a-summary-and-its-audiences-req-snw-020
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Keeps a start tile's `summary` and `audiences` on an action, sanitised.
 */
class StartTileNormaliser {
	/**
	 * The longest summary kept, in characters.
	 */
	public const MAX_SUMMARY = 200;

	/**
	 * Keep `summary` and `audiences` on a create or endpoint action.
	 *
	 * @param array<string, mixed> $action The action.
	 * @param array<int, string>|null $served The audiences the provider serves, or null when unknown.
	 *
	 * @return array<string, mixed> The action.
	 *
	 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-contribution-contract/spec.md#requirement-an-action-may-offer-itself-as-a-start-tile-with-a-summary-and-its-audiences-req-snw-020
	 */
	public function apply(array $action, ?array $served): array {
		$summary   = $this->summary(value: ($action['summary'] ?? null));
		$audiences = $this->audiences(value: ($action['audiences'] ?? null), served: $served);
		unset($action['summary'], $action['audiences']);

		$tiles = (($action['type'] ?? '') === 'create') || isset($action['endpoint']) === true;
		if ($tiles === false) {
			return $action;
		}

		if ($summary !== '') {
			$action['summary'] = $summary;
		}

		if ($audiences !== []) {
			$action['audiences'] = $audiences;
		}

		return $action;
	}//end apply()

	/**
	 * The summary sentence, or '' when it is not a string of 1 to 200 characters.
	 *
	 * @param mixed $value The declared summary.
	 *
	 * @return string
	 */
	private function summary(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		$text = trim($value);
		if ($text === '' || mb_strlen($text) > self::MAX_SUMMARY) {
			return '';
		}

		return $text;
	}//end summary()

	/**
	 * The declared audiences the provider serves, once each, in declared order.
	 *
	 * @param mixed $value The declared audiences.
	 * @param array<int, string>|null $served The audiences the provider serves.
	 *
	 * @return array<int, string>
	 */
	private function audiences(mixed $value, ?array $served): array {
		if (is_array($value) === false || $served === null) {
			return [];
		}

		$kept = [];
		foreach ($value as $audience) {
			if (is_string($audience) === true && in_array($audience, $served, true) === true) {
				$kept[$audience] = $audience;
			}
		}

		return array_values($kept);
	}//end audiences()
}//end class
