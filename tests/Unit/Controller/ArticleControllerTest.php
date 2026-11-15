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
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * marketing-articles-from-pipelinq: the article routes are for staff holding
 * `portal.write-articles`, the author is the session's user, the server
 * stamps what a client may not send, and each refusal has its status.
 *
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md
 */
class ArticleControllerTest extends TestCase {
	use StaffActionDoubleTrait;

	/**
	 * The controller over a service double.
	 *
	 * @param ArticleService       $articles The service.
	 * @param array<string, mixed> $params   The request's parameters.
	 * @param bool                 $granted  Whether the user holds the action.
	 * @param bool                 $signedIn Whether anybody is signed in.
	 *
	 * @return ArticleController
	 */
	private function controller(ArticleService $articles, array $params = [], bool $granted = true, bool $signedIn = true): ArticleController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default));
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('marketer');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn ? $user : null);

		return new ArticleController($request, $session, $this->staffActionAuth(ArticleController::ACTION, $granted), $articles);
	}//end controller()

	public function testCreateSendsOnlyTheWritableFieldsAsTheSignedInAuthor(): void {
		$articles = $this->createMock(ArticleService::class);
		$articles->expects($this->once())
			->method('createArticle')
			->with(['title' => 'Een', 'body' => 'Tekst', 'tags' => ['release']], 'marketer')
			->willReturn(['article' => ['uuid' => 'a-1']]);

		$response = $this->controller($articles, [
			'title' => 'Een',
			'body' => 'Tekst',
			'tags' => ['release'],
			'links' => 'not a list',
			'author' => 'someone-else',
			'status' => 'published',
			'agentAuthored' => true,
			'agentAuthoredBy' => 'forged',
			'legacyRef' => 'pipelinq:forged',
		])->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['article' => ['uuid' => 'a-1']], $response->getData());
	}//end testCreateSendsOnlyTheWritableFieldsAsTheSignedInAuthor()

	public function testARefusedCreateIsABadRequestNamingTheField(): void {
		$articles = $this->createMock(ArticleService::class);
		$articles->method('createArticle')->willReturn(['error' => 'slug_taken', 'field' => 'slug', 'slug' => 'een']);

		$response = $this->controller($articles, ['title' => 'Een'])->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'slug_taken', 'field' => 'slug', 'slug' => 'een'], $response->getData());
	}//end testARefusedCreateIsABadRequestNamingTheField()

	public function testTheLifecycleRoutesApplyTheirTransition(): void {
		$asked    = [];
		$articles = $this->createMock(ArticleService::class);
		$articles->method('applyTransition')->willReturnCallback(
			static function (string $articleId, string $transition) use (&$asked): array {
				$asked[] = [$articleId, $transition];
				return match ($transition) {
					'publish' => ['article' => ['status' => 'published']],
					'archive' => ['error' => ArticleService::NOT_FOUND],
					default => ['error' => 'transition_not_allowed'],
				};
			}
		);

		$controller = $this->controller($articles);
		$published  = $controller->publish('a-1');
		$archived   = $controller->archive('gone');
		$restored   = $controller->transition('a-1', 'restore');

		$this->assertSame([['a-1', 'publish'], ['gone', 'archive'], ['a-1', 'restore']], $asked);
		$this->assertSame([Http::STATUS_OK, ['article' => ['status' => 'published']]], [$published->getStatus(), $published->getData()]);
		$this->assertSame([Http::STATUS_NOT_FOUND, ['error' => 'not_found']], [$archived->getStatus(), $archived->getData()]);
		$this->assertSame([Http::STATUS_BAD_REQUEST, ['error' => 'transition_not_allowed']], [$restored->getStatus(), $restored->getData()]);
	}//end testTheLifecycleRoutesApplyTheirTransition()

	public function testShowUpdateIndexAndUsagesAnswerAsTheServiceDoes(): void {
		$articles = $this->createMock(ArticleService::class);
		$articles->method('getArticle')->willReturnMap([['a-1', ['uuid' => 'a-1']], ['gone', null]]);
		$articles->expects($this->once())->method('updateArticle')->with('a-1', ['summary' => 'Kort'])->willReturn(['article' => ['uuid' => 'a-1', 'summary' => 'Kort']]);
		$articles->expects($this->once())->method('listArticles')->with(2, 5)->willReturn(['data' => [], 'pagination' => ['page' => 2]]);
		$articles->method('listUsages')->willReturnMap([['a-1', ['data' => []]], ['gone', ['error' => ArticleService::NOT_FOUND]]]);

		$controller = $this->controller($articles, ['summary' => 'Kort']);

		$this->assertSame(['article' => ['uuid' => 'a-1']], $controller->show('a-1')->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->show('gone')->getStatus());
		$this->assertSame(['article' => ['uuid' => 'a-1', 'summary' => 'Kort']], $controller->update('a-1')->getData());
		$this->assertSame(['data' => [], 'pagination' => ['page' => 2]], $controller->index(2, 5)->getData());
		$this->assertSame(['data' => []], $controller->usages('a-1')->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->usages('gone')->getStatus());
	}//end testShowUpdateIndexAndUsagesAnswerAsTheServiceDoes()

	public function testEveryRouteRefusesAUserWithoutTheActionOrASession(): void {
		$articles = $this->createMock(ArticleService::class);
		foreach (['listArticles', 'getArticle', 'createArticle', 'updateArticle', 'applyTransition', 'listUsages'] as $method) {
			$articles->expects($this->never())->method($method);
		}

		foreach ([$this->controller($articles, [], false), $this->controller($articles, [], true, false)] as $controller) {
			$calls = [
				fn () => $controller->index(),
				fn () => $controller->show('a-1'),
				fn () => $controller->create(),
				fn () => $controller->update('a-1'),
				fn () => $controller->publish('a-1'),
				fn () => $controller->archive('a-1'),
				fn () => $controller->transition('a-1', 'restore'),
				fn () => $controller->usages('a-1'),
			];
			$refused = 0;
			foreach ($calls as $call) {
				try {
					$call();
				} catch (OCSForbiddenException $e) {
					$refused++;
				}
			}

			$this->assertSame(count($calls), $refused);
		}
	}//end testEveryRouteRefusesAUserWithoutTheActionOrASession()
}//end class
