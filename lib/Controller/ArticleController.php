<?php

/**
 * Portaliq ArticleController
 *
 * The staff routes of the marketing articles (marketing-articles-from-pipelinq):
 * list, read, create, change, the lifecycle moves and where an article is
 * used. Every method checks the `portal.write-articles` action first.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-portaliq
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\ActionAuthService;
use OCA\Portaliq\Service\Articles\ArticleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Article routes for staff.
 *
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-portaliq
 */
class ArticleController extends Controller {
	use StaffActionGuard;

	/**
	 * The ADR-023 action every method is gated by: write and publish marketing articles.
	 */
	public const ACTION = 'portal.write-articles';

	/**
	 * The fields a client may send; the rest are stamped by the service.
	 *
	 * @var array<int, string>
	 */
	private const BODY_TEXT = ['title', 'slug', 'summary', 'body', 'heroImage', 'language', 'organisation', 'portalPageRef'];

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request     The request.
	 * @param IUserSession      $userSession The signed-in staff member.
	 * @param ActionAuthService $actionAuth  Decides whether they may write articles (ADR-023).
	 * @param ArticleService    $articles    The article write path.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly ArticleService $articles,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * A page of articles, newest first.
	 *
	 * @param int $page  The page, from 1.
	 * @param int $limit The page size, 1 to 100.
	 *
	 * @return JSONResponse `{data, pagination}`.
	 *
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-portaliq
	 */
	#[NoAdminRequired]
	public function index(int $page = 1, int $limit = 20): JSONResponse {
		$this->requireAuthenticatedStaff();

		return new JSONResponse($this->articles->listArticles(page: $page, limit: $limit));
	}//end index()

	/**
	 * One article by id or slug.
	 *
	 * @param string $id The article's id or slug.
	 *
	 * @return JSONResponse `{article}`, or 404.
	 *
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-portaliq
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$this->requireAuthenticatedStaff();

		$article = $this->articles->getArticle(articleId: $id);
		if ($article === null) {
			return new JSONResponse(['error' => ArticleService::NOT_FOUND], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['article' => $article]);
	}//end show()

	/**
	 * Create an article as a draft, by the signed-in staff member.
	 *
	 * @return JSONResponse 201 `{article}`, or 400 naming the field.
	 *
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$uid = $this->requireAuthenticatedStaff();

		$result = $this->articles->createArticle(payload: $this->body(), authorUid: $uid);
		if (isset($result['error']) === true) {
			return new JSONResponse($result, Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['article' => $result['article']], Http::STATUS_CREATED);
	}//end create()

	/**
	 * Change an article's editable fields.
	 *
	 * @param string $id The article's id or slug.
	 *
	 * @return JSONResponse `{article}`, 400 or 404.
	 *
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 */
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		$this->requireAuthenticatedStaff();

		return $this->answer(result: $this->articles->updateArticle(articleId: $id, patch: $this->body()));
	}//end update()

	/**
	 * Publish an article; the first publish stamps the moment.
	 *
	 * @param string $id The article's id or slug.
	 *
	 * @return JSONResponse `{article}`, 400 or 404.
	 *
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
	 */
	#[NoAdminRequired]
	public function publish(string $id): JSONResponse {
		$this->requireAuthenticatedStaff();

		return $this->answer(result: $this->articles->applyTransition(articleId: $id, transition: 'publish'));
	}//end publish()

	/**
	 * Archive an article; it stays readable and every reference stays intact.
	 *
	 * @param string $id The article's id or slug.
	 *
	 * @return JSONResponse `{article}`, 400 or 404.
	 *
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
	 */
	#[NoAdminRequired]
	public function archive(string $id): JSONResponse {
		$this->requireAuthenticatedStaff();

		return $this->answer(result: $this->articles->applyTransition(articleId: $id, transition: 'archive'));
	}//end archive()

	/**
	 * Apply another declared move: submit for review, return to draft, restore.
	 *
	 * @param string $id         The article's id or slug.
	 * @param string $transition The transition's name.
	 *
	 * @return JSONResponse `{article}`, 400 or 404.
	 *
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
	 */
	#[NoAdminRequired]
	public function transition(string $id, string $transition = ''): JSONResponse {
		$this->requireAuthenticatedStaff();

		return $this->answer(result: $this->articles->applyTransition(articleId: $id, transition: $transition));
	}//end transition()

	/**
	 * Where an article is used, asked from every app.
	 *
	 * @param string $id The article's id or slug.
	 *
	 * @return JSONResponse `{data}`, or 404.
	 *
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
	 */
	#[NoAdminRequired]
	public function usages(string $id): JSONResponse {
		$this->requireAuthenticatedStaff();

		$result = $this->articles->listUsages(articleId: $id);
		if (isset($result['error']) === true) {
			return new JSONResponse($result, Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($result);
	}//end usages()

	/**
	 * A service result as a response.
	 *
	 * @param array<string, mixed> $result What the service answered.
	 *
	 * @return JSONResponse
	 */
	private function answer(array $result): JSONResponse {
		if (($result['error'] ?? '') === ArticleService::NOT_FOUND) {
			return new JSONResponse(['error' => ArticleService::NOT_FOUND], Http::STATUS_NOT_FOUND);
		}

		if (isset($result['error']) === true) {
			return new JSONResponse($result, Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['article' => $result['article']]);
	}//end answer()

	/**
	 * The article fields the request carries. `author`, `status`,
	 * `publishedAt`, `legacyRef` and the agent mark are absent by
	 * construction: the service stamps them.
	 *
	 * @return array<string, mixed>
	 */
	private function body(): array {
		$body = [];
		foreach (self::BODY_TEXT as $key) {
			$value = $this->request->getParam($key);
			if (is_scalar($value) === true) {
				$body[$key] = (string)$value;
			}
		}

		foreach (['links', 'tags'] as $key) {
			$value = $this->request->getParam($key);
			if (is_array($value) === true) {
				$body[$key] = $value;
			}
		}

		return $body;
	}//end body()
}//end class
