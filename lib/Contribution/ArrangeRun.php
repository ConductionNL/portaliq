<?php

/**
 * Portaliq Arrange Run (operate-pages-per-portal-and-client)
 *
 * One arrangement of an aggregate's pages by a portal's choice: which pages
 * the choice hides and where it ranks the ones it names.
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
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * The choice of one PageChoice::arrange() call. Never leaves PageChoice.
 *
 * @internal
 *
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-portal-shows-the-pages-its-administrator-chose-in-the-chosen-order-req-pgc-001
 */
final class ArrangeRun {
	/**
	 * Constructor.
	 *
	 * @param array<string, bool> $listed The choice as `key => hidden`, in the listed order.
	 * @param array<string, int>  $rank   Each listed key's place in the list.
	 */
	public function __construct(
		public readonly array $listed,
		public readonly array $rank,
	) {
	}//end __construct()

	/**
	 * A contribution with its hidden pages gone and the rest in the listed
	 * order; one without a page list is returned as it came.
	 *
	 * @param array<string, mixed> $contribution The contribution.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-portal-shows-the-pages-its-administrator-chose-in-the-chosen-order-req-pgc-001
	 */
	public function arranged(array $contribution): array {
		if (array_key_exists('pages', $contribution) === false) {
			return $contribution;
		}

		$app   = (string)($contribution['app'] ?? '');
		$pages = array_values(
			array_filter(
				(array)$contribution['pages'],
				fn (mixed $page): bool => ($this->listed[$this->keyOf(app: $app, pageId: (string)($page['id'] ?? ''))] ?? false) !== true
			)
		);

		// Named pages first, in the listed order; the others after, as they came.
		usort($pages, fn (array $a, array $b): int => $this->rankOf(app: $app, page: $a) <=> $this->rankOf(app: $app, page: $b));
		$contribution['pages'] = $pages;

		return $contribution;
	}//end arranged()

	/**
	 * The best place any page of an arranged contribution has in the list.
	 *
	 * @param array<string, mixed> $contribution The arranged contribution.
	 *
	 * @return int PHP_INT_MAX when no page of it is listed.
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-portal-shows-the-pages-its-administrator-chose-in-the-chosen-order-req-pgc-001
	 */
	public function bestOf(array $contribution): int {
		$best = PHP_INT_MAX;
		$app  = (string)($contribution['app'] ?? '');
		foreach ((array)($contribution['pages'] ?? []) as $page) {
			$best = min($best, $this->rankOf(app: $app, page: $page));
		}

		return $best;
	}//end bestOf()

	/**
	 * One page's place in the list.
	 *
	 * @param string $app  The contributing app.
	 * @param mixed  $page The page.
	 *
	 * @return int PHP_INT_MAX when the list does not name it.
	 */
	private function rankOf(string $app, mixed $page): int {
		return ($this->rank[$this->keyOf(app: $app, pageId: (string)($page['id'] ?? ''))] ?? PHP_INT_MAX);
	}//end rankOf()

	/**
	 * The key the choice uses for one page, as PageChoice::keyOf() builds it.
	 *
	 * @param string $app    The contributing app.
	 * @param string $pageId The page's id.
	 *
	 * @return string `<app>:<pageId>`.
	 */
	private function keyOf(string $app, string $pageId): string {
		return $app . ':' . $pageId;
	}//end keyOf()
}//end class
