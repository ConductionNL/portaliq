<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PublicIndexText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Text clean-up for the public index declaration.
 */
#[CoversClass(PublicIndexText::class)]
class PublicIndexTextTest extends TestCase {
	/**
	 * Text is stripped, trimmed and ellipsised at the limit.
	 *
	 * @return void
	 */
	public function testTextAndShort(): void {
		$t = new PublicIndexText();

		$this->assertNull($t->text(5, 10));
		$this->assertNull($t->text('  <b></b> ', 10));
		$this->assertSame('hi', $t->text(' <b>hi</b> ', 10));
		$this->assertSame('abcd…', $t->text('abcdefghij', 5));

		$this->assertSame('12', $t->short(12));
		$this->assertNull($t->short(1.5));
		$this->assertSame('a b c', $t->short("a  b\n c"));
		$this->assertSame(200, mb_strlen((string)$t->short(str_repeat('x', 300))));
	}//end testTextAndShort()

	/**
	 * Meta is a capped list of short strings.
	 *
	 * @return void
	 */
	public function testMeta(): void {
		$t = new PublicIndexText();

		$this->assertSame(['a', 'b'], $t->meta(['a', [], 'b', null]));
		$this->assertSame(6, count($t->meta(range(1, 20))));
		$this->assertSame(['x'], $t->meta('x'));
	}//end testMeta()

	/**
	 * Facets map a label to unique values, capped.
	 *
	 * @return void
	 */
	public function testFacets(): void {
		$t = new PublicIndexText();

		$this->assertSame([], $t->facets('nope'));
		$this->assertSame(
			['Colour' => ['red', 'blue'], 'Size' => ['m']],
			$t->facets(['Colour' => ['red', 'blue', 'red'], 'Empty' => [], '' => ['x'], 'Size' => 'm'])
		);

		$many = [];
		for ($i = 0; $i < 9; $i++) {
			$many['L' . $i] = ['v'];
		}

		$this->assertCount(6, $t->facets($many));
	}//end testFacets()

	/**
	 * Cells need a lowerCamel key and a short text, and are capped.
	 *
	 * @return void
	 */
	public function testCells(): void {
		$t = new PublicIndexText();

		$this->assertSame([], $t->cells('x'));
		$this->assertSame(['price' => '5', 'nameX' => 'n'], $t->cells(['price' => 5, 'Bad' => 'x', 'nameX' => 'n', 3 => 'y', 'empty' => '', 'long-key' => 'z']));

		$many = [];
		foreach (range('a', 'p') as $letter) {
			$many[$letter] = 'v';
		}

		$this->assertCount(12, $t->cells($many));
	}//end testCells()
}//end class
