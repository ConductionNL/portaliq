<?php

/**
 * Portaliq Board Keys (zuiddrecht-resident-pages-match-the-boards)
 *
 * The keys a contribution declares to draw its resident pages the way the
 * Mijn Zuiddrecht boards draw them, kept apart from the older normalisers so
 * each stays readable. Every key is opt-in: a block or page that declares
 * none keeps the shape it had, so every other portal renders unchanged.
 *
 * - `cases`: `display: compact` (number left, tag right, title link, bar,
 *   one line under it), `showAll: true` (the link to every case beside the
 *   heading) and `yourTurn` (the turn values at which the tag reads the
 *   turn's words in the warning tone);
 * - `tasks` with `display: highlight`: a `tone` (`warning` or `info`) and
 *   `dueInLine: true` (the due day joins the sub line);
 * - `inbox`: `display: list` (a title and the day, no badge);
 * - `documents`: `upload: true` (an outlined button that adds a document);
 * - `detail`: a `label` over the facts and `timeline: false`;
 * - `citizenCase`: `display: actions` (the closed window sentence as a
 *   notice, no status block, no documents section);
 * - a page `record`: `heading: record` (the record's name is the h1) and
 *   `under: cases` (the breadcrumb passes through Mijn zaken).
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
 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-contribution-may-declare-the-board-displays
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * The board keys on blocks and pages, each kept only when well formed.
 *
 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-contribution-may-declare-the-board-displays
 */
class BoardKeys {

	/**
	 * The tones a highlight card may take besides its accent.
	 */
	private const HIGHLIGHT_TONES = ['warning', 'info'];

	/**
	 * How long a label over the facts may be.
	 */
	private const MAX_LABEL_LENGTH = 120;

	/**
	 * How many turn values a cases block may name.
	 */
	private const MAX_TURN_VALUES = 10;


	/**
	 * A cases block's board keys: `display: compact`, `showAll` and `yourTurn`.
	 *
	 * @param array<string, mixed> $block The declared block.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-contribution-may-declare-the-board-displays
	 */
	public function casesKeys(array $block): array {
		$out = [];
		if (($block['display'] ?? null) === 'compact') {
			$out['display'] = 'compact';
		}

		if (($block['showAll'] ?? null) === true) {
			$out['showAll'] = true;
		}

		$turns = $this->names(value: ($block['yourTurn'] ?? null), max: self::MAX_TURN_VALUES);
		if ($turns !== []) {
			$out['yourTurn'] = $turns;
		}

		return $out;
	}//end casesKeys()


	/**
	 * A tasks block's board key: `emptyNotice: true` (with nothing to do,
	 * the sentence is a notice in the ok tone, as the board draws it on the
	 * case page).
	 *
	 * @param array<string, mixed> $block The declared block.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-contribution-may-declare-the-board-displays
	 */
	public function tasksKeys(array $block): array {
		if (($block['emptyNotice'] ?? null) === true) {
			return ['emptyNotice' => true];
		}

		return [];
	}//end tasksKeys()


	/**
	 * A highlight tasks block's board keys: `tone` and `dueInLine`.
	 *
	 * @param array<string, mixed> $block The declared block.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-contribution-may-declare-the-board-displays
	 */
	public function highlightKeys(array $block): array {
		$out  = [];
		$tone = ($block['tone'] ?? null);
		if (is_string($tone) === true && in_array($tone, self::HIGHLIGHT_TONES, true) === true) {
			$out['tone'] = $tone;
		}

		if (($block['dueInLine'] ?? null) === true) {
			$out['dueInLine'] = true;
		}

		return $out;
	}//end highlightKeys()


	/**
	 * An inbox block's board key: `display: list`.
	 *
	 * @param array<string, mixed> $block The declared block.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-contribution-may-declare-the-board-displays
	 */
	public function inboxKeys(array $block): array {
		if (($block['display'] ?? null) === 'list') {
			return ['display' => 'list'];
		}

		return [];
	}//end inboxKeys()


	/**
	 * A documents block's board key: `upload: true`.
	 *
	 * @param array<string, mixed> $block The declared block.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-contribution-may-declare-the-board-displays
	 */
	public function documentsKeys(array $block): array {
		if (($block['upload'] ?? null) === true) {
			return ['upload' => true];
		}

		return [];
	}//end documentsKeys()


	/**
	 * A detail block's board keys: a `label` over the facts and `timeline: false`.
	 *
	 * @param array<string, mixed> $block The declared block.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-contribution-may-declare-the-board-displays
	 */
	public function detailKeys(array $block): array {
		$out   = [];
		$label = ($block['label'] ?? null);
		if (is_string($label) === true) {
			$label = trim($label);
			if ($label !== '' && mb_strlen($label) <= self::MAX_LABEL_LENGTH) {
				$out['label'] = $label;
			}
		}

		if (($block['timeline'] ?? null) === false) {
			$out['timeline'] = false;
		}

		return $out;
	}//end detailKeys()


	/**
	 * A citizenCase block's board key: `display: actions`.
	 *
	 * @param array<string, mixed> $block The declared block.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-contribution-may-declare-the-board-displays
	 */
	public function citizenCaseKeys(array $block): array {
		if (($block['display'] ?? null) === 'actions') {
			return ['display' => 'actions'];
		}

		return [];
	}//end citizenCaseKeys()


	/**
	 * A page record's board keys: `heading: record` and `under: cases`.
	 *
	 * @param array<string, mixed> $record The declared record.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-contribution-may-declare-the-board-displays
	 */
	public function recordKeys(array $record): array {
		$out = [];
		if (($record['heading'] ?? null) === 'record') {
			$out['heading'] = 'record';
		}

		if (($record['under'] ?? null) === 'cases') {
			$out['under'] = 'cases';
		}

		return $out;
	}//end recordKeys()


	/**
	 * The non-empty strings in a declared list, at most `max` of them.
	 *
	 * @param mixed $value The declared value.
	 * @param int   $max   How many to keep.
	 *
	 * @return array<int, string>
	 */
	private function names(mixed $value, int $max): array {
		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach ($value as $entry) {
			if (is_string($entry) === true && trim($entry) !== '') {
				$out[] = trim($entry);
			}
		}

		return array_slice(array_values(array_unique($out)), 0, $max);
	}//end names()
}//end class
