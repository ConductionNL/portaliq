<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\StepsHighlightKeys;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Sanitising the highlight keys of a `steps` block.
 */
#[CoversClass(StepsHighlightKeys::class)]
class StepsHighlightKeysTest extends TestCase {
	/**
	 * Every block keeps exactly the highlight keys recorded for it.
	 *
	 * @return void
	 */
	public function testEveryBlockIsAsRecorded(): void {
		$keys  = new StepsHighlightKeys();
		$pages = ['home', 'case'];
		$long  = str_repeat('é', 81);
		$cases = [
			[['display' => 'list', 'eyebrow' => 'Now'], []],
			[[], []],
			[['display' => 'highlight'], ['display' => 'highlight']],
			[
				['display' => 'highlight', 'eyebrow' => ' Now ', 'buttonLabel' => ' Go ', 'page' => 'case', 'withRecord' => true],
				['display' => 'highlight', 'eyebrow' => 'Now', 'buttonLabel' => 'Go', 'page' => 'case', 'withRecord' => true],
			],
			[
				['display' => 'highlight', 'buttonLabel' => 'Go', 'page' => 'home', 'withRecord' => 1],
				['display' => 'highlight', 'buttonLabel' => 'Go', 'page' => 'home'],
			],
			[['display' => 'highlight', 'buttonLabel' => 'Go', 'page' => 'gone', 'withRecord' => true], ['display' => 'highlight', 'buttonLabel' => 'Go']],
			[['display' => 'highlight', 'buttonLabel' => 'Go', 'page' => 5], ['display' => 'highlight', 'buttonLabel' => 'Go']],
			[['display' => 'highlight', 'eyebrow' => 'Now', 'page' => 'case', 'withRecord' => true], ['display' => 'highlight', 'eyebrow' => 'Now']],
			[['display' => 'highlight', 'eyebrow' => '  ', 'buttonLabel' => $long, 'page' => 'case'], ['display' => 'highlight']],
			[['display' => 'highlight', 'eyebrow' => str_repeat('é', 80), 'buttonLabel' => 7], ['display' => 'highlight', 'eyebrow' => str_repeat('é', 80)]],
		];
		foreach ($cases as [$block, $expected]) {
			$this->assertSame($expected, $keys->keys(block: $block, pageIds: $pages));
		}
	}//end testEveryBlockIsAsRecorded()
}//end class
