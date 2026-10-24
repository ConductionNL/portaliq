<?php

/**
 * Portaliq CMS Publish Validator (portal-cms-admin-ui)
 *
 * Checks the content of one portal before it goes out, for the two ways a
 * portal has been seen to render broken: a menu item that points at nothing,
 * and a portal with no front door. A menu item that points at a page that is
 * still a draft is a warning, not a block, because linking ahead of a release
 * is a normal editorial workflow.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-cms-admin-ui/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

/**
 * Finds what would render broken in one portal's pages and menus.
 *
 * @spec openspec/changes/portal-cms-admin-ui/tasks.md#task-2
 */
class CmsPublishValidator {
	public const NO_ROOT_PAGE = 'no-root-page';

	public const DUPLICATE_ROUTE = 'duplicate-route';

	public const MENU_LINK_NO_PAGE = 'menu-link-no-page';

	public const MENU_LINK_DRAFT_PAGE = 'menu-link-draft-page';

	/**
	 * Check one portal's pages and menus.
	 *
	 * @param array<int, array<string, mixed>> $pages The portal's pages, drafts included.
	 * @param array<int, array<string, mixed>> $menus The portal's menus.
	 *
	 * @return array{blocking: array<int, array<string, string>>, warnings: array<int, array<string, string>>}
	 *
	 * @spec openspec/changes/portal-cms-admin-ui/tasks.md#task-2
	 */
	public function check(array $pages, array $menus): array {
		$blocking = [];
		$warnings = [];

		$byRoute = $this->byRoute(pages: $pages);
		foreach ($byRoute as $route => $states) {
			if (count($states) > 1) {
				$blocking[] = ['code' => self::DUPLICATE_ROUTE, 'route' => $route];
			}
		}

		if (in_array(true, ($byRoute['/'] ?? []), true) === false) {
			$blocking[] = ['code' => self::NO_ROOT_PAGE, 'route' => '/'];
		}

		foreach ($this->links(menus: $menus) as $link) {
			$route = $this->normalise(route: $link);
			if ($route === '') {
				continue;
			}

			if (isset($byRoute[$route]) === false) {
				$blocking[] = ['code' => self::MENU_LINK_NO_PAGE, 'route' => $route];
				continue;
			}

			if (in_array(true, $byRoute[$route], true) === false) {
				$warnings[] = ['code' => self::MENU_LINK_DRAFT_PAGE, 'route' => $route];
			}
		}

		return ['blocking' => $this->unique(rows: $blocking), 'warnings' => $this->unique(rows: $warnings)];
	}//end check()

	/**
	 * Whether each page of a route is published, by normalised route.
	 *
	 * @param array<int, array<string, mixed>> $pages The pages.
	 *
	 * @return array<string, array<int, bool>>
	 */
	private function byRoute(array $pages): array {
		$byRoute = [];
		foreach ($pages as $page) {
			$route = $this->normalise(route: (string)($page['route'] ?? ''));
			if ($route === '') {
				continue;
			}

			$byRoute[$route][] = (($page['status'] ?? '') === 'published');
		}

		return $byRoute;
	}//end byRoute()

	/**
	 * The in-site links of every menu item and sub-item.
	 *
	 * @param array<int, array<string, mixed>> $menus The menus.
	 *
	 * @return array<int, string>
	 */
	private function links(array $menus): array {
		$out = [];
		foreach ($menus as $menu) {
			foreach ((array)($menu['items'] ?? []) as $item) {
				if (is_array($item) === false) {
					continue;
				}

				$out[] = (string)($item['link'] ?? '');
				foreach ((array)($item['items'] ?? []) as $sub) {
					if (is_array($sub) === true) {
						$out[] = (string)($sub['link'] ?? '');
					}
				}
			}
		}

		return $out;
	}//end links()

	/**
	 * A route as pages are keyed: a path with a leading slash, no query, no
	 * anchor, no trailing slash. An address that leaves the site gives ''.
	 *
	 * @param string $route A page route or a menu link.
	 *
	 * @return string
	 */
	private function normalise(string $route): string {
		$route = trim($route);
		if ($route === '' || str_starts_with($route, '/') === false || str_starts_with($route, '//') === true) {
			return '';
		}

		$route = (string)preg_replace('/[?#].*$/', '', $route);
		if ($route !== '/') {
			$route = rtrim($route, '/');
		}

		return $route;
	}//end normalise()

	/**
	 * @param array<int, array<string, string>> $rows Findings.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function unique(array $rows): array {
		$seen = [];
		$out  = [];
		foreach ($rows as $row) {
			$key = $row['code'] . '|' . $row['route'];
			if (isset($seen[$key]) === false) {
				$seen[$key] = true;
				$out[]      = $row;
			}
		}

		return $out;
	}//end unique()
}//end class
