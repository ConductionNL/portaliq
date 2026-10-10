<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PublicRecordShape;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Shaping a declared public record into plain, safe text.
 */
#[CoversClass(PublicRecordShape::class)]
class PublicRecordShapeTest extends TestCase {
	/**
	 * Text coerces numbers, strips tags and ignores everything else.
	 *
	 * @return void
	 */
	public function testText(): void {
		$s = new PublicRecordShape();

		$this->assertSame('5', $s->text(5));
		$this->assertSame('1.5', $s->text(1.5));
		$this->assertSame('hi', $s->text(' <i>hi</i> '));
		$this->assertSame('', $s->text(['x']));
		$this->assertSame('', $s->text(null));
	}//end testText()

	/**
	 * A full record keeps its summary, columns and rows; unsafe links go.
	 *
	 * @return void
	 */
	public function testRecord(): void {
		$record = (new PublicRecordShape())->record([
			'title' => '<b>Budget</b>',
			'subtitle' => 'Sub',
			'note' => '',
			'summary' => [['label' => 'Total', 'value' => 10, 'detail' => 'eur'], ['label' => ''], 'x'],
			'columns' => [['key' => 'name', 'label' => 'Name'], ['key' => 'amt'], ['key' => ''], 3],
			'rows' => [
				['name' => 'A', 'amt' => 1, 'extra' => 'dropped', 'subjectUrl' => '/x'],
				['name' => 'B', 'subjectUrl' => 'javascript:alert(1)'],
				'bad',
			],
		]);

		$this->assertSame('Budget', $record['title']);
		$this->assertSame('Sub', $record['subtitle']);
		$this->assertArrayNotHasKey('note', $record);
		$this->assertSame([['label' => 'Total', 'value' => '10', 'detail' => 'eur']], $record['summary']);
		$this->assertSame([['key' => 'name', 'label' => 'Name'], ['key' => 'amt', 'label' => 'amt']], $record['columns']);
		$this->assertSame(
			[['name' => 'A', 'amt' => '1', 'subjectUrl' => '/x'], ['name' => 'B', 'amt' => '']],
			$record['rows']
		);
	}//end testRecord()

	/**
	 * An empty answer yields an empty shell.
	 *
	 * @return void
	 */
	public function testEmptyRecord(): void {
		$this->assertSame(['title' => '', 'summary' => [], 'columns' => [], 'rows' => []], (new PublicRecordShape())->record([]));
	}//end testEmptyRecord()
}//end class
