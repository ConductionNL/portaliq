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
use RuntimeException;

/**
 * marketing-articles-from-pipelinq: the article write path. A draft is
 * stamped by the server, a slug is derived and unique, the lifecycle refuses
 * an undeclared move, the first publish stamps the moment once, a client
 * never sets the agent mark, and usages are asked, not stored.
 *
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md
 */
class ArticleServiceTest extends TestCase {

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

	public function testAnArticleIsCreatedAsADraftByTheSignedInAuthor(): void {
		$result = $this->service()->createArticle(
			payload: [
				'title' => '  Nieuwe release najaar ',
				'body' => '## Wat er nieuw is',
				'tags' => ['release'],
				'status' => 'published',
				'author' => 'someone-else',
				'publishedAt' => '2020-01-01T00:00:00+00:00',
				'agentAuthored' => true,
				'agentAuthoredBy' => 'forged-agent',
				'legacyRef' => 'pipelinq:forged',
			],
			authorUid: 'marketer'
		);

		$this->assertSame([
			'title' => 'Nieuwe release najaar',
			'body' => '## Wat er nieuw is',
			'tags' => ['release'],
			'slug' => 'nieuwe-release-najaar',
			'status' => 'draft',
			'author' => 'marketer',
			'agentAuthored' => false,
			'agentAuthoredBy' => '',
			'createdAt' => $this->now,
			'updatedAt' => $this->now,
		], $this->saves[0]['payload']);
		$this->assertSame('', $this->saves[0]['id']);
		$this->assertSame('uuid-1', $result['article']['uuid']);
		$this->assertArrayNotHasKey('publishedAt', $result['article']);
	}//end testAnArticleIsCreatedAsADraftByTheSignedInAuthor()

	public function testAnAgentWritingIsMarkedByTheWritePath(): void {
		$service = $this->service();
		$created = $service->createArticle(payload: ['title' => 'Concept'], authorUid: 'marketer', agent: 'writer-agent');
		$this->assertTrue($created['article']['agentAuthored']);
		$this->assertSame('writer-agent', $created['article']['agentAuthoredBy']);

		$updated = $service->updateArticle(articleId: 'uuid-1', patch: ['summary' => 'Korter', 'agentAuthored' => true, 'agentAuthoredBy' => 'x']);
		$this->assertFalse($updated['article']['agentAuthored'], 'a person changing it clears the mark; the request cannot set it');
		$this->assertSame('', $updated['article']['agentAuthoredBy']);
		$this->assertSame('Korter', $updated['article']['summary']);
	}//end testAnAgentWritingIsMarkedByTheWritePath()

	public function testATitleIsRequiredAndASlugIsUniqueAndNamed(): void {
		$service = $this->service();
		$this->assertSame(['error' => 'title_required', 'field' => 'title'], $service->createArticle(payload: ['title' => '  '], authorUid: 'm'));

		$service->createArticle(payload: ['title' => 'Nieuwe release najaar'], authorUid: 'm');
		$second = $service->createArticle(payload: ['title' => 'Iets anders', 'slug' => 'Nieuwe Release Najaar'], authorUid: 'm');

		$this->assertSame(['error' => 'slug_taken', 'field' => 'slug', 'slug' => 'nieuwe-release-najaar'], $second);
		$this->assertCount(1, $this->saves);
	}//end testATitleIsRequiredAndASlugIsUniqueAndNamed()

	public function testASlugIsDerivedFromTheTitleWithAccentsAndPunctuationFolded(): void {
		$result = $this->service()->createArticle(payload: ['title' => 'Café & Crème: déjà vu!', 'slug' => '---'], authorUid: 'm');

		$this->assertSame('cafe-creme-deja-vu', $result['article']['slug']);
	}//end testASlugIsDerivedFromTheTitleWithAccentsAndPunctuationFolded()

