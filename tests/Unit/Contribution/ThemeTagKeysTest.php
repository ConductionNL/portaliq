<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\ThemeTagKeys;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Sanitising theme, field-key and `when` declarations on collections and actions.
 */
#[CoversClass(ThemeTagKeys::class)]
class ThemeTagKeysTest extends TestCase {
	/**
	 * Non-product collections lose the product-only keys; a bad theme goes.
	 *
	 * @return void
	 */
	public function testNonProductCollection(): void {
		$keys = new ThemeTagKeys();

		$out = $keys->collection(['theme' => 'Bad Theme', 'titleField' => 'n', 'metaFields' => ['a'], 'countLabel' => [], 'validFromField' => 'x', 'keep' => 1]);
		$this->assertSame(['keep' => 1], $out);

		$this->assertSame(['theme' => 'green-1'], $keys->collection(['theme' => 'green-1']));
		$this->assertSame([], $keys->collection(['theme' => 5]));
	}//end testNonProductCollection()

	/**
	 * Product collections keep sound field keys, meta fields and count labels.
	 *
	 * @return void
	 */
	public function testProductCollection(): void {
		$keys = new ThemeTagKeys();

		$out = $keys->collection([
			'kind' => 'products',
			'titleField' => 'name',
			'validFromField' => '1bad',
			'validUntilField' => ['x'],
			'metaFields' => ['price', 'bad key', 7, 'sku'],
			'countLabel' => ['singular' => ' item ', 'plural' => ' items '],
		]);

		$this->assertSame(
			['kind' => 'products', 'titleField' => 'name', 'metaFields' => ['price', 'sku'], 'countLabel' => ['singular' => 'item', 'plural' => 'items']],
			$out
		);
	}//end testProductCollection()

	/**
	 * Empty meta fields and malformed count labels are dropped.
	 *
	 * @return void
	 */
	public function testProductCollectionDropsBadMetaAndLabel(): void {
		$keys = new ThemeTagKeys();

		$this->assertSame(['kind' => 'products'], $keys->collection(['kind' => 'products', 'metaFields' => ['bad key']]));
		$this->assertSame(['kind' => 'products'], $keys->collection(['kind' => 'products', 'metaFields' => 'str']));
		$this->assertSame(['kind' => 'products'], $keys->collection(['kind' => 'products', 'countLabel' => 'x']));
		$this->assertSame(['kind' => 'products'], $keys->collection(['kind' => 'products', 'countLabel' => ['singular' => 'a', 'plural' => ' ']]));
		$this->assertSame(['kind' => 'products'], $keys->collection(['kind' => 'products', 'countLabel' => ['singular' => 'a', 'plural' => 3]]));
	}//end testProductCollectionDropsBadMetaAndLabel()

	/**
	 * Actions keep a `when` only if field, operator and value are consistent.
	 *
	 * @return void
	 */
	public function testActionWhen(): void {
		$keys = new ThemeTagKeys();

		$this->assertSame(['id' => 1], $keys->action(['id' => 1]));
		$this->assertSame(
			['when' => ['field' => 'status', 'op' => 'eq', 'value' => 'open']],
			$keys->action(['when' => ['field' => 'status', 'op' => 'eq', 'value' => 'open', 'junk' => 1]])
		);
		$this->assertSame(
			['when' => ['field' => 'status', 'op' => 'in', 'value' => ['a', 1]]],
			$keys->action(['when' => ['field' => 'status', 'op' => 'in', 'value' => ['a', 1]]])
		);

		$bad = [
			'text',
			['field' => '1x', 'op' => 'eq', 'value' => 1],
			['field' => 'a', 'op' => 'gt', 'value' => 1],
			['field' => 'a', 'op' => 'eq'],
			['field' => 'a', 'op' => 'eq', 'value' => ['x']],
			['field' => 'a', 'op' => 'in', 'value' => []],
			['field' => 'a', 'op' => 'in', 'value' => 'x'],
			['field' => 'a', 'op' => 'in', 'value' => [['x']]],
			['field' => 'a', 'op' => 'in', 'value' => ['k' => 'x']],
		];
		foreach ($bad as $when) {
			$this->assertSame([], $keys->action(['when' => $when]));
		}
	}//end testActionWhen()
}//end class
