<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Articles;

use DateTime;
use OCA\Portaliq\Event\ArticleUsagesRequestedEvent;
use OCA\Portaliq\Service\Articles\ArticleService;
use OCA\Portaliq\Service\Articles\ArticleStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * marketing-articles-from-pipelinq: the rules of the article write path that
 * the main test leaves alone. Only declared fields are written, the index is
 * clamped, an update and a move answer not_found for an unknown article, the
 * whole lifecycle walks as declared, and a usage request names the article
 * by every identity it has.
 *
 * @spec openspec/changes/archive/2026-11-24-marketing-articles-from-pipelinq/specs/marketing-articles/spec.md
 */
class ArticleServiceRulesTest extends TestCase {

	/**
	 * The rows the store holds, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * What was saved, in order.
	 *
	 * @var array<int, array{payload: array<string, mixed>, id: string}>
	 */
	private array $saves = [];

	/**
	 * The clock's moment.
	 */
	private string $now = '2026-11-13T10:00:00+00:00';

	/**
	 * The service over an in-memory store.
	 *
	 * @param IEventDispatcher|null $dispatcher The dispatcher, or one that does nothing.
	 *
	 * @return ArticleService
	 */
	private function service(?IEventDispatcher $dispatcher = null): ArticleService {
		$store = $this->createMock(ArticleStore::class);
		$store->method('findAll')->willReturnCallback(fn (): array => array_values($this->rows));
		$store->method('find')->willReturnCallback(fn (string $id): ?array => ($this->rows[$id] ?? null));
		$store->method('save')->willReturnCallback(
			function (array $payload, string $id = ''): array {
				$this->saves[] = ['payload' => $payload, 'id' => $id];
				$uuid = ($id !== '' ? $id : 'uuid-' . (count($this->rows) + 1));
				$this->rows[$uuid] = ['uuid' => $uuid] + $payload;
				return $this->rows[$uuid];
			}
		);
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getDateTime')->willReturnCallback(fn (): DateTime => new DateTime($this->now));

		return new ArticleService(
			store: $store,
			timeFactory: $clock,
			dispatcher: ($dispatcher ?? $this->createMock(IEventDispatcher::class)),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * Put an article straight into the store.
	 *
	 * @param string               $uuid The id.
	 * @param array<string, mixed> $row  The fields.
	 *
	 * @return void
	 */
	private function seed(string $uuid, array $row): void {
		$this->rows[$uuid] = ['uuid' => $uuid] + $row;
	}//end seed()

	public function testOnlyTheDeclaredFieldsAreWrittenAndTrimmed(): void {
		$this->service()->createArticle(
			payload: [
				'title' => ' Titel ',
				'summary' => '  Kort  ',
				'language' => 'nl ',
				'organisation' => ' org-1',
				'portalPageRef' => 'page-7 ',
				'heroImage' => ' /img/a.svg ',
				'links' => ['b' => 'https://example.org/b', 'a' => 'https://example.org/a'],
				'tags' => 'not-a-list',
				'body' => ['not' => 'text'],
				'uuid' => 'forged-id',
				'unknownField' => 'dropped',
			],
			authorUid: 'marketer'
		);

		$payload = $this->saves[0]['payload'];
		$this->assertSame('Titel', $payload['title']);
		$this->assertSame('Kort', $payload['summary']);
		$this->assertSame('nl', $payload['language']);
		$this->assertSame('org-1', $payload['organisation']);
		$this->assertSame('page-7', $payload['portalPageRef']);
		$this->assertSame('/img/a.svg', $payload['heroImage']);
		$this->assertSame(['https://example.org/b', 'https://example.org/a'], $payload['links'], 'a list is kept in order, re-indexed');
		$this->assertArrayNotHasKey('tags', $payload, 'a list field that is no list is dropped');
		$this->assertArrayNotHasKey('body', $payload, 'a text field that is no text is dropped');
		$this->assertArrayNotHasKey('uuid', $payload);
		$this->assertArrayNotHasKey('unknownField', $payload);
	}//end testOnlyTheDeclaredFieldsAreWrittenAndTrimmed()

	public function testAScalarFieldIsWrittenAsText(): void {
		$this->service()->createArticle(payload: ['title' => 2027, 'summary' => true], authorUid: 'm');

		$this->assertSame('2027', $this->saves[0]['payload']['title']);
		$this->assertSame('1', $this->saves[0]['payload']['summary']);
		$this->assertSame('2027', $this->saves[0]['payload']['slug']);
	}//end testAScalarFieldIsWrittenAsText()

	public function testATitleOfOnlyPunctuationGetsAGeneratedSlug(): void {
		$result = $this->service()->createArticle(payload: ['title' => '!!! ???', 'slug' => '---'], authorUid: 'm');

		$this->assertMatchesRegularExpression('/^article-[0-9a-f]{8}$/', $result['article']['slug']);
		$this->assertSame(substr(sha1('!!! ???' . $this->now), 0, 8), substr($result['article']['slug'], 8));
	}//end testATitleOfOnlyPunctuationGetsAGeneratedSlug()

	public function testAGivenSlugIsUsedFoldedAndCappedAt255(): void {
		$service = $this->service();
		$given   = $service->createArticle(payload: ['title' => 'Titel', 'slug' => ' Eigen  Slug! '], authorUid: 'm');
		$this->assertSame('eigen-slug', $given['article']['slug']);

		$long = $service->createArticle(payload: ['title' => str_repeat('ab ', 200)], authorUid: 'm');
		$this->assertLessThanOrEqual(255, strlen($long['article']['slug']));
		$this->assertStringEndsNotWith('-', $long['article']['slug']);
		$this->assertStringStartsWith('ab-ab-', $long['article']['slug']);
	}//end testAGivenSlugIsUsedFoldedAndCappedAt255()

	public function testATakenLegacyReferenceIsRefusedAndNothingIsWritten(): void {
		$this->seed(uuid: 'old', row: ['title' => 'Oud', 'slug' => 'oud', 'legacyRef' => 'pipelinq:42']);

		$result = $this->service()->createArticle(payload: ['title' => 'Nieuw'], authorUid: 'm', legacyRef: ' pipelinq:42 ');

		$this->assertSame(['error' => 'legacy_ref_taken', 'field' => 'legacyRef'], $result);
		$this->assertSame([], $this->saves);
	}//end testATakenLegacyReferenceIsRefusedAndNothingIsWritten()

	public function testAnEmptyLegacyReferenceIsNotWritten(): void {
		$result = $this->service()->createArticle(payload: ['title' => 'Nieuw'], authorUid: 'm', legacyRef: '   ');

		$this->assertArrayNotHasKey('legacyRef', $result['article']);
	}//end testAnEmptyLegacyReferenceIsNotWritten()

	public function testThePageAndItsSizeAreClamped(): void {
		for ($n = 1; $n <= 3; $n++) {
			$this->seed(uuid: 'a' . $n, row: ['title' => 'A' . $n, 'createdAt' => '2026-11-0' . $n . 'T00:00:00+00:00']);
		}

		$service = $this->service();

		$first = $service->listArticles(page: 0, limit: 0);
		$this->assertSame(['page' => 1, 'limit' => 1, 'total' => 3, 'pages' => 3], $first['pagination']);
		$this->assertSame(['a3'], array_column($first['data'], 'uuid'));

		$all = $service->listArticles(page: -4, limit: 1000);
		$this->assertSame(['page' => 1, 'limit' => 100, 'total' => 3, 'pages' => 1], $all['pagination']);
		$this->assertSame(['a3', 'a2', 'a1'], array_column($all['data'], 'uuid'));

		$beyond = $service->listArticles(page: 9, limit: 2);
		$this->assertSame([], $beyond['data']);
		$this->assertSame(2, $beyond['pagination']['pages']);
	}//end testThePageAndItsSizeAreClamped()

	public function testAnEmptyIndexHasOnePage(): void {
		$this->assertSame(
			['data' => [], 'pagination' => ['page' => 1, 'limit' => 20, 'total' => 0, 'pages' => 1]],
			$this->service()->listArticles(page: 1, limit: 20)
		);
	}//end testAnEmptyIndexHasOnePage()

	public function testAnArticleIsFoundByIdBeforeSlugAndAnEmptyIdFindsNothing(): void {
		$this->seed(uuid: 'one', row: ['title' => 'Een', 'slug' => 'two']);
		$this->seed(uuid: 'two', row: ['title' => 'Twee', 'slug' => 'twee']);
		$this->seed(uuid: 'three', row: ['title' => 'Drie']);

		$service = $this->service();

		$this->assertSame('two', $service->getArticle(articleId: 'two')['uuid'], 'an id wins over another row\'s slug');
		$this->assertSame('two', $service->getArticle(articleId: 'twee')['uuid']);
		$this->assertNull($service->getArticle(articleId: ''), 'an empty id never matches a row without a slug');
		$this->assertNull($service->getArticle(articleId: 'vier'));
	}//end testAnArticleIsFoundByIdBeforeSlugAndAnEmptyIdFindsNothing()

	public function testAnUpdateOfAnUnknownArticleIsNotFound(): void {
		$this->assertSame(['error' => 'not_found'], $this->service()->updateArticle(articleId: 'nope', patch: ['title' => 'x']));
		$this->assertSame([], $this->saves);
	}//end testAnUpdateOfAnUnknownArticleIsNotFound()

	public function testAnUpdateMayNotEmptyTheTitle(): void {
		$this->seed(uuid: 'a', row: ['title' => 'Titel', 'slug' => 'titel']);

		$this->assertSame(['error' => 'title_required', 'field' => 'title'], $this->service()->updateArticle(articleId: 'a', patch: ['title' => '  ']));
		$this->assertSame([], $this->saves);
	}//end testAnUpdateMayNotEmptyTheTitle()

	public function testAnUpdateKeepsWhatItDoesNotNameAndStampsTheMoment(): void {
		$this->seed(uuid: 'a', row: ['title' => 'Titel', 'slug' => 'titel', 'body' => 'Oud', 'status' => 'review', 'createdAt' => '2026-01-01T00:00:00+00:00']);
		$this->now = '2026-11-14T08:30:00+00:00';

		$result = $this->service()->updateArticle(articleId: 'titel', patch: ['body' => 'Nieuw', 'status' => 'published']);

		$this->assertSame('a', $this->saves[0]['id'], 'found by slug, saved on its own id');
		$this->assertSame('Nieuw', $result['article']['body']);
		$this->assertSame('Titel', $result['article']['title']);
		$this->assertSame('titel', $result['article']['slug']);
		$this->assertSame('review', $result['article']['status'], 'an update never moves the lifecycle');
		$this->assertSame('2026-01-01T00:00:00+00:00', $result['article']['createdAt']);
		$this->assertSame('2026-11-14T08:30:00+00:00', $result['article']['updatedAt']);
	}//end testAnUpdateKeepsWhatItDoesNotNameAndStampsTheMoment()

	public function testAnEmptiedSlugIsDerivedFromTheNewTitle(): void {
		$this->seed(uuid: 'a', row: ['title' => 'Oud', 'slug' => 'oud']);

		$result = $this->service()->updateArticle(articleId: 'a', patch: ['title' => 'Café de Paris', 'slug' => '']);

		$this->assertSame('cafe-de-paris', $result['article']['slug']);
	}//end testAnEmptiedSlugIsDerivedFromTheNewTitle()

	public function testAnUnknownMoveIsRefusedBeforeTheArticleIsRead(): void {
		$this->assertSame(['error' => 'unknown_transition'], $this->service()->applyTransition(articleId: 'nope', transition: 'teleport'));
	}//end testAnUnknownMoveIsRefusedBeforeTheArticleIsRead()

	public function testAMoveOnAnUnknownArticleIsNotFound(): void {
		$this->assertSame(['error' => 'not_found'], $this->service()->applyTransition(articleId: 'nope', transition: 'publish'));
	}//end testAMoveOnAnUnknownArticleIsNotFound()

	public function testTheWholeLifecycleWalksAsDeclared(): void {
		$service = $this->service();
		$service->createArticle(payload: ['title' => 'Release'], authorUid: 'm');

		$this->now = '2026-11-13T11:00:00+00:00';
		$this->assertSame('review', $service->applyTransition(articleId: 'uuid-1', transition: 'submitForReview')['article']['status']);
		$this->assertSame('draft', $service->applyTransition(articleId: 'uuid-1', transition: 'returnToDraft')['article']['status']);
		$this->assertSame('review', $service->applyTransition(articleId: 'uuid-1', transition: 'submitForReview')['article']['status']);

		$this->now = '2026-11-13T12:00:00+00:00';
		$published = $service->applyTransition(articleId: 'uuid-1', transition: 'publish')['article'];
		$this->assertSame('published', $published['status']);
		$this->assertSame('2026-11-13T12:00:00+00:00', $published['publishedAt'], 'publishing from review stamps the moment');

		$this->assertSame(['error' => 'transition_not_allowed'], $service->applyTransition(articleId: 'uuid-1', transition: 'submitForReview'));
		$this->assertSame(['error' => 'transition_not_allowed'], $service->applyTransition(articleId: 'uuid-1', transition: 'restore'));

		$this->now = '2026-11-14T09:00:00+00:00';
		$archived = $service->applyTransition(articleId: 'uuid-1', transition: 'archive')['article'];
		$this->assertSame('archived', $archived['status']);
		$this->assertSame('2026-11-13T12:00:00+00:00', $archived['publishedAt'], 'archiving keeps the publication moment');

		$this->assertSame(['error' => 'transition_not_allowed'], $service->applyTransition(articleId: 'uuid-1', transition: 'publish'));
		$restored = $service->applyTransition(articleId: 'uuid-1', transition: 'restore')['article'];
		$this->assertSame('draft', $restored['status']);
		$this->assertSame('2026-11-14T09:00:00+00:00', $restored['updatedAt']);
	}//end testTheWholeLifecycleWalksAsDeclared()

	public function testAStoredStatusTheSchemaDoesNotKnowIsReadAsDraft(): void {
		$this->seed(uuid: 'a', row: ['title' => 'Vreemd', 'slug' => 'vreemd', 'status' => 'scheduled']);
		$this->seed(uuid: 'b', row: ['title' => 'Zonder status', 'slug' => 'zonder']);

		$service = $this->service();

		$this->assertSame('review', $service->applyTransition(articleId: 'a', transition: 'submitForReview')['article']['status']);
		$this->assertSame(['error' => 'transition_not_allowed'], $service->applyTransition(articleId: 'b', transition: 'restore'));
		$this->assertSame('published', $service->applyTransition(articleId: 'b', transition: 'publish')['article']['status']);
	}//end testAStoredStatusTheSchemaDoesNotKnowIsReadAsDraft()

	public function testAFailedMoveSaysSo(): void {
		$store = $this->createMock(ArticleStore::class);
		$store->method('find')->willReturn(['uuid' => 'a', 'title' => 'T', 'status' => 'draft']);
		$store->method('save')->willReturn(null);
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new DateTime($this->now));
		$service = new ArticleService(
			store: $store,
			timeFactory: $clock,
			dispatcher: $this->createMock(IEventDispatcher::class),
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->assertSame(['error' => 'not_saved'], $service->applyTransition(articleId: 'a', transition: 'publish'));
	}//end testAFailedMoveSaysSo()

	public function testUsagesOfAnUnknownArticleAreNotFoundAndNothingIsAsked(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->never())->method('dispatchTyped');

		$this->assertSame(['error' => 'not_found'], $this->service(dispatcher: $dispatcher)->listUsages(articleId: 'nope'));
	}//end testUsagesOfAnUnknownArticleAreNotFoundAndNothingIsAsked()

	public function testTheUsageRequestNamesTheArticleByEveryIdentity(): void {
		$this->seed(uuid: 'a', row: ['title' => 'T', 'slug' => 'release', 'legacyRef' => 'pipelinq:42']);
		$seen       = null;
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->once())->method('dispatchTyped')->willReturnCallback(
			static function (ArticleUsagesRequestedEvent $event) use (&$seen): void {
				$seen = [$event->getArticleId(), $event->getSlug(), $event->getLegacyRef()];
				$event->addUsage(id: 'p1', title: 'Home', kind: 'page', app: 'portaliq', url: '/home');
			}
		);

		$result = $this->service(dispatcher: $dispatcher)->listUsages(articleId: 'release');

		$this->assertSame(['a', 'release', 'pipelinq:42'], $seen, 'asked by id, slug and legacy reference');
		$this->assertCount(1, $result['data']);
		$this->assertSame('p1', $result['data'][0]['id']);
		$this->assertSame('portaliq', $result['data'][0]['app']);
	}//end testTheUsageRequestNamesTheArticleByEveryIdentity()
}//end class
