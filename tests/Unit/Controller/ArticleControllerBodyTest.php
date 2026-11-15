<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\ArticleController;
use OCA\Portaliq\Service\Articles\ArticleService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * marketing-articles-from-pipelinq: what the article routes read from a
 * request and how each answer of the service becomes a status. Only text and
 * list fields the article declares are read, as their own type; a missing
 * article is a 404 on every route; any other refusal is a 400 that keeps its
 * reason; a success carries the article alone.
 *
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md
 */
class ArticleControllerBodyTest extends TestCase {
	use StaffActionDoubleTrait;

	/**
	 * The controller over a service double, for a signed-in marketer.
	 *
	 * @param ArticleService       $articles The service.
	 * @param array<string, mixed> $params   The request's parameters.
	 *
	 * @return ArticleController
	 */
	private function controller(ArticleService $articles, array $params = []): ArticleController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('marketer');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ArticleController($request, $session, $this->staffActionAuth(ArticleController::ACTION), $articles);
	}//end controller()

	public function testTheBodyReadsTheDeclaredTextAsTextAndTheListsAsLists(): void {
		$articles = $this->createMock(ArticleService::class);
		$articles->expects($this->once())->method('updateArticle')
			->with(
				'a-1',
				[
					'title' => '2027',
					'slug' => 'release',
					'summary' => '1',
					'body' => '## Nieuw',
					'heroImage' => '/img/hero.svg',
					'language' => 'nl',
					'organisation' => 'org-1',
					'portalPageRef' => 'page-1',
					'links' => [['label' => 'Lees', 'url' => 'https://example.org']],
					'tags' => ['release'],
				]
			)
			->willReturn(['article' => ['id' => 'a-1']]);

		$response = $this->controller($articles, [
			'title' => 2027,
			'slug' => 'release',
			'summary' => true,
			'body' => '## Nieuw',
			'heroImage' => '/img/hero.svg',
			'language' => 'nl',
			'organisation' => 'org-1',
			'portalPageRef' => 'page-1',
			'links' => [['label' => 'Lees', 'url' => 'https://example.org']],
			'tags' => ['release'],
			'status' => 'published',
			'author' => 'someone-else',
			'agentAuthoredBy' => 'forged',
		])->update('a-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['article' => ['id' => 'a-1']], $response->getData());
	}//end testTheBodyReadsTheDeclaredTextAsTextAndTheListsAsLists()

	public function testATextFieldSentAsAListAndAListSentAsTextAreLeftOut(): void {
		$articles = $this->createMock(ArticleService::class);
		$articles->expects($this->once())->method('createArticle')
			->with(['summary' => 'Kort'], 'marketer')
			->willReturn(['article' => ['id' => 'new']]);

		$response = $this->controller($articles, [
			'title' => ['not', 'text'],
			'summary' => 'Kort',
			'body' => null,
			'tags' => 'release',
			'links' => 'https://example.org',
		])->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['article' => ['id' => 'new']], $response->getData());
	}//end testATextFieldSentAsAListAndAListSentAsTextAreLeftOut()

	public function testAnEmptyRequestSendsAnEmptyBody(): void {
		$articles = $this->createMock(ArticleService::class);
		$articles->expects($this->once())->method('updateArticle')->with('a-1', [])->willReturn(['article' => ['id' => 'a-1']]);

		$this->assertSame(Http::STATUS_OK, $this->controller($articles)->update('a-1')->getStatus());
	}//end testAnEmptyRequestSendsAnEmptyBody()

	public function testAMissingArticleIsA404OnEveryWriteRoute(): void {
		$articles = $this->createMock(ArticleService::class);
		$articles->method('updateArticle')->willReturn(['error' => ArticleService::NOT_FOUND]);
		$articles->method('applyTransition')->willReturn(['error' => ArticleService::NOT_FOUND, 'extra' => 'dropped']);
		$controller = $this->controller($articles);

		foreach ([
			$controller->update('nope'),
			$controller->publish('nope'),
			$controller->archive('nope'),
			$controller->transition('nope', 'restore'),
		] as $response) {
			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			$this->assertSame(['error' => 'not_found'], $response->getData());
		}
	}//end testAMissingArticleIsA404OnEveryWriteRoute()

	public function testAnyOtherRefusalIsA400KeepingItsReason(): void {
		$articles = $this->createMock(ArticleService::class);
		$articles->method('updateArticle')->willReturn(['error' => 'slug_taken', 'field' => 'slug', 'slug' => 'release']);
		$articles->method('applyTransition')->willReturnCallback(
			static fn (string $articleId, string $transition): array => ($transition === '' ? ['error' => 'unknown_transition'] : ['error' => 'transition_not_allowed'])
		);
		$controller = $this->controller($articles);

		$update = $controller->update('a-1');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $update->getStatus());
		$this->assertSame(['error' => 'slug_taken', 'field' => 'slug', 'slug' => 'release'], $update->getData());

		$this->assertSame(['error' => 'transition_not_allowed'], $controller->publish('a-1')->getData());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->archive('a-1')->getStatus());
		$this->assertSame(['error' => 'unknown_transition'], $controller->transition('a-1')->getData());
	}//end testAnyOtherRefusalIsA400KeepingItsReason()

	public function testEachLifecycleRouteNamesItsOwnMove(): void {
		$seen     = [];
		$articles = $this->createMock(ArticleService::class);
		$articles->method('applyTransition')->willReturnCallback(
			static function (string $articleId, string $transition) use (&$seen): array {
				$seen[] = [$articleId, $transition];
				return ['article' => ['id' => $articleId, 'moved' => $transition]];
			}
		);
		$controller = $this->controller($articles);

		$this->assertSame(['article' => ['id' => 'a-1', 'moved' => 'publish']], $controller->publish('a-1')->getData());
		$controller->archive('a-2');
		$controller->transition('a-3', 'submitForReview');

		$this->assertSame([['a-1', 'publish'], ['a-2', 'archive'], ['a-3', 'submitForReview']], $seen);
	}//end testEachLifecycleRouteNamesItsOwnMove()

	public function testAMissingArticleOrItsUsagesAre404AndAFoundOneIsWrapped(): void {
		$articles = $this->createMock(ArticleService::class);
		$articles->method('getArticle')->willReturnCallback(static fn (string $id): ?array => ($id === 'a-1' ? ['id' => 'a-1'] : null));
		$articles->method('listUsages')->willReturnCallback(
			static fn (string $id): array => ($id === 'a-1' ? ['data' => [['id' => 'p-1']]] : ['error' => ArticleService::NOT_FOUND])
		);
		$controller = $this->controller($articles);

		$this->assertSame(['article' => ['id' => 'a-1']], $controller->show('a-1')->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->show('nope')->getStatus());
		$this->assertSame(['error' => 'not_found'], $controller->show('nope')->getData());
		$this->assertSame(['data' => [['id' => 'p-1']]], $controller->usages('a-1')->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->usages('nope')->getStatus());
	}//end testAMissingArticleOrItsUsagesAre404AndAFoundOneIsWrapped()

	public function testTheIndexPassesItsPageThrough(): void {
		$articles = $this->createMock(ArticleService::class);
		$articles->expects($this->once())->method('listArticles')->with(3, 50)->willReturn(['data' => [], 'pagination' => ['page' => 3]]);

		$response = $this->controller($articles)->index(page: 3, limit: 50);

		$this->assertSame(['data' => [], 'pagination' => ['page' => 3]], $response->getData());
	}//end testTheIndexPassesItsPageThrough()
}//end class
