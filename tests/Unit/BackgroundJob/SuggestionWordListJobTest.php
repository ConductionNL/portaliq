<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\BackgroundJob;

use OCA\Portaliq\BackgroundJob\SuggestionWordListJob;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Search\SuggestionWordList;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * search-sort-by-relevance REQ-SSR-005: the word list is rebuilt daily for
 * every published portal.
 *
 * @spec openspec/changes/search-sort-by-relevance/tasks.md#t08
 */
class SuggestionWordListJobTest extends TestCase {

	public function testEveryPublishedPortalGetsTheListBuiltOnce(): void {
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('allPublishedPortals')->willReturn(
			[['slug' => 'zuiddrecht'], ['slug' => 'noord'], ['slug' => ''], ['title' => 'no slug'], 'not a portal', ['slug' => 'zuiddrecht']]
		);

		$stored = [];
		$words  = $this->createMock(SuggestionWordList::class);
		$words->expects($this->once())->method('build')->willReturn(['parkeren' => 2]);
		$words->method('store')->willReturnCallback(
			static function (string $portal, array $list) use (&$stored): void {
				$stored[$portal] = $list;
			}
		);

		$job = new SuggestionWordListJob($this->createMock(ITimeFactory::class), $portals, $words);

		$this->assertSame(2, $job->rebuildAll());
		$this->assertSame(['zuiddrecht' => ['parkeren' => 2], 'noord' => ['parkeren' => 2]], $stored);
	}

	public function testNoPortalReadsNothing(): void {
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('allPublishedPortals')->willReturn([]);
		$words = $this->createMock(SuggestionWordList::class);
		$words->expects($this->never())->method('build');

		$job = new SuggestionWordListJob($this->createMock(ITimeFactory::class), $portals, $words);

		$this->assertSame(0, $job->rebuildAll());
	}

	public function testTheJobRunsDaily(): void {
		$job = new SuggestionWordListJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(PortalResolver::class),
			$this->createMock(SuggestionWordList::class)
		);

		$interval = (new \ReflectionProperty($job, 'interval'))->getValue($job);
		$this->assertSame(86400, $interval);
	}
}
