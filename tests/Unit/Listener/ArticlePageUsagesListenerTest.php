<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Listener;

use OCA\Portaliq\Event\ArticleUsagesRequestedEvent;
use OCA\Portaliq\Listener\ArticlePageUsagesListener;
use OCA\Portaliq\Service\Articles\ArticleStore;
use OCP\EventDispatcher\Event;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * portaliq's pages that name an article are among its usages.
 *
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
 */
#[CoversClass(ArticlePageUsagesListener::class)]
#[UsesClass(ArticleUsagesRequestedEvent::class)]
#[UsesClass(ArticleStore::class)]
class ArticlePageUsagesListenerTest extends TestCase {

	/**
	 * The filters the page store was last asked with.
	 *
	 * @var array<string, mixed>
	 */
	public array $asked = [];

	/**
	 * A listener over these stored pages.
	 *
	 * @param array<int, array<string, mixed>>|null $pages The pages; null makes the store throw.
	 *
	 * @return ArticlePageUsagesListener
	 */
	private function listener(?array $pages): ArticlePageUsagesListener {
		$test = $this;
		$objects = new class ($pages, $test) {
			/**
			 * @param array<int, array<string, mixed>>|null $pages
			 */
			public function __construct(
				private ?array $pages,
				private ArticlePageUsagesListenerTest $test,
			) {
			}//end __construct()

			/**
			 * @param array<string, mixed> $config
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->test->asked = $config['filters'] ?? [];
				if ($this->pages === null) {
					throw new RuntimeException('store down');
				}

				return $this->pages;
			}//end findAll()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkTo')->willReturn('/index.php');

		return new ArticlePageUsagesListener($container, $urls, $this->createMock(LoggerInterface::class));
	}//end listener()

	public function testThePagesThatNameTheArticleByIdOrSlugAreItsUsages(): void {
		$pages = [
			['id' => 'p1', 'title' => 'Najaarsactie', 'route' => '/najaar', 'status' => 'published', 'body' => ['type' => 'markdown', 'articleRef' => 'uuid-1']],
			['@self' => ['id' => 'p2'], 'title' => 'Release', 'status' => 'draft', 'body' => ['type' => 'markdown', 'articleRef' => ' openregister-3-0-is-uit ']],
			['id' => 'p3', 'title' => 'Ander artikel', 'body' => ['type' => 'markdown', 'articleRef' => 'iets-anders']],
			['id' => 'p4', 'title' => 'Grid', 'body' => ['type' => 'grid', 'widgets' => []]],
		];
		$event = new ArticleUsagesRequestedEvent(articleId: 'uuid-1', slug: 'openregister-3-0-is-uit');

		$this->listener($pages)->handle($event);

		$this->assertSame(['register' => 'portaliq', 'schema' => 'page'], $this->asked);
		$this->assertSame(
			[
				['id' => 'p1', 'title' => 'Najaarsactie', 'kind' => 'page', 'app' => 'portaliq', 'url' => '/index.php/apps/portaliq/pages/p1'],
				['id' => 'p2', 'title' => 'Release', 'kind' => 'page', 'app' => 'portaliq', 'url' => '/index.php/apps/portaliq/pages/p2'],
			],
			$event->getUsages()
		);
	}//end testThePagesThatNameTheArticleByIdOrSlugAreItsUsages()

	public function testAnUnusedArticleOrAFailingStoreAddsNothing(): void {
		$unused = new ArticleUsagesRequestedEvent(articleId: 'uuid-9', slug: 'niemand');
		$this->listener([['id' => 'p1', 'body' => ['articleRef' => 'uuid-1']]])->handle($unused);
		$this->assertSame([], $unused->getUsages());

		$failing = new ArticleUsagesRequestedEvent(articleId: 'uuid-1');
		$this->listener(null)->handle($failing);
		$this->assertSame([], $failing->getUsages());
	}//end testAnUnusedArticleOrAFailingStoreAddsNothing()

	public function testAnotherEventIsIgnored(): void {
		$this->listener([['id' => 'p1', 'body' => ['articleRef' => 'uuid-1']]])->handle(new Event());
		$this->assertSame([], $this->asked, 'the store is not read for another event');
	}//end testAnotherEventIsIgnored()
}//end class
