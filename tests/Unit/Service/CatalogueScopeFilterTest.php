<?php

/**
 * CatalogueScopeFilter: which catalogue items a block's scope keeps, by
 * type, app, category and school year.
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

use OCA\Portaliq\Service\CatalogueScope;
use OCA\Portaliq\Service\CatalogueScopeFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue scope filter.
 */
class CatalogueScopeFilterTest extends TestCase {
	/**
	 * A scope with the given parts.
	 *
	 * @param array<int, string> $types The wanted types.
	 * @param string $app The wanted app.
	 * @param array<int, string> $categories The wanted categories.
	 * @param string $range The wanted range.
	 * @param string $today Today.
	 *
	 * @return CatalogueScope
	 */
	private function scope(array $types=[], string $app='', array $categories=[], string $range='', string $today='2026-11-14'): CatalogueScope {
		return new CatalogueScope(types: $types, words: [], today: $today, app: $app, categories: $categories, range: $range, upcoming: false);
	}//end scope()

	/**
	 * An empty scope keeps every item.
	 *
	 * @return void
	 */
	public function testAnEmptyScopeKeepsEverything(): void {
		$filter = new CatalogueScopeFilter();
		$this->assertTrue(condition: $filter->holds(item: [], scope: $this->scope()));
		$this->assertTrue(condition: $filter->holds(item: ['id' => 'x:1', 'type' => 'news'], scope: $this->scope()));
	}//end testAnEmptyScopeKeepsEverything()

	/**
	 * Types, apps and categories each narrow the items.
	 *
	 * @return void
	 */
	public function testEachPartNarrows(): void {
		$filter = new CatalogueScopeFilter();
		$item   = ['id' => 'dossiq:1', 'type' => 'event', 'category' => 'sport'];
		$this->assertTrue(condition: $filter->holds(item: $item, scope: $this->scope(types: ['news', 'event'])));
		$this->assertFalse(condition: $filter->holds(item: $item, scope: $this->scope(types: ['news'])));
		$this->assertTrue(condition: $filter->holds(item: $item, scope: $this->scope(app: 'dossiq')));
		$this->assertFalse(condition: $filter->holds(item: $item, scope: $this->scope(app: 'dossi')));
		$this->assertFalse(condition: $filter->holds(item: ['id' => 'dossiqx:1'] + $item, scope: $this->scope(app: 'dossiq')));
		$this->assertTrue(condition: $filter->holds(item: $item, scope: $this->scope(categories: ['sport'])));
		$this->assertFalse(condition: $filter->holds(item: $item, scope: $this->scope(categories: ['cultuur'])));
		$this->assertFalse(condition: $filter->holds(item: ['id' => 'dossiq:1'], scope: $this->scope(categories: ['sport'])));
	}//end testEachPartNarrows()

	/**
	 * Item dates, today, and whether the item falls in the current school year.
	 *
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public static function schoolYears(): array {
		return [
			'autumn, this year'        => ['2026-09-01', '2026-11-14', true],
			'first day'                => ['2026-08-01', '2026-11-14', true],
			'the day before'           => ['2026-07-31', '2026-11-14', false],
			'last day'                 => ['2027-07-31', '2026-11-14', true],
			'next school year'         => ['2027-08-01', '2026-11-14', false],
			'spring, from spring'      => ['2026-03-01', '2026-04-10', true],
			'last autumn, from spring' => ['2025-10-01', '2026-04-10', true],
			'a timestamp'              => ['2026-10-01T09:00:00+02:00', '2026-11-14', true],
			'from august'              => ['2026-08-15', '2026-08-02', true],
			'from july'                => ['2026-08-15', '2026-07-30', false],
			'no date'                  => ['', '2026-11-14', false],
			'today malformed'          => ['2026-09-01', '14-11-2026', false],
		];
	}//end schoolYears()

	/**
	 * The school year runs from 1 August to 31 July, and an undated item is outside it.
	 *
	 * @param string $date The item's date.
	 * @param string $today Today.
	 * @param bool $inside Whether the item is kept.
	 *
	 * @return void
	 */
	#[DataProvider('schoolYears')]
	public function testTheSchoolYearRunsFromAugust(string $date, string $today, bool $inside): void {
		$this->assertSame(
			expected: $inside,
			actual: (new CatalogueScopeFilter())->holds(item: ['date' => $date], scope: $this->scope(range: 'schoolYear', today: $today))
		);
	}//end testTheSchoolYearRunsFromAugust()

	/**
	 * Another range does not filter by date at all.
	 *
	 * @return void
	 */
	public function testAnotherRangeDoesNotFilter(): void {
		$this->assertTrue(condition: (new CatalogueScopeFilter())->holds(item: ['date' => '1999-01-01'], scope: $this->scope(range: 'month')));
	}//end testAnotherRangeDoesNotFilter()
}//end class
