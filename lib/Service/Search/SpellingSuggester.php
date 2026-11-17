<?php

/**
 * Portaliq spelling suggester
 *
 * The did-you-mean correction for a search that found little: each word of
 * the term that the portal's word list does not hold is replaced by the
 * nearest list word, and the corrected search is only offered when it finds
 * something.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
 * Picks a checked correction for a search term.
 */
class SpellingSuggester {

	/**
	 * Words up to this many letters allow one edit; longer words allow two.
	 */
	private const SHORT_WORD = 5;

	/**
	 * Constructor.
	 *
	 * @param SuggestionWordList         $words  The portal's word list.
	 * @param AnonymousPublicationSearch $search The anonymous public search.
	 */
	public function __construct(
		private readonly SuggestionWordList $words,
		private readonly AnonymousPublicationSearch $search,
	) {
	}//end __construct()

	/**
	 * The checked suggestion for a term, or null with no results.
	 *
	 * @param string $portal The portal slug.
	 * @param string $term   What the visitor searched for.
	 *
	 * @return array{suggestion: string|null, results: int} The suggestion and what it finds.
	 *
	 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
	 */
	public function suggest(string $portal, string $term): array {
		$corrected = $this->correct(term: $term, list: $this->words->load(portal: $portal));
		if ($corrected === null) {
			return ['suggestion' => null, 'results' => 0];
		}

		// A correction is offered only when it finds something: a link to
		// another empty result page is worse than no link at all.
		$results = $this->search->search(term: $corrected, page: 1, limit: 1)['total'];
		if ($results < 1) {
			return ['suggestion' => null, 'results' => 0];
		}

		return ['suggestion' => $corrected, 'results' => $results];
	}//end suggest()

	/**
	 * The corrected term, or null when no word changed.
	 *
	 * @param string             $term The term.
	 * @param array<string, int> $list The word list.
	 *
	 * @return string|null The corrected term.
	 *
	 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
	 */
	public function correct(string $term, array $list): ?string {
		$tokens = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY);
		if ($tokens === false || $tokens === [] || $list === []) {
			return null;
		}

		$changed = false;
		$out     = [];
		foreach ($tokens as $token) {
			$word = mb_strtolower($token);
			$best = null;
			if (SuggestionWordList::wordsOf(text: $word) === [$word] && isset($list[$word]) === false) {
				$best = $this->closest(word: $word, list: $list);
			}

			if ($best === null) {
				$out[] = $token;
				continue;
			}

			$out[]   = $best;
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		return implode(' ', $out);
	}//end correct()

	/**
	 * The list word nearest to a word within its allowed distance.
	 *
	 * On a tie in distance the more frequent word wins, then the one first in
	 * alphabetical order, so the same term always gets the same answer.
	 *
	 * @param string             $word The word.
	 * @param array<string, int> $list The word list.
	 *
	 * @return string|null The nearest word, or null.
	 */
	private function closest(string $word, array $list): ?string {
		$allowed = 2;
		if (mb_strlen($word) <= self::SHORT_WORD) {
			$allowed = 1;
		}

		$best         = null;
		$bestDistance = PHP_INT_MAX;
		$bestCount    = -1;
		foreach ($list as $candidate => $count) {
			$candidate = (string)$candidate;
			if (abs(mb_strlen($candidate) - mb_strlen($word)) > $allowed) {
				continue;
			}

			$distance = self::distance(left: $word, right: $candidate);
			if ($distance > $allowed) {
				continue;
			}

			$better = ($distance < $bestDistance)
				|| ($distance === $bestDistance && $count > $bestCount)
				|| ($distance === $bestDistance && $count === $bestCount && strcmp($candidate, (string)$best) < 0);
			if ($better === true) {
				$best         = $candidate;
				$bestDistance = $distance;
				$bestCount    = $count;
			}
		}

		return $best;
	}//end closest()

	/**
	 * The edit distance between two words, counted in letters, not bytes.
	 *
	 * PHP's own levenshtein() counts bytes, so "e" against "é" would cost
	 * two edits instead of one.
	 *
	 * @param string $left  One word.
	 * @param string $right The other.
	 *
	 * @return int The number of insertions, deletions and substitutions.
	 *
	 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
	 */
	public static function distance(string $left, string $right): int {
		$a = mb_str_split($left);
		$b = mb_str_split($right);
		$previous = range(0, count($b));
		foreach ($a as $i => $letter) {
			$current = [($i + 1)];
			foreach ($b as $j => $other) {
				$cost = 1;
				if ($letter === $other) {
					$cost = 0;
				}

				$current[] = min(($previous[($j + 1)] + 1), ($current[$j] + 1), ($previous[$j] + $cost));
			}

			$previous = $current;
		}

		return $previous[count($b)];
	}//end distance()
}//end class
