<?php

/**
 * Page blocks read an app's public index: the item's category and cells, the
 * kinds an app declares, the narrowing by app, category and school year, and
 * the filter value `visitor` (editor-blocks-read-public-app-data).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Contribution\PublicIndexItems;
use OCA\Portaliq\Contribution\PublicIndexKinds;
use OCA\Portaliq\Controller\ContentCatalogueController;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\PublicCatalogue;
use OCA\Portaliq\Service\PublicCatalogueQuery;
use OCA\Portaliq\Service\PublicNewsReader;
use OCP\App\IAppManager;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PublicIndexSourceTest extends TestCase {

	/**
	 * The school's tests and days, as learniq would answer them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function index(): array {
		return [
			['id' => 't1', 'type' => 'test', 'kind' => 'Toets', 'title' => 'Wiskunde B', 'date' => '2026-11-09', 'facets' => ['Afdeling' => '4 havo', 'Toetsweek' => '1'], 'cells' => ['day' => 'ma 9 nov', 'time' => '09.00', 'subject' => 'Wiskunde B', 'room' => 'A1.04']],
			['id' => 't2', 'type' => 'test', 'kind' => 'Toets', 'title' => 'Nederlands', 'date' => '2026-11-10', 'facets' => ['Afdeling' => '5 havo', 'Toetsweek' => '1'], 'cells' => ['day' => 'di 10 nov', 'subject' => 'Nederlands']],
			['id' => 'd1', 'type' => 'schoolDay', 'kind' => 'Vakantie', 'title' => 'Herfstvakantie', 'date' => '2026-10-17', 'category' => 'holiday'],
			['id' => 'd2', 'type' => 'schoolDay', 'kind' => 'Studiedag', 'title' => 'Studiedag', 'date' => '2026-12-04', 'category' => 'dayOff'],
			['id' => 'd3', 'type' => 'schoolDay', 'kind' => 'Ouderavond', 'title' => 'Ouderavond', 'date' => '2026-11-12', 'category' => 'activity'],
			['id' => 'd4', 'type' => 'schoolDay', 'kind' => 'Vakantie', 'title' => 'Zomervakantie vorig jaar', 'date' => '2026-07-20', 'category' => 'holiday'],
		];
	}//end index()

	public function testAnItemKeepsItsCategoryAndPlainCells(): void {
		$items = (new PublicIndexItems())->items('learniq', [
			['id' => 'x', 'type' => 'test', 'kind' => 'Toets', 'title' => 'T', 'category' => ' <b>exam</b> ', 'cells' => ['day' => 'ma', 'Bad Key' => 'x', 'room' => ['a'], 'subject' => '<i>Wi</i>']],
		]);

		$this->assertSame('exam', $items[0]['category']);
		$this->assertSame(['day' => 'ma', 'subject' => 'Wi'], $items[0]['cells']);
	}

	public function testTheKindsAnAppDeclaresAreHeldToTheirShape(): void {
		$kinds = (new PublicIndexKinds())->kinds('learniq', [
			['type' => 'test', 'label' => 'Toetsrooster', 'categories' => ['exam', 'exam', 7], 'filters' => ['Afdeling' => ['4 havo', '5 havo'], 'Leeg' => []], 'columns' => [['key' => 'day', 'label' => 'Dag'], ['key' => 'Bad Key', 'label' => 'x'], 'junk']],
			['type' => 'Bad Type', 'label' => 'dropped'],
			['type' => 'test', 'label' => 'Twice'],
			'junk',
		]);

		$this->assertCount(1, $kinds);
		$this->assertSame('learniq', $kinds[0]['app']);
		$this->assertSame(['exam', '7'], $kinds[0]['categories']);
		$this->assertSame(['Afdeling' => ['4 havo', '5 havo']], $kinds[0]['filters']);
		$this->assertSame([['key' => 'day', 'label' => 'Dag']], $kinds[0]['columns']);
		$this->assertSame([], (new PublicIndexKinds())->kinds('learniq', 'nope'));
	}

	public function testAQueryNarrowsByAppCategoryAndSchoolYear(): void {
		$query = new PublicCatalogueQuery();
		$items = (new PublicIndexItems())->items('learniq', self::index());

		$this->assertSame(['learniq:t1', 'learniq:t2'], array_column($query->run($items, ['app' => 'learniq', 'types' => ['test'], 'sort' => 'date'])['items'], 'id'));
		$this->assertSame(0, $query->run($items, ['app' => 'other'])['total'], 'another app has no items here');

		$days = $query->run($items, ['types' => ['schoolDay'], 'categories' => ['holiday', 'dayOff'], 'range' => 'schoolYear', 'today' => '2026-10-08', 'sort' => 'date']);
		$this->assertSame(['learniq:d1', 'learniq:d2'], array_column($days['items'], 'id'), 'holidays and days off of this school year only');

		$next = $query->run($items, ['types' => ['schoolDay'], 'range' => 'schoolYear', 'today' => '2026-07-31']);
		$this->assertSame(['learniq:d4'], array_column($next['items'], 'id'), 'the school year ends on 31 July');
	}

	public function testTheEndpointOffersTheDeclaredKindsAndResolvesVisitorOnlyForASignedInPerson(): void {
		$anonymous = $this->controller(subject: null);
		$kinds     = $anonymous->kinds('vaartveld')->getData()['kinds'];
		$this->assertSame('test', $kinds[0]['type']);

		$all = $anonymous->index('vaartveld', '', 'test', '{"Afdeling":["visitor"]}', 'date');
		$this->assertSame(2, $all->getData()['total'], 'an anonymous visitor leaves the filter empty');

		$signedIn = $this->controller(subject: ['sub' => 'p-1']);
		$mine     = $signedIn->index('vaartveld', '', 'test', '{"Afdeling":["visitor"]}', 'date', 1, 10, '', 'learniq');
		$this->assertSame(['learniq:t1'], array_column($mine->getData()['items'], 'id'), "the visitor's own class");

		$other = $this->controller(subject: ['sub' => 'p-2']);
		$this->assertSame(2, $other->index('vaartveld', '', 'test', '{"Afdeling":["visitor"]}', 'date', 1, 10, '', 'learniq')->getData()['total'], 'a person the app cannot place narrows nothing');
	}

	/**
	 * The endpoint over an app that declares kinds and a visitor.
	 *
	 * @param array<string, mixed>|null $subject The signed-in subject, or null.
	 *
	 * @return ContentCatalogueController
	 */
	private function controller(?array $subject): ContentCatalogueController {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['learniq']);

		$provider = new class () {
			public function getPublicIndex(string $portal): array {
				return PublicIndexSourceTest::index();
			}

			public function getPublicIndexKinds(string $portal): array {
				return [['type' => 'test', 'label' => 'Toetsrooster', 'filters' => ['Afdeling' => ['4 havo', '5 havo']], 'columns' => [['key' => 'day', 'label' => 'Dag']]]];
			}

			public function getPublicIndexVisitor(string $portal, array $subject): array {
				return ($subject['sub'] ?? '') === 'p-1' ? ['Afdeling' => ['4 havo']] : [];
			}
		};
		$locator  = $this->createMock(PortalProviderLocator::class);
		$locator->method('locate')->willReturn($provider);
		$reader = $this->createMock(PublicNewsReader::class);
		$reader->method('allFor')->willReturn([]);
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(null);
		$caches = $this->createMock(ICacheFactory::class);
		$caches->method('createDistributed')->willReturn($cache);
		$catalogue = new PublicCatalogue($apps, $locator, $reader, $caches, $this->createMock(LoggerInterface::class));

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn($subject === null ? '' : 'Bearer x');
		$resolver = $this->createMock(PortalResolver::class);
		$resolver->method('resolve')->willReturn(['slug' => 'vaartveld', 'authentication' => ['modes' => ['public']]]);
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);

		return new ContentCatalogueController('portaliq', $request, $resolver, $session, $catalogue);
	}//end controller()
}
