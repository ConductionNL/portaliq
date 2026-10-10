<?php

/**
 * Portaliq spelling suggester
 *
 * Corrects a search term that found little to a term that finds results.
 * a search correction may be picked from.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Search;

/**
 * Picks "Bedoelde u" for a term that found fewer than three results.
 *
 * Each word of the term that is not in the portal's word list is replaced by
 * the list word nearest to it: at most one edit for a word of up to five
 * letters, at most two for a longer word, the more frequent word on a tie. A
 * correction is offered only when the public search finds at least one
 * result for it, so a suggestion never leads to an empty page.
 *
 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
 */
class SpellingSuggester {

	/**
	 * A term longer than this is not corrected.
	 */
	public const MAX_TERM_LENGTH = 100;

	/**
	 * Constructor.
	 *
	 * @param SuggestionWordList      $list   The portal's words.
	 * @param PublicPublicationSearch $search Counts the corrected term's results.
	 */
	public function __construct(
		private readonly SuggestionWordList $list,
		private readonly PublicPublicationSearch $search,
	) {
	}//end __construct()

	/**
	 * The checked correction of a term, with its result count.
	 *
	 * @param string $portal The portal slug.
	 * @param string $term   What the visitor searched.
	 *
	 * @return array{suggestion: string|null, results: int}
	 *
	 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
	 */
	public function suggest(string $portal, string $term): array {
		$none = ['suggestion' => null, 'results' => 0];
		$term = trim($term);
		if ($term === '' || mb_strlen($term) > self::MAX_TERM_LENGTH) {
			return $none;
		}

		$correction = self::correct(term: $term, words: $this->list->words(portal: $portal));
		if ($correction === null) {
			return $none;
		}

		$results = $this->search->count(term: $correction);
		if ($results === null || $results < 1) {
			return $none;
		}

		return ['suggestion' => $correction, 'results' => $results];
	}//end suggest()

	/**
	 * The term with every unknown word corrected, or null when nothing changed.
	 *
	 * @param string             $term  The term.
	 * @param array<string, int> $words The word list: word => frequency.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
	 */
	public static function correct(string $term, array $words): ?string {
		if ($words === []) {
			return null;
		}

		$changed = false;
		$out     = [];
		foreach (preg_split('/\s+/u', mb_strtolower(trim($term)), -1, PREG_SPLIT_NO_EMPTY) as $word) {
			$better = null;
			if (isset($words[$word]) === false) {
				$better = self::nearest(word: $word, words: $words);
			}

			if ($better !== null) {
				$changed = true;
				$word    = $better;
			}

			$out[] = $word;
		}

		if ($changed === false) {
			return null;
		}

		return implode(' ', $out);
	}//end correct()

	/**
	 * The list word nearest to one word within its allowed edits, or null.
	 *
	 * @param string             $word  The word.
	 * @param array<string, int> $words The word list.
	 *
	 * @return string|null
	 */
	private static function nearest(string $word, array $words): ?string {
		// One edit for a word of up to five letters, two for a longer one.
		$allowed = 2;
		if (mb_strlen($word) <= 5) {
			$allowed = 1;
		}

		$best    = null;
		$bestKey = null;
		foreach ($words as $candidate => $count) {
			$candidate = (string)$candidate;
			if (abs(mb_strlen($candidate) - mb_strlen($word)) > $allowed) {
				continue;
			}

			$distance = levenshtein($word, $candidate);
			if ($distance === 0 || $distance > $allowed) {
				continue;
			}

			// Fewer edits first, then the more frequent word, then the alphabet.
			$key = [$distance, -$count, $candidate];
			if ($bestKey === null || $key < $bestKey) {
				$best    = $candidate;
				$bestKey = $key;
			}
		}

		return $best;
	}//end nearest()
}//end class
