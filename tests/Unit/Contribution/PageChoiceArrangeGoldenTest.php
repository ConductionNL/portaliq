<?php

/**
 * Golden outcomes of arranging an aggregate's pages by a portal's choice.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-portal-shows-the-pages-its-administrator-chose-in-the-chosen-order-req-pgc-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PageChoice;
use PHPUnit\Framework\TestCase;

/**
 * Page and contribution order, hidden pages and the untouched parts.
 */
class PageChoiceArrangeGoldenTest extends TestCase {
	/**
	 * Three contributions: two with pages, one with collections only.
	 *
	 * @return array<string, mixed>
	 */
	private static function aggregate(): array {
		return [
			'audience'      => 'resident',
			'contributions' => [
				['app' => 'alpha', 'pages' => [['id' => 'a1'], ['id' => 'a2'], ['id' => 'a3']], 'collections' => [['id' => 'c']]],
				['app' => 'beta', 'pages' => [['id' => 'b1'], ['id' => 'b2']]],
				['app' => 'gamma', 'collections' => [['id' => 'g']]],
				['app' => 'delta', 'pages' => [['id' => 'd1']]],
			],
		];
	}//end aggregate()

	/**
	 * The page ids per app, in order, of an arranged aggregate.
	 *
	 * @param array<string, mixed> $aggregate The aggregate.
	 *
	 * @return array<string, array<int, string>|null>
	 */
	private static function shape(array $aggregate): array {
		$out = [];
		foreach ($aggregate['contributions'] as $contribution) {
			$out[$contribution['app']] = isset($contribution['pages']) ? array_column($contribution['pages'], 'id') : null;
		}

		return $out;
	}//end shape()

	/**
	 * The recorded arrangement per navigation.
	 *
	 * @return array<string, array{0: mixed, 1: array<string, array<int, string>|null>}>
	 */
	public static function choices(): array {
		return [
			'orderAcrossApps' => [
				[['page' => 'beta:b2'], ['page' => 'alpha:a3'], ['page' => 'beta:b1']],
				['beta' => ['b2', 'b1'], 'alpha' => ['a3', 'a1', 'a2'], 'gamma' => null, 'delta' => ['d1']],
			],
			'hiddenAndOrdered' => [
				[['page' => 'alpha:a2', 'hidden' => true], ['page' => 'delta:d1'], ['page' => 'alpha:a1']],
				['delta' => ['d1'], 'alpha' => ['a1', 'a3'], 'beta' => ['b1', 'b2'], 'gamma' => null],
			],
			'allHiddenGoesLast' => [
				[['page' => 'delta:d1', 'hidden' => true], ['page' => 'beta:b1']],
				['beta' => ['b1', 'b2'], 'alpha' => ['a1', 'a2', 'a3'], 'gamma' => null, 'delta' => []],
			],
			'hiddenNotTrueStays' => [
				[['page' => 'alpha:a1', 'hidden' => 'yes'], ['page' => 'alpha:a1', 'hidden' => true]],
				['alpha' => ['a1', 'a2', 'a3'], 'beta' => ['b1', 'b2'], 'gamma' => null, 'delta' => ['d1']],
			],
		];
	}//end choices()

	/**
	 * Each navigation arranges the aggregate as recorded, and leaves the rest of
	 * it as it was.
	 *
	 * @return void
	 */
	public function testEachChoiceArrangesAsRecorded(): void {
		foreach (self::choices() as $name => [$navigation, $expected]) {
			$out = (new PageChoice())->arrange(self::aggregate(), $navigation);
			$this->assertSame($expected, self::shape($out), $name);
			$this->assertSame('resident', $out['audience'], $name);
			$this->assertSame(array_keys($out['contributions']), range(0, 3), $name);
			foreach ($out['contributions'] as $contribution) {
				if ($contribution['app'] === 'alpha') {
					$this->assertSame([['id' => 'c']], $contribution['collections'], $name);
				}

				if ($contribution['app'] === 'gamma') {
					$this->assertSame(['app' => 'gamma', 'collections' => [['id' => 'g']]], $contribution, $name);
				}
			}
		}
	}//end testEachChoiceArrangesAsRecorded()

	/**
	 * An aggregate without contributions is returned as it came.
	 *
	 * @return void
	 */
	public function testAnAggregateWithoutContributionsIsUntouched(): void {
		$this->assertSame(['audience' => 'x'], (new PageChoice())->arrange(['audience' => 'x'], [['page' => 'alpha:a1']]));
		$this->assertSame(['contributions' => 'junk'], (new PageChoice())->arrange(['contributions' => 'junk'], [['page' => 'alpha:a1']]));
	}//end testAnAggregateWithoutContributionsIsUntouched()
}//end class
