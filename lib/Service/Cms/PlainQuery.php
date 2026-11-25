<?php

/**
 * What a plain page request asks of its page: the search term, the page number and the record.
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
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

/**
 * The request's `_search`, `_page` (from 1) and `record` (the id a public
 * records block shows), and the same three as address parameters.
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-the-server-renders-a-plain-version-of-every-public-page-req-shj-002
 */
class PlainQuery {

	/**
	 * The page number, at least 1.
	 *
	 * @var int
	 */
	public readonly int $page;

	/**
	 * Constructor.
	 *
	 * @param string $query  The request's `_search`.
	 * @param int    $page   The request's `_page`; below 1 is 1.
	 * @param string $record The request's `record`; else ''.
	 */
	public function __construct(
		public readonly string $query = '',
		int $page = 1,
		public readonly string $record = '',
	) {
		$this->page = max(1, $page);
	}//end __construct()

	/**
	 * `record`, `_search` and `_page` as address parameters; an empty one
	 * and page 1 are left out.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
	 */
	public function params(): array {
		$params = [];
		if ($this->record !== '') {
			$params['record'] = $this->record;
		}

		if ($this->query !== '') {
			$params['_search'] = $this->query;
		}

		if ($this->page > 1) {
			$params['_page'] = (string)$this->page;
		}

		return $params;
	}//end params()
}//end class
