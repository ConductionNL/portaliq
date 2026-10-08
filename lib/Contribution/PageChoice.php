<?php

/**
 * Portaliq Page Choice (operate-pages-per-portal-and-client)
 *
 * Two choices over the pages the installed apps contribute. A portal's
 * navigation says per audience which pages show and in which order; it is
 * presentation only. An account's hidden pages are access: the pages go, and
 * so does every collection that only those pages showed.
 *
 * A page is named `<app>:<pageId>`. A page the choice does not name keeps its
 * place after the named ones, so an app installed later is never hidden by an
 * older choice.
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
 * Applies a portal's page choice and an account's hidden pages to an aggregate.
 *
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md
 */
class PageChoice {
	/**
	 * The key a choice uses for one page.
	 *
	 * @param string $app    The contributing app.
	 * @param string $pageId The page's id.
	 *
	 * @return string `<app>:<pageId>`.
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md
	 */
	public static function keyOf(string $app, string $pageId): string {
		return $app . ':' . $pageId;
	}//end keyOf()

	/**
	 * Hide the pages a portal's navigation marks hidden and order the rest as
	 * listed. Pages the list does not name follow the named ones in their own
	 * order. Nothing but the page lists changes: a hidden page stays readable
	 * through the collection routes, because another portal of the same
	 * organisation may show it.
	 *
	 * @param array<string, mixed> $aggregate  The aggregate `{audience, organisation, contributions[]}`.
	 * @param mixed                $navigation The portal's navigation for this audience: `[{page, hidden}]`.
	 *
	 * @return array<string, mixed> The aggregate.
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-portal-shows-the-pages-its-administrator-chose-in-the-chosen-order-req-pgc-001
	 */
	public function arrange(array $aggregate, mixed $navigation): array {
		$listed = $this->listed(navigation: $navigation);
		if ($listed === [] || is_array($aggregate['contributions'] ?? null) === false) {
			return $aggregate;
		}

		$rank = array_flip(array_keys($listed));
		$best = [];
		foreach ($aggregate['contributions'] as $i => $contribution) {
			$app   = (string)($contribution['app'] ?? '');
			$pages = [];
			foreach ((array)($contribution['pages'] ?? []) as $page) {
				$key = self::keyOf(app: $app, pageId: (string)($page['id'] ?? ''));
				if (($listed[$key] ?? false) === true) {
					continue;
				}

				$pages[] = $page;
			}

			// Named pages first, in the listed order; the others after, as they came.
			usort(
				$pages,
				static function (array $a, array $b) use ($rank, $app): int {
					$left  = $rank[self::keyOf(app: $app, pageId: (string)($a['id'] ?? ''))] ?? PHP_INT_MAX;
					$right = $rank[self::keyOf(app: $app, pageId: (string)($b['id'] ?? ''))] ?? PHP_INT_MAX;

					return $left <=> $right;
				}
			);

			if (array_key_exists('pages', $contribution) === true) {
				$aggregate['contributions'][$i]['pages'] = $pages;
			}

			$best[$i] = PHP_INT_MAX;
			foreach ($pages as $page) {
				$best[$i] = min($best[$i], ($rank[self::keyOf(app: $app, pageId: (string)($page['id'] ?? ''))] ?? PHP_INT_MAX));
			}
		}

		$order = array_keys($aggregate['contributions']);
		usort($order, static fn (int $a, int $b): int => [$best[$a], $a] <=> [$best[$b], $b]);
		$aggregate['contributions'] = array_values(array_map(static fn (int $i): array => $aggregate['contributions'][$i], $order));

		return $aggregate;
	}//end arrange()

	/**
	 * Remove the pages an account may not see and the collections only those
	 * pages showed. A collection no page names (an inbox, say) stays.
	 *
	 * @param array<string, mixed> $aggregate The aggregate.
	 * @param mixed                $hidden    The account's hidden pages: `<app>:<pageId>`.
	 *
	 * @return array<string, mixed> The aggregate.
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
	 */
	public function withoutHidden(array $aggregate, mixed $hidden): array {
		$names = [];
		if (is_array($hidden) === true) {
			$names = array_values(array_filter($hidden, 'is_string'));
		}

		$keys = array_flip($names);
		if ($keys === [] || is_array($aggregate['contributions'] ?? null) === false) {
			return $aggregate;
		}

		foreach ($aggregate['contributions'] as $i => $contribution) {
			$app     = (string)($contribution['app'] ?? '');
			$kept    = [];
			$closing = [];
			foreach ((array)($contribution['pages'] ?? []) as $page) {
				if (isset($keys[self::keyOf(app: $app, pageId: (string)($page['id'] ?? ''))]) === true) {
					$closing = array_merge($closing, $this->collectionsOf(page: $page));
					continue;
				}

				$kept[] = $page;
			}

			if (count($kept) === count((array)($contribution['pages'] ?? []))) {
				continue;
			}

			$stillShown = [];
			foreach ($kept as $page) {
				$stillShown = array_merge($stillShown, $this->collectionsOf(page: $page));
			}

			$closed = array_diff($closing, $stillShown);
			$aggregate['contributions'][$i]['pages'] = $kept;
			if ($closed !== [] && is_array($contribution['collections'] ?? null) === true) {
				$aggregate['contributions'][$i]['collections'] = array_values(
					array_filter(
						$contribution['collections'],
						static fn (mixed $collection): bool => in_array((string)($collection['id'] ?? ''), $closed, true) === false
					)
				);
			}
		}

		return $aggregate;
	}//end withoutHidden()

	/**
	 * The collections a page shows: those of its blocks and its record pages.
	 *
	 * @param array<string, mixed> $page The page.
	 *
	 * @return array<int, string>
	 */
	private function collectionsOf(array $page): array {
		$ids = [];
		foreach ((array)($page['blocks'] ?? []) as $block) {
			if (is_array($block) === true && is_string($block['collection'] ?? null) === true) {
				$ids[] = $block['collection'];
			}
		}

		foreach (['record', 'records'] as $key) {
			if (is_array($page[$key] ?? null) === true && is_string($page[$key]['collection'] ?? null) === true) {
				$ids[] = $page[$key]['collection'];
			}
		}

		return array_values(array_unique($ids));
	}//end collectionsOf()

	/**
	 * The choice as `key => hidden`, in the listed order.
	 *
	 * @param mixed $navigation The portal's navigation for the audience.
	 *
	 * @return array<string, bool>
	 */
	private function listed(mixed $navigation): array {
		$out = [];
		if (is_array($navigation) === false) {
			return $out;
		}

		foreach ($navigation as $entry) {
			$page = null;
			if (is_array($entry) === true) {
				$page = ($entry['page'] ?? null);
			}

			if (is_string($page) === true && preg_match('/^[A-Za-z0-9_-]+:[^\s]+$/', $page) === 1 && isset($out[$page]) === false) {
				$out[$page] = (($entry['hidden'] ?? false) === true);
			}
		}

		return $out;
	}//end listed()
}//end class
