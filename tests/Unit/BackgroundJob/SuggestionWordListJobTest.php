<?php

/**
 * Unit tests for the daily job that rebuilds the search word lists.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/search-suggestions-while-typing/tasks.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\BackgroundJob;

use OCA\Portaliq\BackgroundJob\SuggestionWordListJob;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Search\SuggestionWordList;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * One rebuild per published portal; a failing portal does not stop the rest.
 *
 * @covers \OCA\Portaliq\BackgroundJob\SuggestionWordListJob
 */
class SuggestionWordListJobTest extends TestCase {
	/**
	 * @return void
	 */
	public function testEveryPublishedPortalIsRebuiltAndAFailureIsLogged(): void {
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('allPublishedPortals')->willReturn([['slug' => 'a'], ['slug' => ''], ['slug' => 'b'], ['slug' => 'c']]);
		$seen = [];
		$list = $this->createMock(SuggestionWordList::class);
		$list->method('rebuild')->willReturnCallback(
			function (string $portal) use (&$seen): int {
				$seen[] = $portal;
				if ($portal === 'b') {
					throw new RuntimeException('boom');
				}

				return 1;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$job = new SuggestionWordListJob($this->createMock(ITimeFactory::class), $portals, $list, $logger);

		$run = new \ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);

		$this->assertSame(['a', 'b', 'c'], $seen);
		$this->assertSame(SuggestionWordListJob::INTERVAL, $job->getInterval());
	}//end testEveryPublishedPortalIsRebuiltAndAFailureIsLogged()
}//end class
