<?php

/**
 * A portal's public catalogue: each app's public index held to its shape,
 * gathered with the portal's news, then searched, filtered by facet, sorted
 * and paged; and the endpoint gated like the rest of `/api/content`
 * (portal-public-catalogue).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Contribution\PublicIndexItems;
use OCA\Portaliq\Controller\ContentCatalogueController;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\PublicCatalogue;
use OCA\Portaliq\Service\PublicCatalogueQuery;
use OCA\Portaliq\Service\PublicNewsReader;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md
 */
class PublicCatalogueTest extends TestCase {

	/**
	 * The Warmtepompacademie's courses, as the school app would answer them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function courses(): array {
		return [
			['id' => 'c1', 'type' => 'course', 'kind' => 'Cursus', 'title' => 'F-gassen: herhaling en examen', 'summary' => 'Een dag om je certificaat te verlengen.', 'date' => '2026-10-08', 'meta' => ['1 dag'], 'facets' => ['Plaats' => 'Praktijkhal Zuiddrecht', 'Start in' => ['Oktober 2026']], 'note' => 'Nog 1 plek', 'noteTone' => 'warning'],
			['id' => 'c2', 'type' => 'course', 'kind' => 'Cursus', 'title' => 'Waterzijdig inregelen', 'date' => '2026-10-15', 'facets' => ['Plaats' => 'Praktijkhal Zuiddrecht', 'Start in' => ['Oktober 2026', 'November 2026']]],
			['id' => 'c3', 'type' => 'course', 'kind' => 'Cursus', 'title' => 'Lucht-water warmtepomp: ontwerp en inbedrijfstelling', 'date' => '2026-11-03', 'facets' => ['Plaats' => 'Bij u op de zaak', 'Start in' => ['November 2026']]],
			['id' => 'e1', 'type' => 'event', 'kind' => 'Agenda', 'title' => 'Open dag in de praktijkhal', 'date' => '2026-09-01'],
		];
	}//end courses()

	/**
	 * A catalogue over one app answering `$answer` and the portal's news.
	 *
	 * @param mixed      $answer  The app's public index.
	 * @param array      $news    The portal's public news summaries.
	 * @param array|null $cached  What the cache already holds.
	 *
	 * @return PublicCatalogue
	 */
	private function catalogue(mixed $answer, array $news = [], ?array $cached = null): PublicCatalogue {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['learniq', 'files']);

		$provider = new class ($answer) {
			public function __construct(private mixed $answer) {
			}

			public function getPublicIndex(string $portal): mixed {
				return $portal === 'warmtepompacademie' ? $this->answer : [];
			}
		};
		$locator = $this->createMock(PortalProviderLocator::class);
		$locator->method('locate')->willReturnCallback(static fn (string $app): ?object => $app === 'learniq' ? $provider : new \stdClass());

		$reader = $this->createMock(PublicNewsReader::class);
		$reader->method('allFor')->willReturn($news);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn($cached);
		$caches = $this->createMock(ICacheFactory::class);
		$caches->method('createDistributed')->willReturn($cache);

