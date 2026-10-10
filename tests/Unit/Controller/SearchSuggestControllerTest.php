<?php

/**
 * Unit tests for the public "did you mean" endpoint.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/search-suggestions-while-typing/tasks.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\SearchSuggestController;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Search\SpellingSuggester;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The portal is resolved from the request, and an unknown one suggests nothing.
 *
 * @covers \OCA\Portaliq\Controller\SearchSuggestController
 */
class SearchSuggestControllerTest extends TestCase {
	/**
	 * @return void
	 */
	public function testAnUnknownPortalSuggestsNothingAndNeverSearches(): void {
		$request = $this->createMock(IRequest::class);
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolve')->willReturn(null);
		$suggester = $this->createMock(SpellingSuggester::class);
		$suggester->expects($this->never())->method('suggest');

		$response = (new SearchSuggestController($request, $portals, $suggester))->suggest();

		$this->assertSame(['suggestion' => null, 'results' => 0], $response->getData());
	}//end testAnUnknownPortalSuggestsNothingAndNeverSearches()

	/**
	 * @return void
	 */
	public function testTheNamedPortalAndTheQueryReachTheSuggester(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->with('q', '')->willReturn('afvall');
		$portals = $this->createMock(PortalResolver::class);
		$portals->expects($this->once())->method('resolve')->with($request, 'de-stad')->willReturn(['slug' => 'de-stad']);
		$suggester = $this->createMock(SpellingSuggester::class);
		$suggester->expects($this->once())->method('suggest')->with('de-stad', 'afvall')->willReturn(['suggestion' => 'afval', 'results' => 4]);

		$response = (new SearchSuggestController($request, $portals, $suggester))->suggest(portal: 'de-stad');

		$this->assertSame(['suggestion' => 'afval', 'results' => 4], $response->getData());
	}//end testTheNamedPortalAndTheQueryReachTheSuggester()
}//end class
