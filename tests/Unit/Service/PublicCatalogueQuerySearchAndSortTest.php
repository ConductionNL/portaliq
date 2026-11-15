<?php

/**
 * PublicCatalogueQuery: how words match, how each sort orders, and how
 * pages are clamped.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PublicCatalogueQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Search, sort and paging of the public catalogue.
 */
class PublicCatalogueQuerySearchAndSortTest extends TestCase {
	/**
	 * A small catalogue with dates, kinds, summaries and meta.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function items(): array {
		return [
			['id' => 'a:1', 'type' => 'news', 'title' => 'Café opent in de haven', 'date' => '2026-03-01', 'summary' => 'Nieuw terras'],
			['id' => 'a:2', 'type' => 'news', 'title' => 'Bijeenkomst over de haven', 'date' => '2026-05-10', 'kind' => 'Overleg'],
			['id' => 'b:1', 'type' => 'event', 'title' => 'Zomerfeest', 'date' => '2026-07-01', 'endDate' => '2026-07-03', 'meta' => ['Stadspark']],
			['id' => 'b:2', 'type' => 'event', 'title' => 'archief', 'summary' => 'Zonder datum'],
		];
	}//end items()

	/**
	 * The titles of a query's items, in order.
	 *
	 * @param array<string, mixed> $params The query parameters.
	 *
	 * @return array<int, string>
	 */
	private function titles(array $params): array {
		return array_column((new PublicCatalogueQuery())->run(items: $this->items(), params: $params)['items'], 'title');
	}//end titles()

	/**
	 * Words fold case and accents, on both sides of the match.
	 *
	 * @return void
	 */
	public function testWordsFoldCaseAndAccents(): void {
		$this->assertSame(expected: ['Café opent in de haven'], actual: $this->titles(params: ['q' => 'CAFE']));
		$this->assertSame(expected: ['Café opent in de haven'], actual: $this->titles(params: ['q' => 'cafè']));
	}//end testWordsFoldCaseAndAccents()

	/**
	 * Every word must occur, in the title, kind, summary or meta.
	 *
	 * @return void
	 */
	public function testEveryWordMustOccurSomewhere(): void {
		$this->assertSame(expected: ['Café opent in de haven'], actual: $this->titles(params: ['q' => 'haven terras']));
		$this->assertSame(expected: ['Bijeenkomst over de haven'], actual: $this->titles(params: ['q' => 'overleg']));
		$this->assertSame(expected: ['Zomerfeest'], actual: $this->titles(params: ['q' => 'stadspark']));
		$this->assertSame(expected: [], actual: $this->titles(params: ['q' => 'haven stadspark']));
	}//end testEveryWordMustOccurSomewhere()

	/**
	 * Punctuation splits words and a query of only punctuation matches everything.
	 *
	 * @return void
	 */
	public function testPunctuationSplitsWords(): void {
		$this->assertSame(expected: ['Café opent in de haven'], actual: $this->titles(params: ['q' => 'café,,,opent!']));
		$this->assertCount(expectedCount: 4, haystack: $this->titles(params: ['q' => ' -- ?! ']));
	}//end testPunctuationSplitsWords()

	/**
	 * Relevance puts the most title hits first, then the newest.
	 *
	 * @return void
	 */
	public function testRelevanceRanksTitleHitsThenDate(): void {
		$this->assertSame(
			expected: ['Bijeenkomst over de haven', 'Café opent in de haven'],
			actual: $this->titles(params: ['q' => 'haven'])
		);
		$items = [
			['id' => 'a:1', 'title' => 'Markt', 'date' => '2026-09-01', 'summary' => 'Kraam en haven'],
			['id' => 'a:2', 'title' => 'Havenfeest', 'date' => '2026-01-01'],
		];
		$result = (new PublicCatalogueQuery())->run(items: $items, params: ['q' => 'haven']);
		$this->assertSame(expected: ['Havenfeest', 'Markt'], actual: array_column($result['items'], 'title'));
	}//end testRelevanceRanksTitleHitsThenDate()

	/**
	 * Each named sort and the order it gives.
	 *
	 * @return array<string, array{0: string, 1: array<int, string>}>
	 */
	public static function sorts(): array {
		return [
			'date rises, undated last' => ['date', ['Café opent in de haven', 'Bijeenkomst over de haven', 'Zomerfeest', 'archief']],
			'date falls, undated last' => ['dateDesc', ['Zomerfeest', 'Bijeenkomst over de haven', 'Café opent in de haven', 'archief']],
			'title ignores case'       => ['title', ['archief', 'Bijeenkomst over de haven', 'Café opent in de haven', 'Zomerfeest']],
			'unknown means relevance'  => ['random', ['Zomerfeest', 'Bijeenkomst over de haven', 'Café opent in de haven', 'archief']],
		];
	}//end sorts()

