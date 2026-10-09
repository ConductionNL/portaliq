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
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * The daily job rebuilds every published portal's list, and is registered
 * (search-sort-by-relevance REQ-SSR-005).
 *
 * @covers \OCA\Portaliq\BackgroundJob\SuggestionWordListJob
 *
 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
 */
class SuggestionWordListJobTest extends TestCase {

	public function testEveryPublishedPortalIsRebuiltAndOneFailureStopsNothing(): void {
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('allPublishedPortals')->willReturn([['slug' => 'a'], ['slug' => ''], ['slug' => 'b'], ['slug' => 'c']]);

		$built = [];
		$list  = $this->getMockBuilder(SuggestionWordList::class)->disableOriginalConstructor()->onlyMethods(['rebuild'])->getMock();
		$list->method('rebuild')->willReturnCallback(
			static function (string $portal) use (&$built): int {
				$built[] = $portal;
				if ($portal === 'b') {
					throw new \RuntimeException('down');
				}

				return 1;
			}
		);

		$job = new SuggestionWordListJob($this->createMock(ITimeFactory::class), $portals, $list, new NullLogger());
		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		$this->assertSame(['a', 'b', 'c'], $built);
		$this->assertSame(SuggestionWordListJob::INTERVAL, $job->getInterval());
	}//end testEveryPublishedPortalIsRebuiltAndOneFailureStopsNothing()

	public function testTheJobIsRegistered(): void {
		$info = (string)file_get_contents(__DIR__.'/../../../appinfo/info.xml');

		$this->assertStringContainsString('<job>OCA\Portaliq\BackgroundJob\SuggestionWordListJob</job>', $info);
	}//end testTheJobIsRegistered()
}//end class
