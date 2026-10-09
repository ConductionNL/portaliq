<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Search;

use OCA\Portaliq\Service\Search\PublicPublicationSearch;
use OCA\Portaliq\Service\Search\SpellingSuggester;
use OCA\Portaliq\Service\Search\SuggestionWordList;
use PHPUnit\Framework\TestCase;

/**
 * "Bedoelde u": the correction rule and the check that it finds results
 * (search-sort-by-relevance REQ-SSR-005).
 *
 * @covers \OCA\Portaliq\Service\Search\SpellingSuggester
 *
 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
 */
class SpellingSuggesterTest extends TestCase {

	public function testASummaryWordGetsASuggestion(): void {
		$suggester = $this->suggester(words: ['hondenbelasting' => 3, 'parkeren' => 5], counts: ['hondenbelasting' => 4]);

		$this->assertSame(['suggestion' => 'hondenbelasting', 'results' => 4], $suggester->suggest(portal: 'gemeente', term: 'hondenbelasing'));
	}//end testASummaryWordGetsASuggestion()

	public function testAShortWordAllowsOneEdit(): void {
		$words = ['afval' => 2, 'water' => 1];

		$this->assertSame('afval', SpellingSuggester::correct(term: 'afvak', words: $words));
		// Two edits on a five-letter word is too far.
		$this->assertNull(SpellingSuggester::correct(term: 'avfak', words: $words));
		// A longer word may take two.
		$this->assertSame('parkeervergunning', SpellingSuggester::correct(term: 'parkeervergunig', words: ['parkeervergunning' => 1]));
		// A known word is left as it is; nothing changed is no suggestion.
		$this->assertNull(SpellingSuggester::correct(term: 'afval', words: $words));
	}//end testAShortWordAllowsOneEdit()

	public function testNoSuggestionThatFindsNothing(): void {
		$suggester = $this->suggester(words: ['hondenbelasting' => 3], counts: ['hondenbelasting' => 0]);
		$this->assertSame(['suggestion' => null, 'results' => 0], $suggester->suggest(portal: 'gemeente', term: 'hondenbelasing'));

		$unanswered = $this->suggester(words: ['hondenbelasting' => 3], counts: []);
		$this->assertSame(['suggestion' => null, 'results' => 0], $unanswered->suggest(portal: 'gemeente', term: 'hondenbelasing'));
	}//end testNoSuggestionThatFindsNothing()

	public function testTheMoreFrequentWordWinsATie(): void {
		$this->assertSame('kaart', SpellingSuggester::correct(term: 'kaarx', words: ['kaarp' => 1, 'kaart' => 9]));
	}//end testTheMoreFrequentWordWinsATie()

	/**
	 * The suggester over a word list and a search that counts per term.
	 *
	 * @param array<string, int> $words  The word list.
	 * @param array<string, int> $counts The result count per corrected term; absent = no answer.
	 *
	 * @return SpellingSuggester
	 */
	private function suggester(array $words, array $counts): SpellingSuggester {
		$list = $this->getMockBuilder(SuggestionWordList::class)->disableOriginalConstructor()->onlyMethods(['words'])->getMock();
		$list->method('words')->with('gemeente')->willReturn($words);
		$search = $this->getMockBuilder(PublicPublicationSearch::class)->disableOriginalConstructor()->onlyMethods(['count'])->getMock();
		$search->method('count')->willReturnCallback(static fn (string $term): ?int => ($counts[$term] ?? null));

		return new SpellingSuggester($list, $search);
	}//end suggester()
}//end class
