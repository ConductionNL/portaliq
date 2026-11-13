<?php

/**
 * Portaliq ArticleService
 *
 * The marketing article's write path, ported from pipelinq
 * (marketing-articles-from-pipelinq, design decision 2). It owns what the
 * schema's lifecycle grammar cannot say: the author stamp, slug derivation
 * and uniqueness, the first-publish stamp, a unique `legacyRef`, and that the
 * agent mark is set by the write path and never taken from a request
 * (ADR-088). Where an article is used is asked, not stored.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Articles
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
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Articles;

use DateTimeInterface;
use OCA\Portaliq\Event\ArticleUsagesRequestedEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The article lifecycle and its usages.
 *
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
 *
 * @SuppressWarnings(PHPMD.TooManyMethods) The private slug, identity and
 * lifecycle helpers back one surface; splitting them would only relocate them.
 */
class ArticleService {

	/**
	 * The statuses an article may hold, as the schema's enum.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = ['draft', 'review', 'published', 'archived'];

	/**
	 * The transitions the schema's lifecycle declares, restated so a refusal
	 * happens before a write; the unit tests hold the two to each other.
	 *
	 * @var array<string, array{from: array<int, string>, to: string}>
	 */
	public const TRANSITIONS = [
		'submitForReview' => ['from' => ['draft'], 'to' => 'review'],
		'returnToDraft' => ['from' => ['review'], 'to' => 'draft'],
		'publish' => ['from' => ['draft', 'review'], 'to' => 'published'],
		'archive' => ['from' => ['draft', 'review', 'published'], 'to' => 'archived'],
		'restore' => ['from' => ['archived'], 'to' => 'draft'],
	];

	/**
	 * The text fields a client may set. Anything else in a request is
	 * dropped, so `author`, `status`, `publishedAt` and the agent mark are
	 * only ever set here.
	 *
	 * @var array<int, string>
	 */
	private const WRITABLE_TEXT = ['title', 'slug', 'summary', 'body', 'heroImage', 'language', 'organisation', 'portalPageRef'];

	/**
	 * The list fields a client may set.
	 *
	 * @var array<int, string>
	 */
	private const WRITABLE_LISTS = ['links', 'tags'];

	/**
	 * The error a missing article answers with.
	 */
	public const NOT_FOUND = 'not_found';