	/**
	 * Each sort orders the hits as named.
	 *
	 * @param string $sort The sort.
	 * @param array<int, string> $expected The titles in order.
	 *
	 * @return void
	 */
	#[DataProvider('sorts')]
	public function testEachSortOrdersAsNamed(string $sort, array $expected): void {
		$this->assertSame(expected: $expected, actual: $this->titles(params: ['sort' => $sort]));
	}//end testEachSortOrdersAsNamed()

	/**
	 * Upcoming keeps items whose end, or else start, is today or later.
	 *
	 * @return void
	 */
	public function testUpcomingUsesTheEndDateFirst(): void {
		$this->assertSame(expected: ['Zomerfeest'], actual: $this->titles(params: ['upcoming' => true, 'today' => '2026-07-02']));
		$this->assertSame(
			expected: ['Bijeenkomst over de haven', 'Zomerfeest'],
			actual: $this->titles(params: ['upcoming' => true, 'today' => '2026-05-10', 'sort' => 'date'])
		);
		$this->assertCount(expectedCount: 4, haystack: $this->titles(params: ['upcoming' => 'yes', 'today' => '2030-01-01']));
	}//end testUpcomingUsesTheEndDateFirst()

	/**
	 * The limit is clamped to 1..50 and the page to the pages there are.
	 *
	 * @return void
	 */
	public function testThePagingIsClamped(): void {
		$query = new PublicCatalogueQuery();
		$page  = $query->run(items: $this->items(), params: ['limit' => 3, 'page' => 9, 'sort' => 'title']);
		$this->assertSame(expected: [4, 2, 2], actual: [$page['total'], $page['page'], $page['pages']]);
		$this->assertSame(expected: ['Zomerfeest'], actual: array_column($page['items'], 'title'));

		$first = $query->run(items: $this->items(), params: ['limit' => 0, 'page' => -3]);
		$this->assertSame(expected: [1, 4, 1], actual: [$first['page'], $first['pages'], count($first['items'])]);

		$all = $query->run(items: $this->items(), params: ['limit' => 1000]);
		$this->assertSame(expected: 1, actual: $all['pages']);
	}//end testThePagingIsClamped()

	/**
	 * An empty result is still one page, with no facets.
	 *
	 * @return void
	 */
	public function testAnEmptyResultIsOnePage(): void {
		$result = (new PublicCatalogueQuery())->run(items: [], params: ['q' => 'x']);
		$this->assertSame(expected: ['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'facets' => []], actual: $result);
	}//end testAnEmptyResultIsOnePage()

	/**
	 * Filters without a label, or without string values, are ignored.
	 *
	 * @return void
	 */
	public function testMalformedFiltersAreIgnored(): void {
		$items  = [
			['id' => 'a:1', 'title' => 'One', 'facets' => ['Wijk' => ['Noord']]],
			['id' => 'a:2', 'title' => 'Two', 'facets' => ['Wijk' => ['Zuid']]],
		];
		$params = ['filters' => ['' => ['Noord'], 'Wijk' => ['', 5, null], 3 => ['Zuid']]];
		$result = (new PublicCatalogueQuery())->run(items: $items, params: $params);
		$this->assertSame(expected: 2, actual: $result['total']);
		$this->assertSame(
			expected: [
				[
					'label'  => 'Wijk',
					'values' => [['value' => 'Noord', 'count' => 1, 'selected' => false], ['value' => 'Zuid', 'count' => 1, 'selected' => false]],
				],
			],
			actual: $result['facets']
		);
		$this->assertSame(expected: 2, actual: (new PublicCatalogueQuery())->run(items: $items, params: ['filters' => 'Wijk'])['total']);
	}//end testMalformedFiltersAreIgnored()

	/**
	 * A selected value is marked, and counts come from the other filters only.
	 *
	 * @return void
	 */
	public function testAFacetCountsIgnoreItsOwnFilter(): void {
		$items  = [
			['id' => 'a:1', 'title' => 'One', 'facets' => ['Wijk' => ['Noord'], 'Soort' => ['Feest']]],
			['id' => 'a:2', 'title' => 'Two', 'facets' => ['Wijk' => ['Zuid'], 'Soort' => ['Feest']]],
			['id' => 'a:3', 'title' => 'Three', 'facets' => ['Wijk' => ['Zuid'], 'Soort' => ['Markt']]],
		];
		$result = (new PublicCatalogueQuery())->run(items: $items, params: ['filters' => ['Wijk' => ['Zuid'], 'Soort' => ['Feest']]]);
		$this->assertSame(expected: ['Two'], actual: array_column($result['items'], 'title'));
		$facets = array_column($result['facets'], 'values', 'label');
		$this->assertSame(
			expected: [['value' => 'Noord', 'count' => 1, 'selected' => false], ['value' => 'Zuid', 'count' => 1, 'selected' => true]],
			actual: $facets['Wijk']
		);
		$this->assertSame(
			expected: [['value' => 'Feest', 'count' => 1, 'selected' => true], ['value' => 'Markt', 'count' => 1, 'selected' => false]],
			actual: $facets['Soort']
		);
	}//end testAFacetCountsIgnoreItsOwnFilter()
}//end class
