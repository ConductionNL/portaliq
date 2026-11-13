<?php

/**
 * Portaliq anonymous publication search
 *
 * The public publication search as an anonymous visitor runs it, from the
 * server: OpenCatalogi's federation endpoint, called on this instance with no
 * session, no cookie and no bearer. What it answers is what a visitor could
 * have found, and nothing else.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
use Throwable;

/**
 * Runs the public publication search anonymously.
 *
 * The request carries nothing that identifies anybody, so the endpoint can
 * only answer with what it shows the public. A failure answers no rows rather
 * than throwing: an empty word list and a withheld suggestion are the safe
 * outcomes.
 */
class AnonymousPublicationSearch {

	/**
	 * The endpoint the public search block queries by default.
	 */
	public const ENDPOINT = '/index.php/apps/opencatalogi/api/federation/publications';

	/**
	 * Seconds one call may take.
	 */
	private const TIMEOUT = 10;

	/**
	 * Constructor.
	 *
	 * @param InstanceLoopback $loopback Calls this instance from inside the server.
	 */
	public function __construct(
		private readonly InstanceLoopback $loopback,
	) {
	}//end __construct()

	/**
	 * One page of the anonymous search.
	 *
	 * @param string $term  The search term, empty for every public publication.
	 * @param int    $page  The 1-based page.
	 * @param int    $limit Rows per page.
	 *
	 * @return array{results: list<array<string, mixed>>, total: int} The rows and the total count.
	 *
	 * @spec openspec/changes/search-sort-by-relevance/specs/portal-federated-search/spec.md#requirement-a-search-that-finds-little-offers-a-checked-correction-req-ssr-005
	 */
	public function search(string $term, int $page=1, int $limit=100): array {
		$query = ['_limit' => max(1, $limit), '_page' => max(1, $page)];
		$term  = trim($term);
		if ($term !== '') {
			// The same flags the search block sends with a term (REQ-SSR-004),
			// so a checked suggestion is checked against the search the
			// visitor will actually run.
			$query['_search'] = $term;
			$query['_fuzzy']  = 'true';
		}

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
		} catch (Throwable) {
			return ['results' => [], 'total' => 0];
		}

		if ($response->getStatusCode() !== 200) {
			return ['results' => [], 'total' => 0];
		}

		return $this->decode(body: $response->getBody());
	}//end search()

	/**
	 * The rows and total of one answer.
	 *
	 * @param mixed $body The response body.
	 *
	 * @return array{results: list<array<string, mixed>>, total: int} The rows and the total count.
	 */
	private function decode(mixed $body): array {
		if (is_string($body) === false) {
			return ['results' => [], 'total' => 0];
		}

		$decoded = json_decode($body, true);
		if (is_array($decoded) === false || is_array($decoded['results'] ?? null) === false) {
			return ['results' => [], 'total' => 0];
		}

		$rows = array_values(array_filter($decoded['results'], 'is_array'));
		$total = count($rows);
		if (is_numeric($decoded['total'] ?? null) === true) {
			$total = (int)$decoded['total'];
		}

		return ['results' => $rows, 'total' => $total];
	}//end decode()
}//end class