	/**
	 * Constructor.
	 *
	 * @param ArticleStore      $store       The article rows.
	 * @param ITimeFactory      $timeFactory The server clock.
	 * @param IEventDispatcher  $dispatcher  Asks the other apps for their usages.
	 * @param LoggerInterface   $logger      The logger.
	 */
	public function __construct(
		private readonly ArticleStore $store,
		private readonly ITimeFactory $timeFactory,
		private readonly IEventDispatcher $dispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

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
	public function listArticles(int $page, int $limit): array {
		$page  = max(1, $page);
		$limit = min(100, max(1, $limit));
		$all   = $this->store->findAll();
		usort(
			$all,
			static fn (array $left, array $right): int => strcmp((string)($right['createdAt'] ?? ''), (string)($left['createdAt'] ?? ''))
		);
		$total = count($all);

		return [
			'data' => array_slice($all, (($page - 1) * $limit), $limit),
			'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'pages' => max(1, (int)ceil($total / $limit))],
		];
	}//end listArticles()

	/**
	 * One article by id or slug, or null.
	 *
	 * @param string $articleId The article's id or slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
	 */
	public function getArticle(string $articleId): ?array {
		$found = $this->store->find(id: $articleId);
		if ($found !== null) {
			return $found;
		}

		foreach ($this->store->findAll() as $row) {
			if ($articleId !== '' && (string)($row['slug'] ?? '') === $articleId) {
				return $row;
			}
		}

		return null;
	}//end getArticle()

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
	public function createArticle(array $payload, string $authorUid, string $agent = '', string $legacyRef = ''): array {
		$clean = $this->sanitise(payload: $payload);
		$title = trim((string)($clean['title'] ?? ''));
		if ($title === '') {
			return ['error' => 'title_required', 'field' => 'title'];
		}

		$slug = $this->resolveSlug(candidate: (string)($clean['slug'] ?? ''), title: $title);
		if ($this->taken(field: 'slug', value: $slug, exceptId: '') === true) {
			return ['error' => 'slug_taken', 'field' => 'slug', 'slug' => $slug];
		}

		$legacyRef = trim($legacyRef);
		if ($legacyRef !== '' && $this->taken(field: 'legacyRef', value: $legacyRef, exceptId: '') === true) {
			return ['error' => 'legacy_ref_taken', 'field' => 'legacyRef'];
		}

		$now    = $this->now();
		$record = array_merge(
			$clean,
			[
				'title' => $title,
				'slug' => $slug,
				'status' => 'draft',
				'author' => $authorUid,
				'agentAuthored' => ($agent !== ''),
				'agentAuthoredBy' => $agent,
				'createdAt' => $now,
				'updatedAt' => $now,
			]
		);
		if ($legacyRef !== '') {
			$record['legacyRef'] = $legacyRef;
		}

		$saved = $this->store->save(payload: $record);
		if ($saved === null) {
			return ['error' => 'not_saved'];
		}

		return ['article' => $saved];
	}//end createArticle()

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
	public function updateArticle(string $articleId, array $patch, string $agent = ''): array {
		$existing = $this->getArticle(articleId: $articleId);
		if ($existing === null) {
			return ['error' => self::NOT_FOUND];
		}

		$clean = $this->sanitise(payload: $patch);
		if (array_key_exists('title', $clean) === true && trim((string)$clean['title']) === '') {
			return ['error' => 'title_required', 'field' => 'title'];
		}

		if (array_key_exists('slug', $clean) === true) {
			$slug = $this->resolveSlug(candidate: (string)$clean['slug'], title: (string)($clean['title'] ?? ($existing['title'] ?? '')));
			if ($this->taken(field: 'slug', value: $slug, exceptId: ArticleStore::idOf(row: $existing)) === true) {
				return ['error' => 'slug_taken', 'field' => 'slug', 'slug' => $slug];
			}

			$clean['slug'] = $slug;
		}

		$merged = array_merge($existing, $clean);
		$merged['updatedAt']       = $this->now();
		$merged['agentAuthored']   = ($agent !== '');
		$merged['agentAuthoredBy'] = $agent;

		return $this->saved(existing: $existing, merged: $merged);
	}//end updateArticle()

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
	public function applyTransition(string $articleId, string $transition): array {
		$rule = (self::TRANSITIONS[$transition] ?? null);
		if ($rule === null) {
			return ['error' => 'unknown_transition'];
		}

		$existing = $this->getArticle(articleId: $articleId);
		if ($existing === null) {
			return ['error' => self::NOT_FOUND];
		}

		if (in_array($this->statusOf(article: $existing), $rule['from'], true) === false) {
			return ['error' => 'transition_not_allowed'];
		}

		$merged = $existing;
		$merged['status']    = $rule['to'];
		$merged['updatedAt'] = $this->now();
		if ($rule['to'] === 'published' && trim((string)($existing['publishedAt'] ?? '')) === '') {
			// The first publish only: a second never moves the moment a reader saw.
			$merged['publishedAt'] = $merged['updatedAt'];
		}

		return $this->saved(existing: $existing, merged: $merged);
	}//end applyTransition()

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
	public function listUsages(string $articleId): array {
		$article = $this->getArticle(articleId: $articleId);
		if ($article === null) {
			return ['error' => self::NOT_FOUND];
		}

		$event = new ArticleUsagesRequestedEvent(
			articleId: ArticleStore::idOf(row: $article),
			slug: (string)($article['slug'] ?? ''),
			legacyRef: (string)($article['legacyRef'] ?? '')
		);
		try {
			$this->dispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			// One app's listener failing leaves the others' answers standing.
			$this->logger->warning('Portaliq: an article usage listener failed', ['reason' => $e->getMessage()]);
		}

		return ['data' => $event->getUsages()];
	}//end listUsages()

	/**
	 * Save the merged article over the stored one.
	 *
	 * @param array<string, mixed> $existing The stored article.
	 * @param array<string, mixed> $merged   What to store.
	 *
	 * @return array{article?: array<string, mixed>, error?: string}
	 */
	private function saved(array $existing, array $merged): array {
		$saved = $this->store->save(payload: $merged, id: ArticleStore::idOf(row: $existing));
		if ($saved === null) {
			return ['error' => 'not_saved'];
		}

		return ['article' => $saved];
	}//end saved()

	/**
	 * An article's status; an unknown one counts as a draft.
	 *
	 * @param array<string, mixed> $article The article.
	 *
	 * @return string
	 */
	private function statusOf(array $article): string {
		$status = (string)($article['status'] ?? 'draft');
		if (in_array($status, self::STATUSES, true) === false) {
			return 'draft';
		}

		return $status;
	}//end statusOf()

	/**
	 * The writable part of a request: text fields trimmed, list fields only
	 * when they are lists.
	 *
	 * @param array<string, mixed> $payload What the client sent.
	 *
	 * @return array<string, mixed>
	 */
	private function sanitise(array $payload): array {
		$clean = [];
		foreach (self::WRITABLE_TEXT as $key) {
			if (array_key_exists($key, $payload) === true && is_scalar($payload[$key]) === true) {
				$clean[$key] = trim((string)$payload[$key]);
			}
		}

		foreach (self::WRITABLE_LISTS as $key) {
			if (isset($payload[$key]) === true && is_array($payload[$key]) === true) {
				$clean[$key] = array_values($payload[$key]);
			}
		}

		return $clean;
	}//end sanitise()

	/**
	 * The slug to store: the candidate when usable, else one from the title.
	 *
	 * @param string $candidate The client's slug, maybe empty.
	 * @param string $title     The title.
	 *
	 * @return string
	 */
	private function resolveSlug(string $candidate, string $title): string {
		foreach ([$candidate, $title] as $source) {
			$slug = $this->slugify(value: $source);
			if ($slug !== '') {
				return $slug;
			}
		}

		return 'article-' . substr(sha1($title . $this->now()), 0, 8);
	}//end resolveSlug()

	/**
	 * A string reduced to the schema's slug pattern, at most 255 characters.
	 *
	 * @param string $value The raw value.
	 *
	 * @return string The slug, or '' when nothing survives.
	 */
	private function slugify(string $value): string {
		$ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $value);
		if ($ascii === false) {
			$ascii = $value;
		}

		$dashed = preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii));
		if (is_string($dashed) === false) {
			return '';
		}

		return trim(substr(trim($dashed, '-'), 0, 255), '-');
	}//end slugify()

	/**
	 * Whether another article already holds a value of a unique field.
	 * OpenRegister does not hold a property unique, so the check runs here.
	 *
	 * @param string $field    `slug` or `legacyRef`.
	 * @param string $value    The value claimed.
	 * @param string $exceptId The article allowed to hold it already.
	 *
	 * @return bool
	 */
	private function taken(string $field, string $value, string $exceptId): bool {
		foreach ($this->store->findAll() as $row) {
			if ((string)($row[$field] ?? '') !== $value) {
				continue;
			}

			if ($exceptId !== '' && ArticleStore::idOf(row: $row) === $exceptId) {
				continue;
			}

			return true;
		}

		return false;
	}//end taken()

	/**
	 * The current moment, ISO 8601 in UTC.
	 *
	 * @return string
	 */
	private function now(): string {
		return $this->timeFactory->getDateTime('now', new \DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
	}//end now()
}//end class
