<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\CollectionGroupOrderKey;
use PHPUnit\Framework\TestCase;

/**
 * collection-group-order T2: `groupOrder` is kept only as 'rows' or 'heading'
 * beside `groupByField`.
 *
 * @spec openspec/changes/collection-group-order/tasks.md#T2
 */
class CollectionGroupOrderKeyTest extends TestCase {
	/**
	 * A known order beside a group field is kept as it is.
	 *
	 * @return void
	 */
	public function testAKnownOrderBesideAGroupFieldIsKept(): void {
		$key = new CollectionGroupOrderKey();
		foreach (CollectionGroupOrderKey::ORDERS as $order) {
			$out = $key->normalise(collection: ['groupByField' => 'courseName', 'groupOrder' => $order]);
			$this->assertSame(expected: $order, actual: $out['groupOrder']);
		}
	}//end testAKnownOrderBesideAGroupFieldIsKept()

	/**
	 * An unknown order, or an order without a group field, is dropped; a
	 * collection without the key is left alone.
	 *
	 * @return void
	 */
	public function testAnUnknownOrderOrOneWithoutAGroupFieldIsDropped(): void {
		$key = new CollectionGroupOrderKey();
		$this->assertArrayNotHasKey(key: 'groupOrder', array: $key->normalise(collection: ['groupByField' => 'x', 'groupOrder' => 'Rows']));
		$this->assertArrayNotHasKey(key: 'groupOrder', array: $key->normalise(collection: ['groupByField' => 'x', 'groupOrder' => null]));
		$this->assertArrayNotHasKey(key: 'groupOrder', array: $key->normalise(collection: ['groupOrder' => 'rows']));
		$this->assertSame(expected: ['id' => 'c'], actual: $key->normalise(collection: ['id' => 'c']));
	}//end testAnUnknownOrderOrOneWithoutAGroupFieldIsDropped()
}//end class
