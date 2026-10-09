<?php

/**
 * Portaliq School Block Keys (site-school-blocks)
 *
 * The keys the Mijn omgeving blocks of the school portals add to existing
 * block types, and the one new block type, kept apart from the older
 * normalisers so each stays readable:
 *
 * - `tasks` with `display: highlight`: the "do this first" card, with an
 *   uppercase `eyebrow`, a sub line from `subtitleFields` and a `buttonLabel`;
 * - `kpi` with `display: segmented`: one bar of `segments` (`field`, `label`,
 *   `tone`) against `totalField` or a fixed `target`, with a `unit`;
 * - `calendar` with `display: tiles`: date tiles instead of the month list;
 * - `greeting`: the overview's opening, the date and "Good morning, name",
 *   with at most one call to action that opens a page, a route or an action.
 *
 * A key that does not fit is dropped; the block keeps its older shape.
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
 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-the-overview-blocks-may-take-the-school-displays
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Normalises the school displays on tasks, kpi and calendar blocks, and the greeting block.
 *
 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-the-overview-blocks-may-take-the-school-displays
 */
class SchoolBlockKeys {
	/**
	 * The longest eyebrow, button label or unit.
	 */
	private const MAX_TEXT = 60;

	/**
	 * The most segments one bar shows.
	 */
	private const MAX_SEGMENTS = 5;

	/**
	 * The tones a segment may have.
	 */
	private const TONES = ['positive', 'waiting', 'warning'];

	/**
	 * A tasks block's highlight keys.
	 *
	 * @param array<string, mixed> $block      The declared block.
	 * @param array<string, mixed> $collection The collection it reads.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-the-overview-blocks-may-take-the-school-displays
	 */
	public function tasksKeys(array $block, array $collection): array {
		if (($block['display'] ?? null) !== 'highlight') {
			return [];
		}

		$out = ['display' => 'highlight'] + $this->texts(block: $block, keys: ['eyebrow', 'buttonLabel']);
		$fields = array_values(
			array_filter(
				(array)($block['subtitleFields'] ?? []),
				fn ($field): bool => $this->projects(collection: $collection, field: $field)
			)
		);
		if ($fields !== []) {
			$out['subtitleFields'] = $fields;
		}

		// The card's tone and the due day in its line
		// (zuiddrecht-resident-pages-match-the-boards).
		return $out + (new BoardKeys())->highlightKeys(block: $block);
	}//end tasksKeys()

	/**
	 * A kpi block's segmented keys, or [] when it declares no usable segment.
	 *
	 * @param array<string, mixed> $block The declared block.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-the-overview-blocks-may-take-the-school-displays
	 */
	public function segmentedKeys(array $block): array {
		if (($block['display'] ?? null) !== 'segmented') {
			return [];
		}

		$segments = $this->segments(declared: ($block['segments'] ?? null));
		if ($segments === []) {
			return [];
		}

		$out = ['display' => 'segmented', 'segments' => array_slice($segments, 0, self::MAX_SEGMENTS)];
		if ($this->isName(value: ($block['totalField'] ?? null)) === true) {
			$out['totalField'] = $block['totalField'];
		}

		$target = ($block['target'] ?? null);
		if ((is_int($target) === true || is_float($target) === true) && $target > 0) {
			$out['target'] = $target;
		}

		return $out + $this->texts(block: $block, keys: ['unit']);
	}//end segmentedKeys()

	/**
	 * The well-formed segments, each `{field, label, tone}`.
	 *
	 * @param mixed $declared The declared segments.
	 *
	 * @return array<int, array{field: string, label: string, tone: string}>
	 */
	private function segments(mixed $declared): array {
		$segments = [];
		foreach ((array)$declared as $segment) {
			if (is_array($segment) === false || $this->isName(value: ($segment['field'] ?? null)) === false
				|| $this->isName(value: ($segment['label'] ?? null)) === false
			) {
				continue;
			}

			$tone = 'positive';
			if (in_array(($segment['tone'] ?? null), self::TONES, true) === true) {
				$tone = $segment['tone'];
			}

			$segments[] = ['field' => $segment['field'], 'label' => trim($segment['label']), 'tone' => $tone];
		}

		return $segments;
	}//end segments()

	/**
	 * A calendar block's display.
	 *
	 * @param array<string, mixed> $block The declared block.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-the-overview-blocks-may-take-the-school-displays
	 */
	public function calendarKeys(array $block): array {
		if (($block['display'] ?? null) === 'tiles') {
			return ['display' => 'tiles'];
		}

		return [];
	}//end calendarKeys()

	/**
	 * A greeting block. With a `label` and exactly one of `page`, `route` or
	 * `action` that resolves, it carries that call to action; a target that
	 * does not resolve is dropped and the greeting stays. `showDate` is on
	 * unless the block turns it off.
	 *
	 * @param array<string, mixed> $block     The declared block.
	 * @param array<int, string>   $actionIds The contribution's action ids.
	 * @param array<int, string>   $pageIds   The contribution's page ids.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-greeting-block-opens-the-overview
	 */
	public function greetingBlock(array $block, array $actionIds, array $pageIds): array {
		$out = ['type' => 'greeting', 'showDate' => (($block['showDate'] ?? true) !== false)];
		// The week number after the date (mijn-overview-follows-the-boards).
		if (($block['showWeek'] ?? null) === true) {
			$out['showWeek'] = true;
		}

		$cta = (new CtaBlockNormaliser())->normalise(block: $block, actionIds: $actionIds, pageIds: $pageIds);
		if ($cta === null) {
			return $out;
		}

		unset($cta['type'], $cta['withRecord']);

		return $out + $cta;
	}//end greetingBlock()

	/**
	 * The short texts a block keeps, each trimmed and at most MAX_TEXT long.
	 *
	 * @param array<string, mixed> $block The declared block.
	 * @param array<int, string>   $keys  The keys.
	 *
	 * @return array<string, string>
	 */
	private function texts(array $block, array $keys): array {
		$out = [];
		foreach ($keys as $key) {
			$text = ($block[$key] ?? null);
			if (is_string($text) === true && trim($text) !== '' && mb_strlen(trim($text)) <= self::MAX_TEXT) {
				$out[$key] = trim($text);
			}
		}

		return $out;
	}//end texts()

	/**
	 * Whether a value is a non-empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isName(mixed $value): bool {
		return is_string($value) === true && trim($value) !== '';
	}//end isName()

	/**
	 * Whether a field is one the collection projects.
	 *
	 * @param array<string, mixed> $collection The collection.
	 * @param mixed                $field      The field.
	 *
	 * @return bool
	 */
	private function projects(array $collection, mixed $field): bool {
		if (is_string($field) === false || $field === '') {
			return false;
		}

		$fields = ($collection['fields'] ?? null);
		return is_array($fields) === false || in_array($field, $fields, true) === true;
	}//end projects()
}//end class
