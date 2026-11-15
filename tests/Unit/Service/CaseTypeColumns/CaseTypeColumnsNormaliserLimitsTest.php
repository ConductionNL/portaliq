<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\CaseTypeColumns;

use OCA\Portaliq\Service\CaseTypeColumns\CaseTypeColumnsNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * The limits of a case type's column settings: how many columns are kept,
 * how long a field name and a link text may be, and which entries are
 * dropped as duplicates or unusable.
 *
 * @covers \OCA\Portaliq\Service\CaseTypeColumns\CaseTypeColumnsNormaliser
 *
 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
 */
class CaseTypeColumnsNormaliserLimitsTest extends TestCase {
	public function testAtMostThirtyColumnsAreKeptInTheGivenOrder(): void {
		$columns = [];
		for ($i = 1; $i <= 35; $i++) {
			$columns[] = ['field' => 'veld' . $i, 'render' => 'text'];
		}

		$clean = (new CaseTypeColumnsNormaliser())->normalise(columns: $columns);

		$this->assertCount(30, $clean);
		$this->assertSame(['field' => 'veld1', 'render' => 'text'], $clean[0]);
		$this->assertSame(['field' => 'veld30', 'render' => 'text'], $clean[29]);
	}//end testAtMostThirtyColumnsAreKeptInTheGivenOrder()

	public function testDuplicatesDoNotCountTowardsTheLimit(): void {
		$columns = [['field' => 'status', 'render' => 'text'], ['field' => 'status', 'render' => 'date']];
		for ($i = 1; $i <= 29; $i++) {
			$columns[] = ['field' => 'veld' . $i, 'render' => 'text'];
		}

		$clean = (new CaseTypeColumnsNormaliser())->normalise(columns: $columns);

		$this->assertCount(30, $clean);
		$this->assertSame(['field' => 'status', 'render' => 'text'], $clean[0]);
		$this->assertSame(['field' => 'veld29', 'render' => 'text'], $clean[29]);
	}//end testDuplicatesDoNotCountTowardsTheLimit()

	public function testAFieldIsTrimmedAndTheFirstOfTwoTrimmedEqualFieldsWins(): void {
		$clean = (new CaseTypeColumnsNormaliser())->normalise(
			columns: [
				'a' => ['field' => '  zaak.status ', 'render' => 'text'],
				'b' => ['field' => 'zaak.status', 'render' => 'link', 'linkLabel' => 'Open'],
			]
		);

		$this->assertSame([['field' => 'zaak.status', 'render' => 'text']], $clean);
	}//end testAFieldIsTrimmedAndTheFirstOfTwoTrimmedEqualFieldsWins()

	public function testAFieldNameMayBeSixtyFourCharactersButNotMore(): void {
		$sixtyFour = str_repeat('a', 64);
		$clean = (new CaseTypeColumnsNormaliser())->normalise(
			columns: [
				['field' => str_repeat('b', 65), 'render' => 'text'],
				['field' => $sixtyFour, 'render' => 'text'],
				['field' => 'Zaak-nummer_1.x', 'render' => 'date'],
				['field' => 'zaak/nummer', 'render' => 'text'],
			]
		);

		$this->assertSame([['field' => $sixtyFour, 'render' => 'text'], ['field' => 'Zaak-nummer_1.x', 'render' => 'date']], $clean);
	}//end testAFieldNameMayBeSixtyFourCharactersButNotMore()

	public function testARenderIsMatchedExactly(): void {
		$clean = (new CaseTypeColumnsNormaliser())->normalise(
			columns: [
				['field' => 'a', 'render' => 'Text'],
				['field' => 'b', 'render' => ' link'],
				['field' => 'c'],
				['field' => 'd', 'render' => 'qr'],
			]
		);

		$this->assertSame([['field' => 'd', 'render' => 'qr']], $clean);
	}//end testARenderIsMatchedExactly()

	public function testALinkTextOfSixtyCharactersIsKeptAndAnEmptyOneIsLeftOut(): void {
		$sixty = str_repeat('é', 60);
		$clean = (new CaseTypeColumnsNormaliser())->normalise(
			columns: [
				['field' => 'website', 'render' => 'link', 'linkLabel' => $sixty],
				['field' => 'bewijs', 'render' => 'qr', 'linkLabel' => '   '],
				['field' => 'kaart', 'render' => 'link'],
			]
		);

		$this->assertSame(
			[
				['field' => 'website', 'render' => 'link', 'linkLabel' => $sixty],
				['field' => 'bewijs', 'render' => 'qr'],
				['field' => 'kaart', 'render' => 'link'],
			],
			$clean
		);
	}//end testALinkTextOfSixtyCharactersIsKeptAndAnEmptyOneIsLeftOut()

	public function testByTypeSkipsAnEmptyTypeIdAndAPortalWithoutColumns(): void {
		$normaliser = new CaseTypeColumnsNormaliser();

		$this->assertSame(
			['7' => [['field' => 'status', 'render' => 'text']]],
			$normaliser->byType(
				portal: ['caseTypeColumns' => ['' => [['field' => 'x', 'render' => 'text']], 7 => [['field' => 'status', 'render' => 'text']]]]
			)
		);
		$this->assertSame([], $normaliser->byType(portal: ['slug' => 'mijn']));
		$this->assertSame([], $normaliser->byType(portal: ['caseTypeColumns' => ['vergunning' => 'not a list']]));
	}//end testByTypeSkipsAnEmptyTypeIdAndAPortalWithoutColumns()
}//end class
