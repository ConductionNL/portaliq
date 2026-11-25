<?php

/**
 * What one plain page request asks for.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Cms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.4
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

/**
 * The route, the trailing segment a parent page gets, the search term, the
 * page number, the language, the addresses and the record id of one plain
 * page request.
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-the-server-renders-a-plain-version-of-every-public-page-req-shj-002
 */
class PlainContext {

	/**
	 * The request's `_search`.
	 *
	 * @var string
	 */
	public readonly string $query;

	/**
	 * The request's `_page`, from 1.
	 *
	 * @var int
	 */
	public readonly int $page;

	/**
	 * The request's `record`: the id a public records block shows; else ''.
	 *
	 * @var string
	 */
	public readonly string $record;

	/**
	 * Constructor.
	 *
	 * @param string       $route      The route asked for.
	 * @param string       $routeParam The trailing segment, when a parent page answers; else ''.
	 * @param PlainQuery   $asked      The request's search, page and record.
	 * @param PlainAddress $address    The addresses.
	 * @param string       $locale     The document language.
	 * @param list<array{label: string, href: string, items: list<array{label: string, href: string}>}> $menu The main menu as plain links.
	 */
	public function __construct(
		public readonly string $route,
		public readonly string $routeParam,
		private readonly PlainQuery $asked,
		public readonly PlainAddress $address,
		public readonly string $locale,
		public readonly array $menu = [],
	) {
		$this->query = $asked->query;
		$this->page = $asked->page;
		$this->record = $asked->record;
	}//end __construct()

	/**
	 * The full page of this request's route, with its record, search and page.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.4
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
	 */
	public function fullUrl(): string {
		return $this->address->full(route: $this->route, extra: $this->asked->params());
	}//end fullUrl()
}//end class
