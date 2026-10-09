<?php

/**
 * Portaliq Display Keys (site-school-blocks)
 *
 * The shapes a `collection` block may draw its rows in besides the table:
 * `rows` (a date tile, a title, a sub line, a quote and a status pill),
 * `bars` (one bar per row against a maximum), `chips` (marks as chips with
 * an average), and the extra parts of a `cards` card (a sub line, a status
 * pill with a note, a "coming up" part, an initial). Every field a display
 * names MUST be one the collection projects; one that is not is dropped and
 * the display draws without it. A display the block does not declare adds
 * nothing.
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
 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-may-draw-its-rows-as-dated-rows-bars-chips-or-richer-cards
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Normalises the display keys of a collection block.
 *
 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-may-draw-its-rows-as-dated-rows-bars-chips-or-richer-cards
 */
class DisplayKeys {
	/**
	 * The single-field keys each display keeps.
	 */
	private const FIELDS = [
		'cards' => ['statusField', 'noteField', 'soonField'],
		'rows'  => [
			'dateField',
			'subtitleField',
			'quoteField',
			'statusField',
			'statusNoteField',
			// Mijn-lists-follow-the-boards: the big figure, the "Nieuw" pill, the small line above the title.
			'valueField',
			'newField',
			'eyebrowField',
		],
		'bars'  => ['labelField', 'valueField', 'noteField', 'captionField'],
		'chips' => [
			'labelField',
			'valuesField',
			'averageField',
			// Mijn-lists-follow-the-boards: one row per mark, grouped per subject.
			'groupField',
			'valueField',
			'dateField',
			'weightField',
			'subtitleField',
			'newField',
		],
	];

	/**
	 * The text keys each display keeps, at most 160 characters.
	 */
	private const TEXTS = [
		'cards' => ['soonLabel'],
		'rows'  => ['dateLabel'],
		'bars'  => ['noteLabel'],
		'chips' => ['summaryText'],
	];

	/**
	 * The choices a display key may take, the first one the default
	 * (mijn-lists-follow-the-boards).
	 */
	private const CHOICES = [
		'rows'  => [
			'dateDisplay' => ['tile', 'line', 'eyebrow', 'end'],
			'rowStyle'    => ['cards', 'lines'],
		],
		'chips' => ['summary' => [true]],
	];

	/**
	 * The tones a status pill may take.
	 */
	private const TONES = ['neutral', 'success', 'warning', 'error'];

	/**
	 * The most status values one block may give a tone.
	 */
	private const MAX_TONES = 20;

	/**
	 * The longest text a display key may carry.
	 */
	private const MAX_TEXT = 160;

	/**
	 * The display keys a collection block keeps.
	 *
	 * @param array<string, mixed>      $block      The declared block.
	 * @param array<string, mixed>|null $collection The collection it reads, or null when unknown.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-may-draw-its-rows-as-dated-rows-bars-chips-or-richer-cards
	 */
	public function keys(array $block, ?array $collection): array {
		$display = ($block['display'] ?? null);
		if (is_string($display) === false || isset(self::FIELDS[$display]) === false) {
			return [];
		}

		$out = ['display' => $display];
		foreach (self::FIELDS[$display] as $key) {
			if ($this->projects(collection: $collection, field: ($block[$key] ?? null)) === true) {
				$out[$key] = $block[$key];
			}
		}

		foreach (self::TEXTS[$display] as $key) {
			$text = ($block[$key] ?? null);
			if (is_string($text) === true && trim($text) !== '' && mb_strlen($text) <= self::MAX_TEXT) {
				$out[$key] = trim($text);
			}
		}

		return $out + $this->lists(display: $display, block: $block, collection: $collection)
			+ $this->numbers(display: $display, block: $block) + $this->tones(display: $display, block: $block)
			+ $this->choices(display: $display, block: $block);
	}//end keys()

	/**
	 * The keys of a display that take one of a few values; any other value is
	 * dropped (mijn-lists-follow-the-boards).
	 *
	 * @param string               $display The display.
	 * @param array<string, mixed> $block   The declared block.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/mijn-lists-follow-the-boards/specs/portal-contribution-contract/spec.md#requirement-rows-may-read-as-the-boards-lists
	 */
	private function choices(string $display, array $block): array {
		$out = [];
		foreach ((self::CHOICES[$display] ?? []) as $key => $values) {
			if (in_array(($block[$key] ?? null), $values, true) === true) {
				$out[$key] = $block[$key];
			}
		}

		return $out;
	}//end choices()

	/**
	 * The tone of each status value on rows and cards: `statusTones`, a map
	 * from a stored value to `neutral`, `success`, `warning` or `error`. The
	 * pill always says the status in words; the tone only adds weight.
	 *
	 * @param string               $display The display.
	 * @param array<string, mixed> $block   The declared block.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function tones(string $display, array $block): array {
		if (in_array($display, ['rows', 'cards'], true) === false || is_array($block['statusTones'] ?? null) === false) {
			return [];
		}

		$tones = [];
		foreach ($block['statusTones'] as $value => $tone) {
			if (is_string($value) === true && $value !== '' && in_array($tone, self::TONES, true) === true) {
				$tones[$value] = $tone;
			}
		}

		if ($tones === []) {
			return [];
		}

		return ['statusTones' => array_slice($tones, 0, self::MAX_TONES, true)];
	}//end tones()

	/**
	 * The field lists a display keeps: `titleFields` on rows, `subtitleFields`
	 * on cards, and `avatar` on cards.
	 *
	 * @param string                    $display    The display.
	 * @param array<string, mixed>      $block      The declared block.
	 * @param array<string, mixed>|null $collection The collection.
	 *
	 * @return array<string, mixed>
	 */
	private function lists(string $display, array $block, ?array $collection): array {
		$out = [];
		$list = ['rows' => 'titleFields', 'cards' => 'subtitleFields'];
		if (isset($list[$display]) === true) {
			$fields = array_values(
				array_filter(
					(array)($block[$list[$display]] ?? []),
					fn ($field): bool => $this->projects(collection: $collection, field: $field)
				)
			);
			if ($fields !== []) {
				$out[$list[$display]] = $fields;
			}
		}

		if ($display === 'cards' && ($block['avatar'] ?? null) === true) {
			$out['avatar'] = true;
		}

		return $out;
	}//end lists()

	/**
	 * The numbers a display keeps: the bars' `max`, the chips' `lowBelow`.
	 *
	 * @param string               $display The display.
	 * @param array<string, mixed> $block   The declared block.
	 *
	 * @return array<string, int|float>
	 */
	private function numbers(string $display, array $block): array {
		$keys = ['bars' => 'max', 'chips' => 'lowBelow'];
		if (isset($keys[$display]) === false) {
			return [];
		}

		$value = ($block[$keys[$display]] ?? null);
		if ((is_int($value) === true || is_float($value) === true) && $value > 0 && $value <= 1000) {
			return [$keys[$display] => $value];
		}

		return [];
	}//end numbers()

	/**
	 * Whether a field is one the collection projects. A collection without a
	 * field list projects everything, as elsewhere in the contract.
	 *
	 * @param array<string, mixed>|null $collection The collection.
	 * @param mixed                     $field      The field.
	 *
	 * @return bool
	 */
	private function projects(?array $collection, mixed $field): bool {
		if (is_string($field) === false || $field === '' || $collection === null) {
			return false;
		}

		$fields = ($collection['fields'] ?? null);
		return is_array($fields) === false || in_array($field, $fields, true) === true;
	}//end projects()
}//end class