	public function testAMovedArticleKeepsAUniqueLegacyReference(): void {
		$service = $this->service();
		$first   = $service->createArticle(payload: ['title' => 'Een'], authorUid: 'm', legacyRef: 'pipelinq:abc');
		$this->assertSame('pipelinq:abc', $first['article']['legacyRef']);

		$again = $service->createArticle(payload: ['title' => 'Twee'], authorUid: 'm', legacyRef: 'pipelinq:abc');
		$this->assertSame(['error' => 'legacy_ref_taken', 'field' => 'legacyRef'], $again);
	}//end testAMovedArticleKeepsAUniqueLegacyReference()

	public function testAnUpdateMayKeepItsOwnSlugButNotTakeAnothers(): void {
		$service = $this->service();
		$service->createArticle(payload: ['title' => 'Een'], authorUid: 'm');
		$service->createArticle(payload: ['title' => 'Twee'], authorUid: 'm');

		$this->assertSame('een', $service->updateArticle(articleId: 'uuid-1', patch: ['slug' => 'een'])['article']['slug']);
		$this->assertSame('slug_taken', $service->updateArticle(articleId: 'uuid-1', patch: ['slug' => 'twee'])['error']);
		$this->assertSame('title_required', $service->updateArticle(articleId: 'uuid-1', patch: ['title' => ''])['error']);
		$this->assertSame(['error' => ArticleService::NOT_FOUND], $service->updateArticle(articleId: 'gone', patch: []));
	}//end testAnUpdateMayKeepItsOwnSlugButNotTakeAnothers()

	public function testAnArticleIsFoundByItsSlug(): void {
		$service = $this->service();
		$service->createArticle(payload: ['title' => 'Nieuwe release'], authorUid: 'm');

		$this->assertSame('uuid-1', $service->getArticle(articleId: 'nieuwe-release')['uuid']);
		$this->assertNull($service->getArticle(articleId: 'unknown'));
	}//end testAnArticleIsFoundByItsSlug()

	public function testPublishingTwiceKeepsTheFirstPublicationMoment(): void {
		$service = $this->service();
		$service->createArticle(payload: ['title' => 'Een'], authorUid: 'm');

		$first = $service->applyTransition(articleId: 'uuid-1', transition: 'publish');
		$this->assertSame('published', $first['article']['status']);
		$this->assertSame('2026-11-13T10:00:00+00:00', $first['article']['publishedAt']);

		$this->now = '2026-11-20T10:00:00+00:00';
		$service->applyTransition(articleId: 'uuid-1', transition: 'archive');
		$service->applyTransition(articleId: 'uuid-1', transition: 'restore');
		$again = $service->applyTransition(articleId: 'uuid-1', transition: 'publish');

		$this->assertSame('2026-11-13T10:00:00+00:00', $again['article']['publishedAt']);
		$this->assertSame('2026-11-20T10:00:00+00:00', $again['article']['updatedAt']);
	}//end testPublishingTwiceKeepsTheFirstPublicationMoment()

	public function testAMoveTheLifecycleDoesNotDeclareIsRefusedAndNothingIsWritten(): void {
		$service = $this->service();
		$service->createArticle(payload: ['title' => 'Een'], authorUid: 'm');
		$service->applyTransition(articleId: 'uuid-1', transition: 'archive');
		$saves = count($this->saves);

		$this->assertSame(['error' => 'transition_not_allowed'], $service->applyTransition(articleId: 'uuid-1', transition: 'publish'));
		$this->assertSame(['error' => 'unknown_transition'], $service->applyTransition(articleId: 'uuid-1', transition: 'delete'));
		$this->assertSame(['error' => ArticleService::NOT_FOUND], $service->applyTransition(articleId: 'gone', transition: 'publish'));
		$this->assertCount($saves, $this->saves);
		$this->assertSame('archived', $this->rows['uuid-1']['status']);
	}//end testAMoveTheLifecycleDoesNotDeclareIsRefusedAndNothingIsWritten()

	public function testTheTransitionsAreTheOnesTheSchemaDeclares(): void {
		$register  = json_decode((string)file_get_contents(__DIR__ . '/../../../../lib/Settings/portaliq_register.json'), true);
		$schema    = $register['components']['schemas']['article'];
		$lifecycle = $schema['x-openregister-lifecycle']['transitions'];

		$declared = [];
		foreach ($lifecycle as $name => $rule) {
			$declared[$name] = ['from' => $rule['from'], 'to' => $rule['to']];
		}

		$this->assertSame($declared, ArticleService::TRANSITIONS);
		$this->assertSame($schema['properties']['status']['enum'], ArticleService::STATUSES);
		$this->assertContains('article', $register['components']['registers']['portaliq']['schemas']);
	}//end testTheTransitionsAreTheOnesTheSchemaDeclares()

