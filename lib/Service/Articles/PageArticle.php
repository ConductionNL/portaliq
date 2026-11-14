<?php

/**
 * Portaliq PageArticle
 *
 * The article a markdown page names, shaped for the site: its title, summary,
 * hero image and markdown body (marketing-articles-from-pipelinq). A campaign
 * page then carries no copy of the text. Only a published or an archived
 * article is shown, and archiving keeps an article showing on the pages that
 * already name it; a draft or an article under review is never served.
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
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-a-page-can-show-an-article
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Articles;

use OCA\Portaliq\Service\Cms\MediaReferences;
use OCP\IURLGenerator;
use Throwable;

/**
 * Resolves a page's `articleRef` to the article the site renders.
 *
 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-a-page-can-show-an-article
 */
class PageArticle {

	/**
	 * The statuses a page may show.
	 *
	 * @var string[]
	 */
	private const SHOWN = ['published', 'archived'];

	/**
	 * An image an app ships: `app:<app>/<file>`, a file in that app's img/ folder.
	 */
	private const APP_IMAGE = '#^app:([a-z0-9_]+)/([A-Za-z0-9._/-]+)$#';

	/**
	 * Constructor.
	 *
	 * @param ArticleStore    $store The article rows.
	 * @param IURLGenerator   $urls  Resolves an app's image path.
	 * @param MediaReferences $media Resolves media references in the body and hero.
	 */
	public function __construct(
		private readonly ArticleStore $store,
		private readonly IURLGenerator $urls,
		private readonly MediaReferences $media,
	) {
	}//end __construct()

	/**
	 * The article a page names, or null when it names none, the article is
	 * missing, not yet published, or belongs to another organisation.
	 *
	 * @param string $portal       The page's portal.
	 * @param string $ref          The page body's `articleRef`: an article id or slug.
	 * @param string $organisation The serving portal's organisation; empty skips the check.
	 *
	 * @return array{id: string, slug: string, title: string, summary: string, hero: array{url: string, alt: string}|null, markdown: string}|null
	 *
	 * @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-a-page-can-show-an-article
	 */
	public function shown(string $portal, string $ref, string $organisation=''): ?array {
		$ref = trim($ref);
		if ($ref === '') {
			return null;
		}

		$article = $this->store->find(id: $ref);
		if ($article === null || in_array(($article['status'] ?? ''), self::SHOWN, true) === false) {
			return null;
		}

		$owner = (string) ($article['organisation'] ?? '');
		if ($owner !== '' && $organisation !== '' && $owner !== $organisation) {
			return null;
		}

		$title = (string) ($article['title'] ?? '');

		return [
			'id'       => ArticleStore::idOf(row: $article),
			'slug'     => (string) ($article['slug'] ?? ''),
			'title'    => $title,
			'summary'  => (string) ($article['summary'] ?? ''),
			'hero'     => $this->hero(portal: $portal, value: ($article['heroImage'] ?? null), title: $title),
			'markdown' => $this->media->markdown(portal: $portal, markdown: (string) ($article['body'] ?? '')),
		];
	}//end shown()

	/**
	 * The hero image: an app's own image, a media item or an address. A path
	 * in someone's Files is not public, so it shows nothing.
	 *
	 * @param string $portal The page's portal.
	 * @param mixed  $value  The stored hero image.
	 * @param string $title  The article's title, the image's alternative text.
	 *
	 * @return array{url: string, alt: string}|null
	 */
	private function hero(string $portal, mixed $value, string $title): ?array {
		if (is_string($value) === true && preg_match(self::APP_IMAGE, $value, $match) === 1) {
			if (str_contains($match[2], '..') === true) {
				return null;
			}

			try {
				return ['url' => $this->urls->imagePath(appName: $match[1], file: $match[2]), 'alt' => $title];
			} catch (Throwable $e) {
				return null;
			}
		}

		$hero = $this->media->hero(portal: $portal, value: $value);
		if ($hero !== null && $hero['alt'] === '') {
			$hero['alt'] = $title;
		}

		return $hero;
	}//end hero()
}//end class
