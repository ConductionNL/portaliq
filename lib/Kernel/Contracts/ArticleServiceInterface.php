<?php

/**
 * Contract of ArticleService
 *
 * The public surface of ArticleService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

/**
 * Contract of ArticleService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ArticleServiceInterface {

	/**
	 * The error a missing article answers with.
	 */
	public const NOT_FOUND = 'not_found';

	/**
	 * A page of articles, newest first.
	 *
	 * @param int $page  1-based page number.
	 * @param int $limit Page size, 1 to 100.
	 *
	 * @return array{data: array<int, array<string, mixed>>, pagination: array{page: int, limit: int, total: int, pages: int}}
	 *
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-portaliq
	 */
	public function listArticles(int $page, int $limit): array;

	/**
	 * One article by id or slug, or null.
	 *
	 * @param string $articleId The article's id or slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 */
	public function getArticle(string $articleId): ?array;

	/**
	 * Create an article as a draft, by the signed-in author.
	 *
	 * @param array<string, mixed> $payload   What the client sent.
	 * @param string               $authorUid The signed-in user.
	 * @param string               $agent     The agent writing, or '' for a person.
	 * @param string               $legacyRef Where a moved article came from, or ''; set by a move, never by a client.
	 *
	 * @return array{article?: array<string, mixed>, error?: string, field?: string}
	 *
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-agent-drafted-article-is-marked-as-such
	 */
	public function createArticle(array $payload, string $authorUid, string $agent = '', string $legacyRef = ''): array;

	/**
	 * Change an article's editable fields.
	 *
	 * @param string               $articleId The article's id or slug.
	 * @param array<string, mixed> $patch     What the client sent.
	 * @param string               $agent     The agent writing, or '' for a person.
	 *
	 * @return array{article?: array<string, mixed>, error?: string, field?: string}
	 *
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-agent-drafted-article-is-marked-as-such
	 */
	public function updateArticle(string $articleId, array $patch, string $agent = ''): array;

	/**
	 * Apply one transition the lifecycle declares. A transition not declared
	 * from the article's current status is refused before anything is written.
	 *
	 * @param string $articleId  The article's id or slug.
	 * @param string $transition The transition's name.
	 *
	 * @return array{article?: array<string, mixed>, error?: string}
	 *
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
	 */
	public function applyTransition(string $articleId, string $transition): array;

	/**
	 * Where an article is used, asked from every app at read time and stored
	 * nowhere. An unused article answers an empty list.
	 *
	 * @param string $articleId The article's id or slug.
	 *
	 * @return array{data?: array<int, array{id: string, title: string, kind: string, app: string, url: string}>, error?: string}
	 *
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
	 */
	public function listUsages(string $articleId): array;
}//end interface