	public function testUsagesAreAskedFromEveryAppAndStoredNowhere(): void {
		$dispatched = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (ArticleUsagesRequestedEvent $event) use (&$dispatched): void {
				$dispatched[] = [$event->getArticleId(), $event->getSlug(), $event->getLegacyRef()];
				$event->addUsage(id: 't-1', title: 'Najaarsmailing', kind: 'campaignTemplate', app: 'pipelinq', url: '/apps/pipelinq/templates/t-1');
			}
		);
		$service = $this->service(dispatcher: $dispatcher);
		$service->createArticle(payload: ['title' => 'Een'], authorUid: 'm', legacyRef: 'pipelinq:abc');
		$saves = count($this->saves);

		$usages = $service->listUsages(articleId: 'een');

		$this->assertSame([['uuid-1', 'een', 'pipelinq:abc']], $dispatched);
		$this->assertSame(['data' => [['id' => 't-1', 'title' => 'Najaarsmailing', 'kind' => 'campaignTemplate', 'app' => 'pipelinq', 'url' => '/apps/pipelinq/templates/t-1']]], $usages);
		$this->assertCount($saves, $this->saves);
	}//end testUsagesAreAskedFromEveryAppAndStoredNowhere()

	public function testAnUnusedArticleReportsAnEmptyListAndAFailingListenerIsNotFatal(): void {
		$service = $this->service();
		$service->createArticle(payload: ['title' => 'Een'], authorUid: 'm');
		$this->assertSame(['data' => []], $service->listUsages(articleId: 'uuid-1'));

		$failing = $this->createMock(IEventDispatcher::class);
		$failing->method('dispatchTyped')->willThrowException(new RuntimeException('listener broke'));
		$this->assertSame(['data' => []], $this->service(dispatcher: $failing)->listUsages(articleId: 'uuid-1'));
		$this->assertSame(['error' => ArticleService::NOT_FOUND], $service->listUsages(articleId: 'gone'));
	}//end testAnUnusedArticleReportsAnEmptyListAndAFailingListenerIsNotFatal()

	public function testTheIndexIsNewestFirstAndPaged(): void {
		$service = $this->service();
		foreach (['2026-11-01', '2026-11-03', '2026-11-02'] as $day) {
			$this->now = $day . 'T00:00:00+00:00';
			$service->createArticle(payload: ['title' => 'Artikel ' . $day], authorUid: 'm');
		}

		$page = $service->listArticles(page: 1, limit: 2);
		$this->assertSame(['Artikel 2026-11-03', 'Artikel 2026-11-02'], array_column($page['data'], 'title'));
		$this->assertSame(['page' => 1, 'limit' => 2, 'total' => 3, 'pages' => 2], $page['pagination']);
		$this->assertSame(['Artikel 2026-11-01'], array_column($service->listArticles(page: 2, limit: 2)['data'], 'title'));
		$this->assertSame(['page' => 1, 'limit' => 100, 'total' => 3, 'pages' => 1], $service->listArticles(page: 0, limit: 500)['pagination']);
	}//end testTheIndexIsNewestFirstAndPaged()

	public function testAFailedSaveSaysSo(): void {
		$store = $this->createMock(ArticleStore::class);
		$store->method('findAll')->willReturn([]);
		$store->method('save')->willReturn(null);
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new DateTime($this->now));
		$service = new ArticleService(store: $store, timeFactory: $clock, dispatcher: $this->createMock(IEventDispatcher::class), logger: $this->createMock(LoggerInterface::class));

		$this->assertSame(['error' => 'not_saved'], $service->createArticle(payload: ['title' => 'Een'], authorUid: 'm'));
	}//end testAFailedSaveSaysSo()
}//end class
