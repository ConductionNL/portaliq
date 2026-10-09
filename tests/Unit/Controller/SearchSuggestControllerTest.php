<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\SearchSuggestController;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Search\PublicPublicationSearch;
use OCA\Portaliq\Service\Search\SpellingSuggester;
use OCA\Portaliq\Service\Search\SuggestionWordList;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The suggest route is public, rate limited, and answers the suggestion and
 * its count (search-sort-by-relevance REQ-SSR-005).
 *
 * @covers \OCA\Portaliq\Controller\SearchSuggestController
 * @uses   \OCA\Portaliq\Service\Search\SpellingSuggester
 *
 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
 */
class SearchSuggestControllerTest extends TestCase {

	public function testTheRouteAnswersSuggestionAndCount(): void {
		$routes = require __DIR__.'/../../../appinfo/routes.php';
		$named  = [];
		foreach ((array)($routes['routes'] ?? []) as $route) {
			$named[(string)$route['name']] = (string)$route['verb'].' '.(string)$route['url'];
		}

		$this->assertSame('GET /api/site/search/suggest', ($named['searchSuggest#suggest'] ?? ''));
		$method = new ReflectionMethod(SearchSuggestController::class, 'suggest');
		$this->assertNotEmpty($method->getAttributes(PublicPage::class));
		$this->assertNotEmpty($method->getAttributes(NoCSRFRequired::class));
		$this->assertNotEmpty($method->getAttributes(AnonRateLimit::class));

		$response = $this->controller(portal: ['slug' => 'gemeente'])->suggest(portal: 'gemeente');
		$this->assertSame(['suggestion' => 'hondenbelasting', 'results' => 4], $response->getData());
	}//end testTheRouteAnswersSuggestionAndCount()

	public function testAnUnknownPortalGetsNothing(): void {
		$response = $this->controller(portal: null)->suggest(portal: 'nergens');

		$this->assertSame(['suggestion' => null, 'results' => 0], $response->getData());
	}//end testAnUnknownPortalGetsNothing()

	/**
	 * The controller over the real suggester.
	 *
	 * @param array<string, mixed>|null $portal The resolved portal.
	 *
	 * @return SearchSuggestController
	 */
	private function controller(?array $portal): SearchSuggestController {
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolve')->willReturn($portal);
		$list = $this->getMockBuilder(SuggestionWordList::class)->disableOriginalConstructor()->onlyMethods(['words'])->getMock();
		$list->method('words')->willReturn(['hondenbelasting' => 2]);
		$search = $this->getMockBuilder(PublicPublicationSearch::class)->disableOriginalConstructor()->onlyMethods(['count'])->getMock();
		$search->method('count')->willReturn(4);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([['q', '', 'hondenbelasing']]);

		return new SearchSuggestController($request, $portals, new SpellingSuggester($list, $search));
	}//end controller()
}//end class
