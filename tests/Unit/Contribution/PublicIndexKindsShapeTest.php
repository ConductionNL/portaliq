<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PublicIndexKinds;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What an app declares for the public index is reduced to bounded, plain
 * text; malformed kinds, filters and columns are dropped.
 */
#[CoversClass(PublicIndexKinds::class)]
class PublicIndexKindsShapeTest extends TestCase {

	public function testANonArrayAnswerHasNoKinds(): void {
		$this->assertSame([], (new PublicIndexKinds())->kinds('portaliq', 'decisions'));
	}//end testANonArrayAnswerHasNoKinds()

	public function testKindsAreValidatedDeduplicatedAndShaped(): void {
		$out = (new PublicIndexKinds())->kinds(
			'decidiq',
			[
				'loose',
				['type' => 'Bad'],
				[
					'type'       => 'decision',
					'label'      => ' <b>Besluiten</b>  van   de raad ',
					'categories' => ['Wonen', 'Wonen', 12, null, '  '],
					'filters'    => ['Jaar' => [2025, '2026'], 'Leeg' => [], 3 => 'not-a-list'],
					'columns'    => [['key' => 'title', 'label' => 'Titel'], ['key' => 'Bad', 'label' => 'X'], ['key' => 'date'], 'loose'],
				],
				['type' => 'decision', 'label' => 'Dubbel'],
				['type' => 'meeting'],
			]
		);

		$this->assertSame(
			[
				[
					'app'        => 'decidiq',
					'type'       => 'decision',
					'label'      => 'Besluiten van de raad',
					'categories' => ['Wonen', '12'],
					'filters'    => ['Jaar' => ['2025', '2026']],
					'columns'    => [['key' => 'title', 'label' => 'Titel']],
				],
				[
					'app'        => 'decidiq',
					'type'       => 'meeting',
					'label'      => 'meeting',
					'categories' => [],
					'filters'    => [],
					'columns'    => [],
				],
			],
			$out
		);
	}//end testKindsAreValidatedDeduplicatedAndShaped()

	public function testTextIsCutAtOneHundredTwentyCharacters(): void {
		$out = (new PublicIndexKinds())->kinds('a', [['type' => 'k', 'label' => str_repeat('é', 130)]]);
		$this->assertSame(str_repeat('é', 120), $out[0]['label']);
	}//end testTextIsCutAtOneHundredTwentyCharacters()

	public function testVisitorValuesAreFiltersAndIgnoreANonArray(): void {
		$kinds = new PublicIndexKinds();
		$this->assertSame([], $kinds->visitorValues('x'));
		$this->assertSame(['Wijk' => ['Noord']], $kinds->visitorValues(['Wijk' => ['Noord', ''], '' => ['x']]));
	}//end testVisitorValuesAreFiltersAndIgnoreANonArray()
}//end class
