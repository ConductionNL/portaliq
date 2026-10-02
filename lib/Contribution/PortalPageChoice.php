<?php
/**
 * Portaliq Portal Page Choice (operate-pages-per-portal-and-client)
 *
 * Two choices over the pages the installed apps contribute, each named
 * `<app>:<pageId>`:
 *
 * - A portal's navigation list (`portal.navigation`, per audience): hidden
 *   pages leave that portal's menu, listed pages come first in the listed
 *   order, unlisted pages follow in the order the apps gave them. It is
 *   presentation: the collections stay, because the same subject may use
 *   another portal of the organisation where the page is shown (design D1).
 * - A client account's hidden pages (`portalAccount.hiddenPages`): those pages
 *   leave the aggregate, and with them every collection that only they
 *   showed, so the collection, object and action routes refuse it for that
 *   account as they refuse an undeclared one. A collection on no page at all
 *   (an inbox) is left alone: hiding a page never closes what it never showed.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Applies a portal's menu choice and a client's page choice to an aggregate.
 *
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md
 */
class PortalPageChoice {

	/**
	 * The navigation list a portal declares for one audience, or [].
	 *
	 * @param array<string, mixed>|null $portal The serving portal, or null.
	 * @param string $audience The subject's audience.
	 *
	 * @return array<int, array<string, mixed>> The list, in the chosen order.
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-portal-shows-the-pages-its-administrator-chose-in-the-chosen-order-req-pgc-001
	 */
	public function navigationFor(?array $portal, string $audience): array {
		$navigation = ($portal['navigation'] ?? null);
		if (is_array($navigation) === false || is_array($navigation[$audience] ?? null) === false) {
			return [];
		}

		return array_values(array_filter($navigation[$audience], 'is_array'));
	}//end navigationFor()

	/**
	 * The serving portal's menu choice for one audience, applied.
	 *
	 * @param array<int, array<string, mixed>> $contributions The aggregate's contributions.
	 * @param array<string, mixed>|null $portal The serving portal, or null.
	 * @param string $audience The subject's audience.
	 *
	 * @return array<int, array<string, mixed>> The contributions.
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-portal-shows-the-pages-its-administrator-chose-in-the-chosen-order-req-pgc-001
	 */
	public function forPortal(array $contributions, ?array $portal, string $audience): array {
		return $this->applyNavigation(contributions: $contributions, navigation: $this->navigationFor(portal: $portal, audience: $audience));
	}//end forPortal()

	/**
	 * Drop the hidden pages and order the rest by the portal's list.
	 *
	 * Pages are ordered inside each contribution, and the contributions by
	 * the first listed position of their pages, so the flattened menu follows
	 * the list as far as one app's pages stay together.
	 *
	 * @param array<int, array<string, mixed>> $contributions The aggregate's contributions.
	 * @param array<int, array<string, mixed>> $navigation The list for the subject's audience.
	 *
	 * @return array<int, array<string, mixed>> The contributions.
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-portal-shows-the-pages-its-administrator-chose-in-the-chosen-order-req-pgc-001
	 */
	public function applyNavigation(array $contributions, array $navigation): array {
		if ($navigation === []) {
			return $contributions;
		}

		$position = [];
		$hidden   = [];
		foreach ($navigation as $index => $entry) {
			$name = (string)($entry['page'] ?? '');
			if ($name === '') {
				continue;
			}

			$position[$name] = $index;
			if (($entry['hidden'] ?? false) === true) {
				$hidden[] = $name;
			}
		}

		$rank = [];
		foreach ($contributions as $index => $contribution) {
			$app   = (string)($contribution['app'] ?? '');
			$pages = $this->withoutPages(pages: ($contribution['pages'] ?? []), app: $app, names: $hidden);
			$pages = $this->ordered(pages: $pages, app: $app, position: $position);
			$contributions[$index]['pages'] = $pages;
			$rank[$index] = $this->firstPosition(pages: $pages, app: $app, position: $position);
		}

		// Stable: equal ranks keep the order the apps gave them.
		$keys = array_keys($contributions);
		usort(
			$keys,
			fn (int $a, int $b): int => [$rank[$a], $a] <=> [$rank[$b], $b]
		);

		return array_map(fn (int $key): array => $contributions[$key], $keys);
	}//end applyNavigation()

