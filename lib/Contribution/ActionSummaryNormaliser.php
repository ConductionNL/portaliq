<?php

/**
 * Portaliq Action Summary Normaliser (action-summary-sentence)
 *
 * A create or endpoint action with fields MAY declare `answerSummary`: one
 * sentence the form shows above its send button and again on its
 * confirmation, built from the resident's own answers ("Sami is vandaag de
 * hele dag ziek."). Until decision 127 (9 Oct 2026) this object lived under
 * `summary`; that key is now the start tile's plain sentence
 * (StartTileNormaliser), and an object still declared there is read as
 * `answerSummary` by FormStepsNormaliser.
 *
 *     "answerSummary": {
 *       "label": "U meldt",
 *       "template": "{learner} is {when} {reason}.",
 *       "phrases": { "when": { "today": "vandaag de hele dag" } }
 *     }
 *
 * The template MAY name only the action's own fields: a placeholder for any
 * other name drops the whole summary, because a sentence with a hole in it
 * says something the resident did not answer. `phrases` maps a stored answer
 * to the words the sentence uses, per field of the action. Everything is
 * text; nothing here is markup.
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
 * @spec openspec/changes/action-summary-sentence/specs/portal-contribution-contract/spec.md#requirement-an-action-may-sum-up-the-answers-in-one-sentence
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Keeps a well-formed `summary` on an action.
 *
 * @spec openspec/changes/action-summary-sentence/specs/portal-contribution-contract/spec.md#requirement-an-action-may-sum-up-the-answers-in-one-sentence
 */
class ActionSummaryNormaliser {
	/**
	 * The longest template.
	 */
	private const MAX_TEMPLATE = 300;

	/**
	 * The longest label or phrase.
	 */
	private const MAX_TEXT = 120;

	/**
	 * The clean summary, or null to drop it.
	 *
	 * @param mixed              $summary   The declared summary.
	 * @param array<int, string> $whitelist The action's fields.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/action-summary-sentence/specs/portal-contribution-contract/spec.md#requirement-an-action-may-sum-up-the-answers-in-one-sentence
	 */
	public function summary(mixed $summary, array $whitelist): ?array {
		if (is_array($summary) === false) {
			return null;
		}

		$template = ($summary['template'] ?? null);
		if (is_string($template) === false || trim($template) === '' || mb_strlen($template) > self::MAX_TEMPLATE) {
			return null;
		}

		preg_match_all('/\{([A-Za-z][A-Za-z0-9_]*)\}/', $template, $matches);
		$named = array_unique($matches[1]);
		if ($named === [] || array_diff($named, $whitelist) !== []) {
			return null;
		}

		$out = ['template' => trim($template)];
		$label = $this->text(value: ($summary['label'] ?? null));
		if ($label !== '') {
			$out['label'] = $label;
		}

		$phrases = $this->phrases(declared: ($summary['phrases'] ?? null), fields: $named);
		if ($phrases !== []) {
			$out['phrases'] = $phrases;
		}

		return $out;
	}//end summary()

	/**
	 * The phrases for the fields the template names: `{field: {answer: words}}`.
	 *
	 * @param mixed              $declared The declared phrases.
	 * @param array<int, string> $fields   The fields the template names.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function phrases(mixed $declared, array $fields): array {
		if (is_array($declared) === false) {
			return [];
		}

		$out = [];
		foreach ($fields as $field) {
			$map = ($declared[$field] ?? null);
			if (is_array($map) === false) {
				continue;
			}

			foreach ($map as $answer => $words) {
				$text = $this->text(value: $words);
				if ($text !== '') {
					$out[$field][(string)$answer] = $text;
				}
			}
		}

		return $out;
	}//end phrases()

	/**
	 * A trimmed text of at most MAX_TEXT characters, else ''.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function text(mixed $value): string {
		if (is_string($value) === false || mb_strlen(trim($value)) > self::MAX_TEXT) {
			return '';
		}

		return trim($value);
	}//end text()
}//end class
