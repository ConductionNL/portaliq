<?php

/**
 * ThemeChoice::arrange(): every tag kept or dropped, and the themes
 * announced, as recorded.
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

use OCA\Portaliq\Contribution\ThemeChoice;
use PHPUnit\Framework\TestCase;

/**
 * The theme tags, as recorded.
 */
class ThemeChoiceArrangeGoldenTest extends TestCase {
	/**
	 * Junk is left alone, an entry without a tag is untouched, a tag that is
	 * not a declared slug is dropped, and the announced themes keep their
	 * declared order.
	 *
	 * @return void
	 */
	public function testEveryTagIsKeptOrDroppedAsRecorded(): void {
		$themes    = [['slug' => 'wonen', 'title' => 'Wonen'], ['slug' => 'parkeren', 'title' => 'Parkeren'], ['slug' => 'zorg', 'title' => 'Zorg']];
		$aggregate = [
			'audience'      => 'client',
			'contributions' => [
				'junk',
				[
					'app'         => 'a',
					'collections' => ['x', ['id' => 'c1'], ['id' => 'c2', 'theme' => 7], ['id' => 'c3', 'theme' => 'parkeren']],
					'actions'     => 'none',
				],
				['app' => 'b', 'actions' => [['id' => 'a1', 'theme' => 'Wonen'], ['id' => 'a2', 'theme' => 'wonen']]],
			],
		];

		$this->assertSame(
			[
				'audience'      => 'client',
				'contributions' => [
					'junk',
					['app' => 'a', 'collections' => ['x', ['id' => 'c1'], ['id' => 'c2'], ['id' => 'c3', 'theme' => 'parkeren']], 'actions' => 'none'],
					['app' => 'b', 'actions' => [['id' => 'a1'], ['id' => 'a2', 'theme' => 'wonen']]],
				],
				'themes'        => [['slug' => 'wonen', 'title' => 'Wonen', 'intro' => '', 'productsLabel' => ''], ['slug' => 'parkeren', 'title' => 'Parkeren', 'intro' => '', 'productsLabel' => '']],
			],
			(new ThemeChoice())->arrange(aggregate: $aggregate, themes: $themes)
		);
		$this->assertSame(['contributions' => [], 'themes' => []], (new ThemeChoice())->arrange(aggregate: ['contributions' => 'x'], themes: $themes));
	}//end testEveryTagIsKeptOrDroppedAsRecorded()
}//end class
