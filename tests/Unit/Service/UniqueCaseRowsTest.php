<?php

/**
 * UniqueCaseRows: case lists from several sources merged into one, each
 * case once.
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

use OCA\Portaliq\Service\UniqueCaseRows;
use PHPUnit\Framework\TestCase;

/**
 * The merged case rows.
 */
class UniqueCaseRowsTest extends TestCase {
	/**
	 * A case in two lists appears once, as the first list had it, in first-seen order.
	 *
	 * @return void
	 */
	public function testTheFirstCopyOfACaseWins(): void {
		$merged = (new UniqueCaseRows())->merged(
			lists: [
				[['id' => 'c1', 'from' => 'own'], ['id' => 'c2', 'from' => 'own']],
				[['id' => 'c3', 'from' => 'mandate'], ['id' => 'c1', 'from' => 'mandate']],
			]
		);
		$this->assertSame(
			expected: [['id' => 'c1', 'from' => 'own'], ['id' => 'c2', 'from' => 'own'], ['id' => 'c3', 'from' => 'mandate']],
			actual: $merged
		);
	}//end testTheFirstCopyOfACaseWins()

	/**
	 * The id is read from id, then uuid, then the object metadata.
	 *
	 * @return void
	 */
	public function testTheIdIsReadFromEveryPlaceItMayBe(): void {
		$merged = (new UniqueCaseRows())->merged(
			lists: [
				[['id' => 'x'], ['uuid' => 'y'], ['@self' => ['id' => 'z']], ['@self' => ['uuid' => 'w']]],
				[['uuid' => 'x'], ['id' => 'y'], ['id' => 'z'], ['uuid' => 'w']],
			]
		);
		$this->assertCount(expectedCount: 4, haystack: $merged);
	}//end testTheIdIsReadFromEveryPlaceItMayBe()

	/**
	 * Numeric and string ids of the same case are one case.
	 *
	 * @return void
	 */
	public function testNumericAndStringIdsMatch(): void {
		$merged = (new UniqueCaseRows())->merged(lists: [[['id' => 7, 'n' => 1]], [['id' => '7', 'n' => 2]]]);
		$this->assertSame(expected: [['id' => 7, 'n' => 1]], actual: $merged);
	}//end testNumericAndStringIdsMatch()

	/**
	 * Rows without an id are only merged when they are identical.
	 *
	 * @return void
	 */
	public function testRowsWithoutAnIdMergeOnlyWhenIdentical(): void {
		$merged = (new UniqueCaseRows())->merged(
			lists: [
				[['title' => 'A'], ['title' => 'B', 'id' => ''], 'loose'],
				[['title' => 'A'], ['title' => 'B', 'id' => ''], ['title' => 'C', 'id' => ['x']], 'loose', 'other'],
			]
		);
		$this->assertSame(
			expected: [['title' => 'A'], ['title' => 'B', 'id' => ''], 'loose', ['title' => 'C', 'id' => ['x']], 'other'],
			actual: $merged
		);
	}//end testRowsWithoutAnIdMergeOnlyWhenIdentical()

	/**
	 * An id row and an identical-looking row without an id stay apart.
	 *
	 * @return void
	 */
	public function testAnIdNeverCollidesWithARowKey(): void {
		$merged = (new UniqueCaseRows())->merged(lists: [[['id' => 'c1']], [['title' => 'c1']], []]);
		$this->assertCount(expectedCount: 2, haystack: $merged);
		$this->assertSame(expected: [], actual: (new UniqueCaseRows())->merged(lists: []));
	}//end testAnIdNeverCollidesWithARowKey()
}//end class
