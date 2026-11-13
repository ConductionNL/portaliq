<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Theme;

use OCA\Portaliq\Service\PortalThemeResolver;
use OCA\Portaliq\Service\Theme\PortalThemeParents;
use PHPUnit\Framework\TestCase;

/**
 * A theme that extends another loads its ancestors' stylesheets first,
 * root-most first, at most four hops up and never round a cycle.
 *
 * @covers \OCA\Portaliq\Service\Theme\PortalThemeParents
 */
class PortalThemeParentsTest extends TestCase {

	/**
	 * Parents over a theme catalogue whose every theme has a stylesheet, except "nosheet".
	 *
	 * @param array<int, array<string, mixed>> $catalogue The theme catalogue.
	 *
	 * @return PortalThemeParents
	 */
	private function parents(array $catalogue): PortalThemeParents {
		$resolver = $this->createMock(PortalThemeResolver::class);
		$resolver->method('catalogue')->willReturn($catalogue);
		$resolver->method('stylesheetFor')->willReturnCallback(
			static fn (string $theme): ?string => ($theme === 'nosheet') ? null : '/css/' . $theme . '.css'
		);

		return new PortalThemeParents($resolver);
	}//end parents()

	public function testAncestorsLoadRootMostFirstAndTheThemeItselfIsNotIncluded(): void {
		$parents = $this->parents([
			['id' => 'wijk', 'extends' => 'gemeente'],
			['id' => 'gemeente', 'extends' => 'rijk'],
			['id' => 'rijk'],
		]);

		$this->assertSame(['/css/rijk.css', '/css/gemeente.css'], $parents->stylesheetsFor('wijk'));
		$this->assertSame([], $parents->stylesheetsFor('rijk'));
		$this->assertSame([], $parents->stylesheetsFor('unknown'));
	}

	public function testAtMostFourAncestorsAreFollowed(): void {
		$parents = $this->parents([
			['id' => 't0', 'extends' => 't1'],
			['id' => 't1', 'extends' => 't2'],
			['id' => 't2', 'extends' => 't3'],
			['id' => 't3', 'extends' => 't4'],
			['id' => 't4', 'extends' => 't5'],
			['id' => 't5'],
		]);

		$this->assertSame(['/css/t4.css', '/css/t3.css', '/css/t2.css', '/css/t1.css'], $parents->stylesheetsFor('t0'));
	}

	public function testACycleStopsAtTheFirstThemeSeenAgain(): void {
		$parents = $this->parents([
			['id' => 'a', 'extends' => 'b'],
			['id' => 'b', 'extends' => 'a'],
		]);

		$this->assertSame(['/css/b.css'], $parents->stylesheetsFor('a'));
	}

	public function testAnAncestorWithoutAStylesheetIsSkipped(): void {
		$parents = $this->parents([
			['id' => 'wijk', 'extends' => 'nosheet'],
			['id' => 'nosheet', 'extends' => 'rijk'],
			['id' => 'rijk', 'extends' => ''],
		]);

		$this->assertSame(['/css/rijk.css'], $parents->stylesheetsFor('wijk'));
	}
}
