<?php

/**
 * CollectionListKeys: a collection block's limit, skip, label, sort, cards
 * and calendar range.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Contribution
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

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\CollectionListKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The list keys of a collection block.
 */
class CollectionListKeysTest extends TestCase {
	/**
	 * A collection that projects three fields to the resident.
	 */
	private const COLLECTION = ['id' => 'cases', 'fields' => ['title', 'status', 'done', 'total']];

	/**
	 * The keys of a block against the collection above.
	 *
	 * @param array<string, mixed> $block The declared block.
	 * @param array<string, mixed>|null $collection The collection, defaulting to the one above.
	 *
	 * @return array<string, mixed>
	 */
	private function keys(array $block, ?array $collection=self::COLLECTION): array {
		return (new CollectionListKeys())->collectionKeys(block: $block, collection: $collection);
	}//end keys()

	/**
	 * Every key that is well formed is kept, in a fixed order.
	 *
	 * @return void
	 */
	public function testEveryWellFormedKeyIsKept(): void {
		$this->assertSame(
			expected: [
				'limit' => 5,
				'label' => 'Open cases',
				'skip'  => 2,
				'sort'  => ['field' => 'status', 'direction' => 'desc'],
			],
			actual: $this->keys(block: ['sort' => ['field' => 'status', 'direction' => 'desc'], 'skip' => 2, 'label' => ' Open cases ', 'limit' => 5])
		);
	}//end testEveryWellFormedKeyIsKept()

	/**
	 * Limits and skips and whether they survive.
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function counts(): array {
		return [
			'one'      => [1, true],
			'fifty'    => [50, true],
			'zero'     => [0, false],
			'too many' => [51, false],
			'negative' => [-1, false],
			'string'   => ['5', false],
			'float'    => [5.0, false],
		];
	}//end counts()

	/**
	 * Limit and skip both take a whole number from 1 to 50.
	 *
	 * @param mixed $declared The declared count.
	 * @param bool $kept Whether it survives.
	 *
	 * @return void
	 */
	#[DataProvider('counts')]
	public function testLimitAndSkipAreBounded(mixed $declared, bool $kept): void {
		$keys = $this->keys(block: ['limit' => $declared, 'skip' => $declared]);
		$this->assertSame(expected: $kept, actual: array_key_exists('limit', $keys));
		$this->assertSame(expected: $kept, actual: array_key_exists('skip', $keys));
	}//end testLimitAndSkipAreBounded()

	/**
	 * A label is trimmed, and dropped when blank or longer than 120 characters after trimming.
	 *
	 * @return void
	 */
	public function testTheLabelIsTrimmedAndBounded(): void {
		$this->assertSame(expected: [], actual: $this->keys(block: ['label' => '   ']));
		$this->assertSame(expected: [], actual: $this->keys(block: ['label' => str_repeat('x', 121)]));
		$this->assertSame(expected: [], actual: $this->keys(block: ['label' => ['Open']]));
		$this->assertSame(expected: ['label' => str_repeat('x', 120)], actual: $this->keys(block: ['label' => '  '.str_repeat('x', 120).'  ']));
	}//end testTheLabelIsTrimmedAndBounded()

	/**
	 * A sort needs a projected field and an explicit direction.
	 *
	 * @return void
	 */
	public function testASortNeedsAProjectedFieldAndADirection(): void {
		$this->assertSame(expected: [], actual: $this->keys(block: ['sort' => ['field' => 'secret', 'direction' => 'asc']]));
		$this->assertSame(expected: [], actual: $this->keys(block: ['sort' => ['field' => 'status']]));
		$this->assertSame(expected: [], actual: $this->keys(block: ['sort' => ['field' => 'status', 'direction' => 'ASC']]));
		$this->assertSame(expected: [], actual: $this->keys(block: ['sort' => 'status']));
		$this->assertSame(expected: [], actual: $this->keys(block: ['sort' => ['field' => 'status', 'direction' => 'asc']], collection: null));
	}//end testASortNeedsAProjectedFieldAndADirection()

	/**
	 * A collection that names no fields lets any field through: it projects them all.
	 *
	 * @return void
	 */
	public function testACollectionWithoutAFieldListAcceptsAnyField(): void {
		$this->assertSame(
			expected: ['sort' => ['field' => 'anything', 'direction' => 'asc']],
			actual: $this->keys(block: ['sort' => ['field' => 'anything', 'direction' => 'asc']], collection: ['id' => 'cases'])
		);
	}//end testACollectionWithoutAFieldListAcceptsAnyField()

	/**
	 * The cards display keeps projected title fields and a progress bar.
	 *
	 * @return void
	 */
	public function testTheCardsDisplayKeepsTitlesAndProgress(): void {
		$keys = $this->keys(
			block: [
				'display'     => 'cards',
				'titleFields' => ['title', 'secret', '', 7],
				'progress'    => ['valueField' => 'done', 'totalField' => 'total', 'label' => ' steps '],
			]
		);
		$this->assertSame(expected: 'cards', actual: $keys['display']);
		$this->assertSame(expected: ['title'], actual: $keys['titleFields']);
		$this->assertSame(expected: ['valueField' => 'done', 'totalField' => 'total', 'label' => 'steps'], actual: $keys['progress']);
	}//end testTheCardsDisplayKeepsTitlesAndProgress()

	/**
	 * A progress bar needs both fields projected; its label is optional.
	 *
	 * @return void
	 */
	public function testAProgressBarNeedsBothFields(): void {
		$block = ['display' => 'cards'];
		$this->assertArrayNotHasKey(key: 'progress', array: $this->keys(block: $block + ['progress' => ['valueField' => 'done']]));
		$this->assertArrayNotHasKey(key: 'progress', array: $this->keys(block: $block + ['progress' => ['valueField' => 'done', 'totalField' => 'secret']]));
		$this->assertArrayNotHasKey(key: 'progress', array: $this->keys(block: $block + ['progress' => 'done/total']));
		$this->assertSame(
			expected: ['valueField' => 'done', 'totalField' => 'total'],
			actual: $this->keys(block: $block + ['progress' => ['valueField' => 'done', 'totalField' => 'total', 'label' => ' ']])['progress']
		);
	}//end testAProgressBarNeedsBothFields()

	/**
	 * Title fields and progress belong to the cards display only.
	 *
	 * @return void
	 */
	public function testCardKeysNeedTheCardsDisplay(): void {
		$keys = $this->keys(block: ['titleFields' => ['title'], 'progress' => ['valueField' => 'done', 'totalField' => 'total']]);
		$this->assertSame(expected: [], actual: $keys);
	}//end testCardKeysNeedTheCardsDisplay()

	/**
	 * The calendar range is one of day, week or month.
	 *
	 * @return void
	 */
	public function testTheCalendarRangeIsOneOfThree(): void {
		$keys = new CollectionListKeys();
		foreach (['day', 'week', 'month'] as $range) {
			$this->assertSame(expected: ['range' => $range], actual: $keys->calendarKeys(block: ['range' => $range]));
		}

		foreach (['year', 'Week', '', null] as $range) {
			$this->assertSame(expected: [], actual: $keys->calendarKeys(block: ['range' => $range]));
		}
	}//end testTheCalendarRangeIsOneOfThree()
}//end class
