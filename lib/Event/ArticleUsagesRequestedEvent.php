<?php

/**
 * Portaliq ArticleUsagesRequestedEvent
 *
 * Dispatched when an article's page asks where the article is used
 * (marketing-articles-from-pipelinq, design decision 3). Every app that
 * references articles listens and adds its own usages; portaliq stores none
 * of them. A listener in another app registers only when this class exists,
 * so the event is the whole contract between them.
 *
 * @category Event
 * @package  OCA\Portaliq\Event
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
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
 */

declare(strict_types=1);

namespace OCA\Portaliq\Event;

use OCP\EventDispatcher\Event;

/**
 * Collects the usages of one article, each `{id, title, kind, app, url}`.
 *
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
 */
class ArticleUsagesRequestedEvent extends Event {

	/**
	 * The most usages one answer carries.
	 */
	private const MAX_USAGES = 200;

	/**
	 * The usages added so far.
	 *
	 * @var array<int, array{id: string, title: string, kind: string, app: string, url: string}>
	 */
	private array $usages = [];

	/**
	 * Constructor.
	 *
	 * @param string $articleId The article's id.
	 * @param string $slug      The article's slug, for an app that names articles by slug.
	 * @param string $legacyRef Where the article came from, for an app that still names it that way.
	 */
	public function __construct(
		private readonly string $articleId,
		private readonly string $slug = '',
		private readonly string $legacyRef = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The article's id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
	 */
	public function getArticleId(): string {
		return $this->articleId;
	}//end getArticleId()

	/**
	 * The article's slug.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
	 */
	public function getSlug(): string {
		return $this->slug;
	}//end getSlug()

	/**
	 * Where the article came from, or ''.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
	 */
	public function getLegacyRef(): string {
		return $this->legacyRef;
	}//end getLegacyRef()

	/**
	 * Add one usage. A usage without an id, a title, a kind or an app is
	 * dropped, as is one past the cap; the link may be empty.
	 *
	 * @param string $id    The using object's id.
	 * @param string $title Its display name.
	 * @param string $kind  What it is, such as `campaignTemplate` or `page`.
	 * @param string $app   The app that owns it.
	 * @param string $url   Where it opens, or ''.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
	 */
	public function addUsage(string $id, string $title, string $kind, string $app, string $url = ''): void {
		$usage = [
			'id' => trim($id),
			'title' => trim($title),
			'kind' => trim($kind),
			'app' => trim($app),
			'url' => trim($url),
		];
		if ($usage['id'] === '' || $usage['title'] === '' || $usage['kind'] === '' || $usage['app'] === '') {
			return;
		}

		if (count($this->usages) >= self::MAX_USAGES) {
			return;
		}

		$this->usages[] = $usage;
	}//end addUsage()

	/**
	 * The usages added, in the order they came.
	 *
	 * @return array<int, array{id: string, title: string, kind: string, app: string, url: string}>
	 *
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
	 */
	public function getUsages(): array {
		return $this->usages;
	}//end getUsages()
}//end class
