<?php

/**
 * Unit tests for the "did you mean" correction of a search term.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/search-suggestions-while-typing/tasks.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Search;

use OCA\Portaliq\Service\Search\PublicPublicationSearch;
use OCA\Portaliq\Service\Search\SpellingSuggester;
use OCA\Portaliq\Service\Search\SuggestionWordList;
use PHPUnit\Framework\TestCase;

/**
 * A word the portal's own words do not hold is replaced by its nearest one,
 * and a suggestion is only offered when searching it finds something.
 *
 * @covers \OCA\Portaliq\Service\Search\SpellingSuggester
 */
class SpellingSuggesterTest extends TestCase {
	/**
	 * @return void
	 */
	public function testCorrectReplacesOnlyTheWordsTheListDoesNotHold(): void {
		$words = ['afval' => 9, 'kalender' => 4, 'parkeren' => 7];

		$this->assertSame('afval kalender', SpellingSuggester::correct(term: 'afvall  Kalender', words: $words));
		$this->assertNull(SpellingSuggester::correct(term: 'afval', words: $words), 'Nothing to correct.');
		$this->assertNull(SpellingSuggester::correct(term: 'zzzzzz', words: $words), 'Nothing near.');
		$this->assertNull(SpellingSuggester::correct(term: 'afvall', words: []));
		$this->assertSame('parkeren', SpellingSuggester::correct(term: 'parkerne', words: $words), 'Two edits allowed beyond five letters.');
		$this->assertNull(SpellingSuggester::correct(term: 'afvaal ', words: ['afvaaaaal' => 1]));
	}//end testCorrectReplacesOnlyTheWordsTheListDoesNotHold()

	/**
	 * @return void
	 */
	public function testTheNearestWordWinsByDistanceThenByCount(): void {
		$this->assertSame('boom', SpellingSuggester::correct(term: 'boon', words: ['bood' => 1, 'boom' => 5, 'boot' => 2]));
		$this->assertSame('bood', SpellingSuggester::correct(term: 'boon', words: ['bood' => 5, 'boom' => 5]), 'Equal count: the first by spelling.');
	}//end testTheNearestWordWinsByDistanceThenByCount()

	/**
	 * @return void
	 */
	public function testASuggestionNeedsAnEmptyTermToBeSkippedAndAHitToBeOffered(): void {
		$list = $this->createMock(SuggestionWordList::class);
		$list->method('words')->willReturn(['afval' => 3]);
		$search = $this->createMock(PublicPublicationSearch::class);
		$search->method('count')->willReturnOnConsecutiveCalls(5, 0, null);
		$suggester = new SpellingSuggester($list, $search);
		$none      = ['suggestion' => null, 'results' => 0];

		$this->assertSame($none, $suggester->suggest(portal: 'p', term: '   '));
		$this->assertSame($none, $suggester->suggest(portal: 'p', term: str_repeat('a', SpellingSuggester::MAX_TERM_LENGTH + 1)));
		$this->assertSame($none, $suggester->suggest(portal: 'p', term: 'afval'));
		$this->assertSame(['suggestion' => 'afval', 'results' => 5], $suggester->suggest(portal: 'p', term: 'afvall'));
		$this->assertSame($none, $suggester->suggest(portal: 'p', term: 'afvall'));
		$this->assertSame($none, $suggester->suggest(portal: 'p', term: 'afvall'));
	}//end testASuggestionNeedsAnEmptyTermToBeSkippedAndAHitToBeOffered()
}//end class