	/**
	 * Drop an account's hidden pages and the collections only they showed.
	 *
	 * @param array<int, array<string, mixed>> $contributions The aggregate's contributions.
	 * @param array<int, string> $hiddenPages The account's hidden pages, `<app>:<pageId>`.
	 *
	 * @return array<int, array<string, mixed>> The contributions.
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
	 */
	public function hideForAccount(array $contributions, array $hiddenPages): array {
		$names = array_values(array_filter($hiddenPages, fn (mixed $name): bool => is_string($name) === true && $name !== ''));
		if ($names === []) {
			return $contributions;
		}

		foreach ($contributions as $index => $contribution) {
			$app   = (string)($contribution['app'] ?? '');
			$pages = $this->listOf(value: ($contribution['pages'] ?? null));
			$kept  = $this->withoutPages(pages: $pages, app: $app, names: $names);
			if (count($kept) === count($pages)) {
				continue;
			}

			$closed = array_diff($this->shownBy(pages: $pages), $this->shownBy(pages: $kept));
			$contributions[$index]['pages'] = $kept;
			$contributions[$index]['collections'] = array_values(
				array_filter(
					$this->listOf(value: ($contribution['collections'] ?? null)),
					fn (mixed $collection): bool => in_array((string)($collection['id'] ?? ''), $closed, true) === false
				)
			);
		}

		return $contributions;
	}//end hideForAccount()

	/**
	 * The pages whose `<app>:<id>` is not among the names.
	 *
	 * @param mixed $pages The contribution's pages.
	 * @param string $app The contributing app.
	 * @param array<int, string> $names The page names to drop.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function withoutPages(mixed $pages, string $app, array $names): array {
		if (is_array($pages) === false) {
			return [];
		}

		return array_values(
			array_filter(
				$pages,
				fn (mixed $page): bool => is_array($page) === true
					&& in_array($app.':'.(string)($page['id'] ?? ''), $names, true) === false
			)
		);
	}//end withoutPages()

	/**
	 * The pages in list order; unlisted pages after, in their own order.
	 *
	 * @param array<int, array<string, mixed>> $pages The pages.
	 * @param string $app The contributing app.
	 * @param array<string, int> $position The list position per page name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function ordered(array $pages, string $app, array $position): array {
		$keys = array_keys($pages);
		$rank = fn (int $key): int => ($position[$app.':'.(string)($pages[$key]['id'] ?? '')] ?? PHP_INT_MAX);
		usort($keys, fn (int $a, int $b): int => [$rank($a), $a] <=> [$rank($b), $b]);

		return array_map(fn (int $key): array => $pages[$key], $keys);
	}//end ordered()

	/**
	 * The lowest list position of any of these pages, or PHP_INT_MAX.
	 *
	 * @param array<int, array<string, mixed>> $pages The pages.
	 * @param string $app The contributing app.
	 * @param array<string, int> $position The list position per page name.
	 *
	 * @return int
	 */
	private function firstPosition(array $pages, string $app, array $position): int {
		$first = PHP_INT_MAX;
		foreach ($pages as $page) {
			$first = min($first, ($position[$app.':'.(string)($page['id'] ?? '')] ?? PHP_INT_MAX));
		}

		return $first;
	}//end firstPosition()

	/**
	 * The collection ids the blocks of these pages show.
	 *
	 * @param array<int, mixed> $pages The pages.
	 *
	 * @return array<int, string>
	 */
	private function shownBy(array $pages): array {
		$ids = [];
		foreach ($pages as $page) {
			foreach ($this->listOf(value: ($page['blocks'] ?? null)) as $block) {
				$id = ($block['collection'] ?? null);
				if (is_string($id) === true && $id !== '') {
					$ids[] = $id;
				}
			}
		}

		return array_values(array_unique($ids));
	}//end shownBy()

	/**
	 * The value when it is a list, else [].
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<int|string, mixed>
	 */
	private function listOf(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end listOf()
}//end class
