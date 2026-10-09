<?php

/**
 * Portaliq Public Index Items (portal-public-catalogue)
 *
 * An app MAY offer a portal's visitors an index of its public things (a
 * course with its next dates, a programme, a school day on the calendar)
 * through an optional provider method `getPublicIndex(string $portal): array`.
 * The app decides what is public; portaliq holds every answer to one fixed
 * shape and drops what does not fit, so nothing an app returns reaches a
 * visitor as it came:
 *
 *     {id, type, kind, title, summary?, date?, endDate?, dateLabel?, meta?[],
 *      facets?{label: value}, category?, cells?{column: text}, slug?, note?,
 *      noteTone?, href?, badge?}
 *
 * `type` is a machine key the page filters on (`course`, `programme`,
 * `event`); `kind` is the word a visitor reads ("Cursus"). `href` is a path
 * inside the site or an http(s) address, nothing else.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-an-app-may-offer-a-portal-an-index-of-its-public-things
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Holds a public index answer to its fixed shape.
 *
 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-an-app-may-offer-a-portal-an-index-of-its-public-things
 */
class PublicIndexItems {
	/**
	 * The contract method an app may implement.
	 */
	public const METHOD = 'getPublicIndex';

	/**
	 * The most items one app answers for one portal.
	 */
	public const MAX_ITEMS = 500;

	/**
	 * The longest summary.
	 */
	private const MAX_SUMMARY = 600;

	/**
	 * The tones a note may have.
	 */
	private const TONES = ['neutral', 'positive', 'warning'];

	/**
	 * Constructor.
	 *
	 * @param PublicIndexText $clean The text and list cleaning.
	 */
	public function __construct(
		private readonly PublicIndexText $clean = new PublicIndexText(),
	) {
	}//end __construct()

	/**
	 * Every item of one app's answer that fits, each prefixed with the app's
	 * id so two apps never share an id.
	 *
	 * @param string $appId   The answering app.
	 * @param mixed  $entries The provider's answer.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-an-app-may-offer-a-portal-an-index-of-its-public-things
	 */
	public function items(string $appId, mixed $entries): array {
		if (is_array($entries) === false) {
			return [];
		}

		$out  = [];
		$seen = [];
		foreach (array_slice(array_values($entries), 0, self::MAX_ITEMS) as $entry) {
			$item = $this->item(entry: $entry);
			if ($item === null) {
				continue;
			}

			$item['id'] = $appId . ':' . $item['id'];
			if (isset($seen[$item['id']]) === true) {
				continue;
			}

			$seen[$item['id']] = true;
			$out[]             = $item;
		}

		return $out;
	}//end items()

	/**
	 * One item, or null without an id, a type, a kind or a title.
	 *
	 * @param mixed $entry The entry.
	 *
	 * @return array<string, mixed>|null
	 */
	private function item(mixed $entry): ?array {
		if (is_array($entry) === false) {
			return null;
		}

		$id    = $this->clean->short(value: ($entry['id'] ?? null));
		$type  = ($entry['type'] ?? null);
		$kind  = $this->clean->short(value: ($entry['kind'] ?? null));
		$title = $this->clean->short(value: ($entry['title'] ?? null));
		if ($id === null || is_string($type) === false || preg_match('/^[a-z][A-Za-z0-9]{0,39}$/', $type) !== 1 || $kind === null || $title === null) {
			return null;
		}

		$item = ['id' => $id, 'type' => $type, 'kind' => $kind, 'title' => $title];
		$item = $this->withText(item: $item, entry: $entry);
		$item = $this->withDates(item: $item, entry: $entry);
		$item = $this->withLists(item: $item, entry: $entry);

		return $this->withLink(item: $item, entry: $entry);
	}//end item()

	/**
	 * The summary, note, note tone, badge and date label, each when it fits.
	 *
	 * @param array<string, mixed> $item  The item so far.
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return array<string, mixed>
	 */
	private function withText(array $item, array $entry): array {
		$item = $this->withAddress(item: $item, entry: $entry);

		$summary = $this->clean->text(value: ($entry['summary'] ?? null), max: self::MAX_SUMMARY);
		if ($summary !== null) {
			$item['summary'] = $summary;
		}

		foreach (['note', 'badge', 'dateLabel'] as $key) {
			$value = $this->clean->short(value: ($entry[$key] ?? null));
			if ($value !== null) {
				$item[$key] = $value;
			}
		}

		if (isset($item['note']) === true) {
			$item['noteTone'] = 'neutral';
			if (in_array(($entry['noteTone'] ?? null), self::TONES, true) === true) {
				$item['noteTone'] = $entry['noteTone'];
			}
		}

		return $item;
	}//end withText()

	/**
	 * The slug, category and table cells of an item, each when it fits.
	 *
	 * @param array<string, mixed> $item  The item so far.
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return array<string, mixed>
	 */
	private function withAddress(array $item, array $entry): array {
		// The address part of the item's detail page: a plain slug, else none.
		$slug = ($entry['slug'] ?? null);
		if (is_string($slug) === true && preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $slug) === 1) {
			$item['slug'] = $slug;
		}

		// One category and the cells of a table row (editor-blocks-read-public-app-data).
		$category = $this->clean->short(value: ($entry['category'] ?? null));
		if ($category !== null) {
			$item['category'] = $category;
		}

		$cells = $this->clean->cells(declared: ($entry['cells'] ?? null));
		if ($cells !== []) {
			$item['cells'] = $cells;
		}

		return $item;
	}//end withAddress()

	/**
	 * `date` and `endDate`: an ISO day or moment, kept only when it parses.
	 *
	 * @param array<string, mixed> $item  The item so far.
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return array<string, mixed>
	 */
	private function withDates(array $item, array $entry): array {
		foreach (['date', 'endDate'] as $key) {
			$value = ($entry[$key] ?? null);
			if (is_string($value) === true
				&& preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?)?$/', $value) === 1
				&& strtotime($value) !== false
			) {
				$item[$key] = $value;
			}
		}

		return $item;
	}//end withDates()

	/**
	 * `meta` (short lines) and `facets` (label to one value or a list of values).
	 *
	 * @param array<string, mixed> $item  The item so far.
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return array<string, mixed>
	 */
	private function withLists(array $item, array $entry): array {
		$meta = $this->clean->meta(declared: ($entry['meta'] ?? []));
		if ($meta !== []) {
			$item['meta'] = $meta;
		}

		$facets = $this->clean->facets(declared: ($entry['facets'] ?? null));
		if ($facets !== []) {
			$item['facets'] = $facets;
		}

		return $item;
	}//end withLists()

	/**
	 * `href`: a path inside the site (one slash, no scheme) or an http(s)
	 * address; anything else is no link.
	 *
	 * @param array<string, mixed> $item  The item so far.
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return array<string, mixed>
	 */
	private function withLink(array $item, array $entry): array {
		$href = $this->clean->short(value: ($entry['href'] ?? null));
		if ($href !== null && (preg_match('#^/(?!/)[^\s]*$#', $href) === 1 || preg_match('#^https?://[^\s/]+[^\s]*$#i', $href) === 1)) {
			$item['href'] = $href;
		}

		return $item;
	}//end withLink()

}//end class
