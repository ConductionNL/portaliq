<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\Cms\FormPlacementHelp;
use OCA\Portaliq\Service\Cms\PortalHelp;
use OCA\Portaliq\Service\CmsRows;
use OCA\Portaliq\Service\CmsSharedBlocks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Expanding shared-block widgets into the published block's own widgets.
 */
#[CoversClass(CmsSharedBlocks::class)]
#[UsesClass(FormPlacementHelp::class)]
#[UsesClass(PortalHelp::class)]
class CmsSharedBlocksTest extends TestCase {
	/**
	 * Raw widgets are normalised and ordered by row then column.
	 *
	 * @return void
	 */
	public function testShapeWidgets(): void {
		$shared = new CmsSharedBlocks($this->createMock(CmsRows::class));

		$out = $shared->shapeWidgets([
			['id' => 'b', 'widgetKey' => 'text', 'gridY' => '2', 'gridX' => 1, 'props' => ['a' => 1]],
			'junk',
			['id' => 'a', 'widgetKey' => 'hero', 'gridY' => 0],
		]);

		$this->assertSame(['a', 'b'], array_column($out, 'id'));
		$this->assertSame(['slot' => 'body', 'gridX' => 0, 'gridY' => 0, 'gridWidth' => 12, 'gridHeight' => 4, 'props' => []], array_diff_key($out[0], ['id' => 1, 'widgetKey' => 1]));
		$this->assertSame(2, $out[1]['gridY']);
		$this->assertSame(['a' => 1], $out[1]['props']);
	}//end testShapeWidgets()

	/**
	 * A shared block is replaced by its published widgets, unavailable otherwise.
	 *
	 * @return void
	 */
	public function testExpand(): void {
		$rows = $this->createMock(CmsRows::class);
		$rows->method('query')->willReturn([
			['id' => 'good', 'status' => 'published', 'organisation' => 'zuid', 'widgets' => [['id' => 'w1', 'widgetKey' => 'text'], ['id' => 'w2', 'widgetKey' => 'sharedBlock']]],
			['id' => 'draft', 'status' => 'draft', 'organisation' => 'zuid'],
			['status' => 'published', 'organisation' => 'zuid'],
			['id' => 'foreign', 'status' => 'published', 'organisation' => 'other'],
		]);
		$rows->method('rowId')->willReturnCallback(static fn (array $row): ?string => ($row['id'] ?? null));
		$shared = new CmsSharedBlocks($rows);

		$widgets = [
			['widgetKey' => 'text', 'props' => []],
			['widgetKey' => 'sharedBlock', 'props' => ['block' => ' good ']],
			['widgetKey' => 'sharedBlock', 'props' => ['block' => 'draft']],
			['widgetKey' => 'sharedBlock', 'props' => ['block' => 'foreign']],
			['widgetKey' => 'sharedBlock', 'props' => []],
		];

		$out = $shared->expand($widgets, 'zuid');

		$this->assertSame($widgets[0], $out[0]);
		$this->assertFalse($out[1]['props']['unavailable']);
		$this->assertSame('good', $out[1]['props']['block']);
		$this->assertSame(['w1'], array_column($out[1]['props']['widgets'], 'id'));
		foreach ([2, 3, 4] as $index) {
			$this->assertTrue($out[$index]['props']['unavailable']);
			$this->assertSame([], $out[$index]['props']['widgets']);
		}
	}//end testExpand()

	/**
	 * Without an organisation nothing is looked up and every block is unavailable.
	 *
	 * @return void
	 */
	public function testExpandWithoutOrganisation(): void {
		$rows = $this->createMock(CmsRows::class);
		$rows->expects($this->never())->method('query');

		$out = (new CmsSharedBlocks($rows))->expand([['widgetKey' => 'sharedBlock', 'props' => ['block' => 'x']]], '');

		$this->assertTrue($out[0]['props']['unavailable']);
	}//end testExpandWithoutOrganisation()

	/**
	 * A form placement leaves with its stored form's help, read for the serving portal.
	 *
	 * @return void
	 */
	public function testExpandHandsAFormPlacementItsHelp(): void {
		$rows = $this->createMock(CmsRows::class);
		$rows->expects($this->once())
			->method('query')
			->with('form', ['portal' => 'zuiddrecht'])
			->willReturn([['id' => 'f-1', 'portal' => 'zuiddrecht', 'help' => ['phone' => '14 021']]]);

		$out = (new CmsSharedBlocks($rows))->expand([['widgetKey' => 'form', 'props' => ['formId' => 'f-1']]], 'gemeente', 'zuiddrecht');

		$this->assertSame(['phone' => '14 021'], $out[0]['props']['formHelp']);
	}//end testExpandHandsAFormPlacementItsHelp()
}//end class
