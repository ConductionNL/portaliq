<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Search;

use OCA\Portaliq\Service\Search\AnonymousPublicationSearch;
use OCA\Portaliq\Service\Search\SpellingSuggester;
use OCA\Portaliq\Service\Search\SuggestionWordList;
use PHPUnit\Framework\TestCase;

/**
 * search-sort-by-relevance REQ-SSR-005: the correction rule and the check
 * that the corrected search finds something.
 *
 * @spec openspec/changes/search-sort-by-relevance/tasks.md#t08
 */
class SpellingSuggesterTest extends TestCase {

	/**
	 * Every corrected term the suggester checked.
	 *
	 * @var list<string>
	 */
	private array $checked = [];

	/**
	 * A suggester over a fixed word list and a search answering the given totals.
	 *
	 * @param array<string, int> $list   The word list.
	 * @param array<string, int> $totals Total per corrected term; absent is 0.
	 *
	 * @return SpellingSuggester
	 */
	private function suggester(array $list, array $totals): SpellingSuggester {
		$words = $this->createMock(SuggestionWordList::class);
		$words->method('load')->with('zuiddrecht')->willReturn($list);

		$search = $this->createMock(AnonymousPublicationSearch::class);
		$search->method('search')->willReturnCallback(
			function (string $term, int $page=1, int $limit=100) use ($totals): array {
				$this->checked[] = $term;
				return ['results' => [], 'total' => ($totals[$term] ?? 0)];
			}
		);

		return new SpellingSuggester($words, $search);
	}

	public function testASummaryWordGetsASuggestion(): void {
		$suggester = $this->suggester(
			list: ['hondenbelasting' => 4, 'aanslag' => 2],
			totals: ['hondenbelasting' => 4]
		);

		$this->assertSame(
			['suggestion' => 'hondenbelasting', 'results' => 4],
			$suggester->suggest(portal: 'zuiddrecht', term: 'hondenbelasing')
		);
		$this->assertSame(['hondenbelasting'], $this->checked);
	}

	public function testAShortWordAllowsOneEdit(): void {
		$suggester = $this->suggester(list: ['markt' => 3], totals: ['markt' => 3]);

		// Five letters, one edit away: corrected.
		$this->assertSame('markt', $suggester->suggest(portal: 'zuiddrecht', term: 'merkt')['suggestion']);
		// Five letters, two edits away: left alone, and nothing is searched.
		$this->checked = [];
		$this->assertSame(['suggestion' => null, 'results' => 0], $suggester->suggest(portal: 'zuiddrecht', term: 'mekrt'));
		$this->assertSame([], $this->checked);
	}

	public function testALongWordAllowsTwoEdits(): void {
		$suggester = $this->suggester(list: ['parkeervergunning' => 1], totals: ['parkeervergunning' => 1]);

		$this->assertSame('parkeervergunning', $suggester->suggest(portal: 'zuiddrecht', term: 'parkervergunnig')['suggestion']);
		$this->assertNull($suggester->suggest(portal: 'zuiddrecht', term: 'prkervergunnig')['suggestion']);
	}

	public function testNoSuggestionThatFindsNothing(): void {
		$suggester = $this->suggester(list: ['hondenbelasting' => 4], totals: []);

		$this->assertSame(
			['suggestion' => null, 'results' => 0],
			$suggester->suggest(portal: 'zuiddrecht', term: 'hondenbelasing')
		);
		$this->assertSame(['hondenbelasting'], $this->checked, 'the correction is checked before it is withheld');
	}

	public function testTheMoreFrequentWordWinsATie(): void {
		$suggester = $this->suggester(list: ['bomen' => 2, 'bonen' => 9], totals: ['bonen' => 1, 'bomen' => 1]);

		$this->assertSame('bonen', $suggester->suggest(portal: 'zuiddrecht', term: 'boden')['suggestion']);
	}

	public function testAKnownWordIsKeptAndOnlyTheUnknownOneChanges(): void {
		$suggester = $this->suggester(
			list: ['vergunning' => 5, 'terras' => 2],
			totals: ['Terras vergunning' => 2]
		);

		$this->assertSame(
			['suggestion' => 'Terras vergunning', 'results' => 2],
			$suggester->suggest(portal: 'zuiddrecht', term: 'Terras vergunnig')
		);
	}

	public function testATermOfKnownWordsGetsNoSuggestion(): void {
		$suggester = $this->suggester(list: ['terras' => 2], totals: ['terras' => 2]);

		$this->assertSame(['suggestion' => null, 'results' => 0], $suggester->suggest(portal: 'zuiddrecht', term: 'terras'));
		$this->assertSame([], $this->checked);
	}

	public function testAnEmptyListSuggestsNothing(): void {
		$suggester = $this->suggester(list: [], totals: []);

		$this->assertNull($suggester->suggest(portal: 'zuiddrecht', term: 'geheimm')['suggestion']);
		$this->assertSame([], $this->checked);
	}

	public function testTheDistanceCountsLettersNotBytes(): void {
		$this->assertSame(1, SpellingSuggester::distance(left: 'café', right: 'cafe'));
		$this->assertSame(0, SpellingSuggester::distance(left: 'één', right: 'één'));
		$this->assertSame(3, SpellingSuggester::distance(left: 'kitten', right: 'sitting'));
		$this->assertSame(4, SpellingSuggester::distance(left: '', right: 'abcd'));
	}
}