		return new PublicCatalogue($apps, $locator, $reader, $caches, $this->createMock(LoggerInterface::class));
	}//end catalogue()

	public function testAnAppAnswerIsHeldToItsShape(): void {
		$items = (new PublicIndexItems())->items(
			'learniq',
			[
				['id' => 'x', 'type' => 'course', 'kind' => 'Cursus', 'title' => ' <b>BHV</b>  basis ', 'date' => 'next week', 'href' => 'javascript:alert(1)', 'secret' => 'bsn', 'facets' => ['Plaats' => ['A', 'A', 7]], 'noteTone' => 'red', 'note' => 'Vol'],
				['id' => 'y', 'type' => 'Bad Type', 'kind' => 'Cursus', 'title' => 'Dropped'],
				['type' => 'course', 'kind' => 'Cursus', 'title' => 'No id'],
				'junk',
				['id' => 'x', 'type' => 'course', 'kind' => 'Cursus', 'title' => 'Twice'],
				['id' => 'z', 'type' => 'event', 'kind' => 'Agenda', 'title' => 'Studiedag', 'date' => '2026-10-09', 'href' => '/kalender'],
			]
		);

		$this->assertSame(['learniq:x', 'learniq:z'], array_column($items, 'id'));
		$this->assertSame('BHV basis', $items[0]['title'], 'markup is stripped and space folded');
		$this->assertArrayNotHasKey('date', $items[0], 'a date that does not parse is dropped');
		$this->assertArrayNotHasKey('href', $items[0], 'only a site path or an http(s) address is a link');
		$this->assertArrayNotHasKey('secret', $items[0], 'an unknown key never reaches a visitor');
		$this->assertSame(['Plaats' => ['A', '7']], $items[0]['facets']);
		$this->assertSame('neutral', $items[0]['noteTone']);
		$this->assertSame('/kalender', $items[1]['href']);
		$this->assertSame([], (new PublicIndexItems())->items('learniq', 'not a list'));
	}

	public function testTheNewsAndEachAppAreGatheredForThePortal(): void {
		$items = $this->catalogue(
			answer: self::courses(),
			news: [['id' => 'n1', 'title' => 'Nieuwe cursusdata', 'intro' => 'De data voor november staan erin.', 'publishedAt' => '2026-10-01T09:00:00+00:00', 'audienceLabel' => '']]
		)->itemsFor('warmtepompacademie');

		$this->assertSame(['news:n1', 'learniq:c1', 'learniq:c2', 'learniq:c3', 'learniq:e1'], array_column($items, 'id'));
		$this->assertSame(['news', 'n1'], [$items[0]['type'], $items[0]['newsId']]);
		$this->assertSame([], array_values(array_filter($this->catalogue(answer: self::courses())->itemsFor('vaartveld'), static fn (array $i): bool => $i['type'] !== 'news')), 'an app answers per portal');
	}

	public function testACachedCatalogueAsksNoApp(): void {
		$cached = [['id' => 'learniq:c9', 'type' => 'course', 'kind' => 'Cursus', 'title' => 'Uit de cache']];

		$this->assertSame($cached, $this->catalogue(answer: self::courses(), cached: $cached)->itemsFor('warmtepompacademie'));
	}

	public function testAQueryFiltersByWordsTypeAndFacetsWithCountsFromTheOtherFacets(): void {
		$catalogue = new PublicCatalogueQuery();
		$items     = (new PublicIndexItems())->items('learniq', self::courses());

		$all = $catalogue->run($items, ['types' => ['course']]);
		$this->assertSame(3, $all['total']);
		$place = $all['facets'][0];
		$this->assertSame('Plaats', $place['label']);
		$this->assertSame([['value' => 'Praktijkhal Zuiddrecht', 'count' => 2, 'selected' => false], ['value' => 'Bij u op de zaak', 'count' => 1, 'selected' => false]], $place['values']);

		$chosen = $catalogue->run($items, ['types' => ['course'], 'filters' => ['Start in' => ['November 2026']], 'sort' => 'date']);
		$this->assertSame(['learniq:c2', 'learniq:c3'], array_column($chosen['items'], 'id'));
		$start = $chosen['facets'][1];
		$this->assertSame(2, $start['values'][0]['count'], 'a facet counts as if its own choice were not made');
		$this->assertTrue($start['values'][1]['selected']);

		$words = $catalogue->run($items, ['q' => 'WARMTEPOMP inbedrijfstelling']);
		$this->assertSame(['learniq:c3'], array_column($words['items'], 'id'), 'every word, any case');
		$this->assertSame(1, $catalogue->run($items, ['q' => 'herhaling', 'types' => ['course']])['total']);
		$this->assertSame(1, $catalogue->run($items, ['q' => 'één dag', 'types' => ['course']])['total'], 'accents do not matter');
	}

	public function testUpcomingSortAndPages(): void {
		$catalogue = new PublicCatalogueQuery();
		$items     = (new PublicIndexItems())->items('learniq', self::courses());

		$upcoming = $catalogue->run($items, ['upcoming' => true, 'today' => '2026-10-05', 'sort' => 'date']);
		$this->assertSame(['learniq:c1', 'learniq:c2', 'learniq:c3'], array_column($upcoming['items'], 'id'), 'the past open day is left out');

		$newest = $catalogue->run($items, ['sort' => 'dateDesc', 'limit' => 2, 'page' => 2]);
		$this->assertSame([2, 2, 4], [$newest['page'], $newest['pages'], $newest['total']]);
		$this->assertSame(['learniq:c1', 'learniq:e1'], array_column($newest['items'], 'id'));
	}

	public function testTheEndpointAnswersAVisitorOfAPublicPortalAndRefusesOneThatIsNot(): void {
		$public = $this->controller(modes: ['public', 'digid']);
		$answer = $public->index('warmtepompacademie', 'herhaling', 'course');
		$this->assertSame(Http::STATUS_OK, $answer->getStatus());
		$this->assertSame(1, $answer->getData()['total']);
		$this->assertStringContainsString('public', (string)(\Closure::bind(fn (): ?array => $this->headers, $answer, \OCP\AppFramework\Http\Response::class)()['Cache-Control'] ?? ''));

		$closed = $this->controller(modes: ['digid']);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $closed->index('warmtepompacademie')->getStatus());

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(modes: null)->index('nergens')->getStatus());
	}

	/**
	 * The endpoint over a portal with the given sign-in modes.
	 *
	 * @param array<int, string>|null $modes The portal's modes; null for no portal.
	 *
	 * @return ContentCatalogueController
	 */
	private function controller(?array $modes): ContentCatalogueController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('');
		$resolver = $this->createMock(PortalResolver::class);
		$resolver->method('resolve')->willReturn($modes === null ? null : ['slug' => 'warmtepompacademie', 'authentication' => ['modes' => $modes]]);
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(null);

		return new ContentCatalogueController('portaliq', $request, $resolver, $session, $this->catalogue(answer: self::courses()));
	}
}
