<?php

/**
 * Portaliq public publication search, read the way an anonymous visitor reads it
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Search
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
 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Search;

use OCA\Portaliq\Service\InstanceLoopback;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The public publication search as an anonymous visitor reaches it: one GET
 * to this instance through InstanceLoopback, without a cookie, a token or any
 * header of the incoming request. Whatever the public search would not show
 * a visitor, it does not show here either; that is what keeps a draft out of
 * the suggestion word list.
 *
 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
 */
class PublicPublicationSearch {

	/**
	 * The search the site's search block asks by default.
	 */
	public const ENDPOINT = '/index.php/apps/opencatalogi/api/federation/publications';

	/**
	 * Rows read per page when the word list is built.
	 */
	public const PAGE_SIZE = 200;

	/**
	 * Pages read at most per build.
	 */
	public const MAX_PAGES = 25;

	private const TIMEOUT = 10;

	/**
	 * Constructor.
	 *
	 * @param InstanceLoopback $loopback Calls this instance.
	 * @param LoggerInterface  $logger   Logs a failed read.
	 */
	public function __construct(
		private readonly InstanceLoopback $loopback,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every row the anonymous search lists, page after page.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
	 */
	public function rows(): array {
		$rows = [];
		for ($page = 1; $page <= self::MAX_PAGES; $page++) {
			$body = $this->get(query: ['_limit' => (string)self::PAGE_SIZE, '_page' => (string)$page]);
			if ($body === null) {
				break;
			}

			foreach ((array)($body['results'] ?? []) as $row) {
				if (is_array($row) === true) {
					$rows[] = $row;
				}
			}

			if ($page >= (int)($body['pages'] ?? 1)) {
				break;
			}
		}

		return $rows;
	}//end rows()

	/**
	 * How many results the anonymous search finds for a term, with fuzzy
	 * matching as the block asks it; null when the search did not answer.
	 *
	 * @param string $term The term.
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
	 */
	public function count(string $term): ?int {
		$body = $this->get(query: ['_search' => $term, '_fuzzy' => 'true', '_limit' => '1', '_page' => '1']);
		if ($body === null) {
			return null;
		}

		return (int)($body['total'] ?? 0);
	}//end count()

	/**
	 * One anonymous GET, decoded; null on any failure.
	 *
	 * @param array<string, string> $query The query parameters.
	 *
	 * @return array<string, mixed>|null
	 */
	private function get(array $query): ?array {
		try {
			$response = $this->loopback->request(
				method: 'GET',
				path: self::ENDPOINT.'?'.http_build_query($query),
				options: [
					'timeout'         => self::TIMEOUT,
					'connect_timeout' => self::TIMEOUT,
					'http_errors'     => false,
					'headers'         => ['Accept' => 'application/json'],
				]
			);
		} catch (Throwable $e) {
			$this->logger->info('Portaliq: the public search did not answer', ['reason' => $e->getMessage()]);
			return null;
		}

		if ($response->getStatusCode() !== 200) {
			return null;
		}

		$body = json_decode((string)$response->getBody(), true);

		if (is_array($body) === false) {
			return null;
		}

		return $body;
	}//end get()
}//end class
