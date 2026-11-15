<?php

/**
 * PublicCatalogueQuery::run(): the scope, the words, the upcoming filter and
 * the paging of a catalogue query, as recorded.
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
use PHPUnit\Framework\TestCase;

/**
 * Which items a query keeps, and which page of them it answers.
 */
class PublicCatalogueQueryGoldenTest extends TestCase {
	/**
	 * The items every case reads.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function items(): array {
		return [
			['id' => 'learniq:a', 'type' => 'course', 'title' => 'Één cursus', 'category' => 'taal', 'date' => '2026-09-01', 'facets' => ['Niveau' => ['A1']]],
			['id' => 'learniq:b', 'type' => 'course', 'title' => 'Rekenen', 'category' => 'reken', 'date' => '2025-07-31', 'facets' => ['Niveau' => ['A2']]],
			['id' => 'eventiq:c', 'type' => 'event', 'title' => 'Open dag', 'kind' => 'cursus', 'date' => '2026-10-10', 'endDate' => '2026-10-11'],
			['id' => 'eventiq:d', 'type' => 'event', 'title' => 'Oud', 'summary' => 'een eerdere cursus', 'date' => '2026-08-01'],
			['id' => 'newsiq:e', 'type' => 'news', 'title' => 'Bericht', 'meta' => ['Cursus nieuws']],
		];
	}//end items()

	/**
	 * The ids of a run's items.
	 *
	 * @param array<string, mixed> $params The query.
	 *
	 * @return array<int, string>
	 */
	private static function ids(array $params): array {
		return array_column((new PublicCatalogueQuery())->run(items: self::items(), params: $params)['items'], 'id');
	}//end ids()

	/**
	 * Types, app, category, school year, words and upcoming each narrow the
	 * items, alone and together.
	 *
	 * @return void
	 */
	public function testEachPartOfTheScopeNarrowsAsRecorded(): void {
		$this->assertSame(['learniq:b', 'learniq:a'], self::ids(['types' => ['course', 7], 'sort' => 'title']));
		$this->assertSame(['eventiq:c', 'eventiq:d'], self::ids(['app' => 'eventiq', 'sort' => 'title']));
		$this->assertSame(['learniq:b'], self::ids(['categories' => ['reken', 3]]));
		$this->assertSame(['eventiq:d', 'learniq:a', 'eventiq:c'], self::ids(['range' => 'schoolYear', 'today' => '2026-11-10', 'sort' => 'date']));
		$this->assertSame([], self::ids(['range' => 'schoolYear', 'today' => '2026-03-01']));
		$this->assertSame([], self::ids(['range' => 'schoolYear', 'today' => 'not a day']));
		$this->assertSame(['newsiq:e', 'eventiq:c', 'eventiq:d', 'learniq:a'], self::ids(['q' => 'CURSUS', 'sort' => 'title']));
		$this->assertSame(['eventiq:c'], self::ids(['upcoming' => true, 'today' => '2026-10-11']));
		$this->assertSame(['eventiq:c'], self::ids(['q' => 'cursus', 'app' => 'eventiq', 'upcoming' => true, 'today' => '2026-10-01']));
		$this->assertSame(['eventiq:c', 'learniq:a', 'eventiq:d', 'learniq:b', 'newsiq:e'], self::ids(['upcoming' => 'yes', 'sort' => 'dateDesc']));
	}//end testEachPartOfTheScopeNarrowsAsRecorded()

	/**
	 * The limit is held between 1 and the page maximum, the page between 1
	 * and the last one, and the facets count every item in scope.
	 *
	 * @return void
	 */
	public function testThePagingAndFacetsAreAsRecorded(): void {
		$query = new PublicCatalogueQuery();

		$first = $query->run(items: self::items(), params: ['limit' => 0, 'page' => -3, 'sort' => 'title']);
		$this->assertSame([['newsiq:e'], 5, 1, 5], [array_column($first['items'], 'id'), $first['total'], $first['page'], $first['pages']]);

		$last = $query->run(items: self::items(), params: ['limit' => 2, 'page' => 99, 'sort' => 'title']);
		$this->assertSame([['learniq:a'], 5, 3, 3], [array_column($last['items'], 'id'), $last['total'], $last['page'], $last['pages']]);

		$all = $query->run(items: self::items(), params: ['limit' => 500, 'filters' => ['Niveau' => ['A1']]]);
		$this->assertSame([1, 1, 1], [$all['total'], $all['page'], $all['pages']]);
		$this->assertSame(
			[['label' => 'Niveau', 'values' => [['value' => 'A1', 'count' => 1, 'selected' => true], ['value' => 'A2', 'count' => 1, 'selected' => false]]]],
			$all['facets']
		);

		$none = $query->run(items: [], params: []);
		$this->assertSame(['items' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'facets' => []], $none);
	}//end testThePagingAndFacetsAreAsRecorded()
}//end class
