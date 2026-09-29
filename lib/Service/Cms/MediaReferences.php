<?php

/**
 * Portaliq media references
 *
 * Turns a page's media:<id> references into the public address of a library
 * item, with its alternative text.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Cms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-page-seo-history-and-media/specs/site-page-seo-history-and-media/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use Closure;
use OCP\IURLGenerator;

/**
 * Resolves media:<id> in a page's hero image, share image and markdown.
 *
 * A page stores the reference, never a copy, so replacing an item's file
 * updates every page using it. Only an item the caller hands in resolves: the
 * caller reads the published items of the page's OWN portal, so a draft item
 * or another portal's item resolves to nothing.
 */
class MediaReferences {

	/**
	 * The reference prefix.
	 */
	public const PREFIX = 'media:';

	/**
	 * Constructor.
	 *
	 * @param IURLGenerator $urls Builds the item's public address.
	 */
	public function __construct(
		private readonly IURLGenerator $urls,
	) {
	}//end __construct()

	/**
	 * Whether a stored value refers to a library item.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return bool
	 */
	public static function isReference(mixed $value): bool {
		return is_string($value) === true && str_starts_with($value, self::PREFIX) === true;
	}//end isReference()

	/**
	 * The public address of an item.
	 *
	 * @param string $portal The portal slug.
	 * @param string $id     The item id.
	 *
	 * @return string
	 */
	public function address(string $portal, string $id): string {
		return $this->urls->linkToRouteAbsolute('portaliq.contentMedia.show', ['id' => $id, 'portal' => $portal]);
	}//end address()

	/**
	 * The hero image of a page: `{url, alt}`, or null when there is none.
	 *
	 * @param string  $portal The page's portal.
	 * @param mixed   $value  The stored heroImage.
	 * @param Closure $items  Returns the portal's published items by id; called only for a reference.
	 *
	 * @return array{url: string, alt: string}|null
	 */
	public function hero(string $portal, mixed $value, Closure $items): ?array {
		if (self::isReference($value) === true) {
			$item = ($items()[substr((string) $value, strlen(self::PREFIX))] ?? null);
			if ($item === null) {
				return null;
			}

			return ['url' => $this->address(portal: $portal, id: $item['id']), 'alt' => $item['alt']];
		}

		if (is_string($value) === true && preg_match('#^https?://#i', $value) === 1) {
			return ['url' => $value, 'alt' => ''];
		}

		return null;
	}//end hero()

	/**
	 * A single image field: the item's address for a reference, '' for an
	 * unresolved one, the value itself otherwise.
	 *
	 * @param string  $portal The page's portal.
	 * @param string  $value  The stored value.
	 * @param Closure $items  Returns the portal's published items by id.
	 *
	 * @return string
	 */
	public function image(string $portal, string $value, Closure $items): string {
		if (self::isReference($value) === false) {
			return $value;
		}

		$hero = $this->hero(portal: $portal, value: $value, items: $items);

		return ($hero['url'] ?? '');
	}//end image()

	/**
	 * Markdown with every `](media:<id>)` link target resolved; an unresolved
	 * one becomes an empty target.
	 *
	 * @param string  $portal   The page's portal.
	 * @param string  $markdown The markdown source.
	 * @param Closure $items    Returns the portal's published items by id.
	 *
	 * @return string
	 */
	public function markdown(string $portal, string $markdown, Closure $items): string {
		if (str_contains($markdown, '](' . self::PREFIX) === false) {
			return $markdown;
		}

		return (string) preg_replace_callback(
			'/\]\(media:([A-Za-z0-9-]+)\)/',
			fn (array $match): string => '](' . $this->image(portal: $portal, value: self::PREFIX . $match[1], items: $items) . ')',
			$markdown
		);
	}//end markdown()
}//end class
