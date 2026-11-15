<?php

/**
 * Portaliq Steps Highlight Keys (steps-highlight)
 *
 * A steps block may draw only the step that matters now as a highlight card:
 * the current step, else the next one to come, with its date, its words and a
 * button to a page of the same contribution ("Volgende stap: Tussenbeoordeling
 * op dinsdag 13 oktober" with "Zelfbeoordeling afmaken").
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
 * @spec openspec/changes/steps-highlight/specs/site-mijn-omgeving/spec.md#requirement-the-steps-may-draw-the-step-that-matters-now-as-a-highlight
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Sanitises the highlight keys of a `steps` block.
 *
 * @spec openspec/changes/steps-highlight/specs/site-mijn-omgeving/spec.md#requirement-the-steps-may-draw-the-step-that-matters-now-as-a-highlight
 */
class StepsHighlightKeys {
	/**
	 * The longest eyebrow or button text.
	 */
	private const MAX_TEXT = 80;

	/**
	 * The highlight keys, or [] when the block does not ask for the highlight.
	 *
	 * @param array<string, mixed> $block   The declared block.
	 * @param array<int, string>   $pageIds The pages of this contribution.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/steps-highlight/specs/site-mijn-omgeving/spec.md#requirement-the-steps-may-draw-the-step-that-matters-now-as-a-highlight
	 */
	public function keys(array $block, array $pageIds): array {
		if (($block['display'] ?? null) !== 'highlight') {
			return [];
		}

		$out = ['display' => 'highlight'] + $this->texts(block: $block);
		if (isset($out['buttonLabel']) === false) {
			return $out;
		}

		return $out + $this->buttonTarget(block: $block, pageIds: $pageIds);
	}//end keys()

	/**
	 * The eyebrow and button texts that are sound.
	 *
	 * @param array<string, mixed> $block The declared block.
	 *
	 * @return array<string, string>
	 */
	private function texts(array $block): array {
		$out = [];
		foreach (['eyebrow', 'buttonLabel'] as $key) {
			$text = $this->text(value: ($block[$key] ?? null));
			if ($text !== null) {
				$out[$key] = $text;
			}
		}

		return $out;
	}//end texts()

	/**
	 * The page the button opens, with the open record when the block says so;
	 * a page that is not there shows no button.
	 *
	 * @param array<string, mixed> $block   The declared block.
	 * @param array<int, string>   $pageIds The pages of this contribution.
	 *
	 * @return array<string, mixed>
	 */
	private function buttonTarget(array $block, array $pageIds): array {
		$page = ($block['page'] ?? null);
		if (is_string($page) === false || in_array($page, $pageIds, true) === false) {
			return [];
		}

		if (($block['withRecord'] ?? false) === true) {
			return ['page' => $page, 'withRecord' => true];
		}

		return ['page' => $page];
	}//end buttonTarget()

	/**
	 * A trimmed, short text, or null.
	 *
	 * @param mixed $value The declared text.
	 *
	 * @return string|null
	 */
	private function text(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '' || mb_strlen(trim($value)) > self::MAX_TEXT) {
			return null;
		}

		return trim($value);
	}//end text()
}//end class
