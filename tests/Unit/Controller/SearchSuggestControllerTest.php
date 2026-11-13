<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\SearchSuggestController;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Search\AnonymousPublicationSearch;
use OCA\Portaliq\Service\Search\SpellingSuggester;
use OCA\Portaliq\Service\Search\SuggestionWordList;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * search-sort-by-relevance REQ-SSR-005: the public suggest route.
 *
 * @spec openspec/changes/search-sort-by-relevance/tasks.md#t09
 */
class SearchSuggestControllerTest extends TestCase {

	/**
	 * The slug the resolver was asked for.
	 *
	 * @var list<string|null>
	 */
	private array $asked = [];

	/**
	 * A controller over a real suggester, a word list and a search double.
	 *
	 * @param array<string, mixed>|null $site   The resolved portal.
	 * @param array<string, int>        $totals Total per searched term.
	 *
	 * @return SearchSuggestController
	 */
	private function controller(?array $site, array $totals=['hondenbelasting' => 3]): SearchSuggestController {
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolve')->willReturnCallback(
			function (IRequest $request, ?string $portalSlug=null) use ($site): ?array {
				$this->asked[] = $portalSlug;
				return $site;
			}
		);

		$words = $this->createMock(SuggestionWordList::class);
		$words->method('load')->willReturn(['hondenbelasting' => 2]);
		$search = $this->createMock(AnonymousPublicationSearch::class);
		$search->method('search')->willReturnCallback(
			static fn (string $term, int $page=1, int $limit=100): array => ['results' => [], 'total' => ($totals[$term] ?? 0)]
		);

		return new SearchSuggestController(
			$this->createMock(IRequest::class),
			$portals,
			new SpellingSuggester($words, $search)
		);
	}

	public function testTheRouteAnswersSuggestionAndCount(): void {
		$response = $this->controller(site: ['slug' => 'zuiddrecht'])->suggest(portal: 'zuiddrecht', q: 'hondenbelasing');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['suggestion' => 'hondenbelasting', 'results' => 3], $response->getData());
		$this->assertSame(['zuiddrecht'], $this->asked);
	}

	public function testACorrectionThatFindsNothingAnswersNull(): void {
		$response = $this->controller(site: ['slug' => 'zuiddrecht'], totals: [])->suggest(portal: 'zuiddrecht', q: 'hondenbelasing');

		$this->assertSame(['suggestion' => null, 'results' => 0], $response->getData());
	}

	public function testAnUnknownPortalIsNotFound(): void {
		$response = $this->controller(site: null)->suggest(portal: 'nergens', q: 'hondenbelasing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['suggestion' => null, 'results' => 0], $response->getData());
	}

	public function testAnEmptyPortalLetsTheHostDecide(): void {
		$this->controller(site: ['slug' => 'zuiddrecht'])->suggest(q: 'hondenbelasing');

		$this->assertSame([null], $this->asked);
	}

	public function testAnEmptyOrOverlongTermAnswersNull(): void {
		$controller = $this->controller(site: ['slug' => 'zuiddrecht']);

		$this->assertSame(['suggestion' => null, 'results' => 0], $controller->suggest(portal: 'zuiddrecht', q: '  ')->getData());
		$this->assertSame(['suggestion' => null, 'results' => 0], $controller->suggest(portal: 'zuiddrecht', q: str_repeat('a', 201))->getData());
	}

	public function testTheRouteIsPublicAndRateLimited(): void {
		$method = new ReflectionMethod(SearchSuggestController::class, 'suggest');

		$this->assertCount(1, $method->getAttributes(PublicPage::class));
		$this->assertCount(1, $method->getAttributes(NoCSRFRequired::class));
		$limits = $method->getAttributes(AnonRateLimit::class);
		$this->assertCount(1, $limits);
		$this->assertSame(['limit' => 30, 'period' => 60], $limits[0]->getArguments());
	}
}
