/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The menu block's data (`siteNavigation`): the same items the header menu
 * shows, in groups a parent can scan, and the rule for when a page carries
 * the block so the header leaves its own menu out.
 *
 * Pure functions, so the shell and a test derive the same groups from the
 * same answers without mounting anything.
 *
 * @spec openspec/changes/site-navigation-block/specs/portaliq-cms/spec.md#requirement-a-menu-block-must-show-the-portals-navigation-in-groups
 */

import { routeForNav } from '../../shared/portalNav.js'

/** The menu block's widget key. */
export const NAVIGATION_BLOCK = 'siteNavigation'

/**
 * The regions whose menu block replaces the header's menu.
 *
 * @type {Array<string>}
 */
const MENU_REGIONS = ['aside', 'main']

/**
 * The navigation in groups:
 *
 * 1. each signed-in app's pages under that app's name,
 * 2. the shell's own sections (cases, tasks, messages, news, inbox, account)
 *    under "My overview",
 * 3. each portal menu (the site's own pages) under its title.
 *
 * A group without items is left out, and a portal menu item's children
 * follow it in the same group, so every page the header offered is here.
 *
 * @param {object} input What the shell holds.
 * @param {Array<object>} input.menus The header menus (position 0).
 * @param {Array<object>} input.nav The signed-in navigation (portalNav.js).
 * @param {(key: string, vars?: object) => string} input.t The translator.
 * @param {number} input.unread The inbox's unread count.
 * @param {(route: string) => string} input.hrefFor A real address for a route.
 * @return {Array<{id: string, title: string, items: Array<object>}>} The groups.
 * @spec openspec/changes/site-navigation-block/specs/portaliq-cms/spec.md#requirement-a-menu-block-must-show-the-portals-navigation-in-groups
 */
export function navigationGroups({ menus, nav, t, unread, hrefFor }) {
	const groups = []
	const byApp = new Map()
	const own = { id: 'my-overview', title: t('My overview'), items: [] }

	for (const entry of nav || []) {
		const link = routeForNav(entry)
		const item = { name: entry.label, link, href: hrefFor(link) }
		if (entry.special === 'inbox' && Number(unread) > 0) {
			item.badge = String(unread)
			item.badgeLabel = t('{count} unread', { count: unread })
		}
		if (entry.special || !entry.contribution) {
			own.items.push(item)
			continue
		}
		const app = String(entry.contribution.app || '')
		if (!byApp.has(app)) {
			const group = {
				id: `app-${app}`,
				title: String(entry.contribution.label || app),
				items: [],
			}
			byApp.set(app, group)
			groups.push(group)
		}
		byApp.get(app).items.push(item)
	}
	groups.push(own)

	;(menus || []).forEach((menu, index) => {
		const items = []
		for (const item of menu.items || []) {
			items.push(menuItem(item))
			for (const child of item.items || []) {
				items.push(menuItem(child))
			}
		}
		groups.push({ id: `menu-${index}`, title: String(menu.title || ''), items })
	})

	return groups.filter((group) => group.items.length > 0)
}

/**
 * One portal menu item in the block's shape.
 *
 * @param {object} item A content API menu item.
 * @return {{name: string, link: string, href: string}} The item.
 */
function menuItem(item) {
	const link = String(item.link || '')
	return { name: String(item.name || ''), link, href: String(item.href || link) }
}

/**
 * Whether the page on screen carries a menu block, in its side region or its
 * main grid. Then the header leaves its own menu out, so the navigation is
 * on the page once.
 *
 * @param {object} regions The resolved regions (regions.js).
 * @return {boolean} True when a menu block is placed.
 * @spec openspec/changes/site-navigation-block/specs/portaliq-cms/spec.md#requirement-a-page-with-a-menu-block-must-leave-the-header-menu-out
 */
export function hasNavigationBlock(regions) {
	return MENU_REGIONS.some((region) =>
		((regions && regions[region]) || []).some(
			(block) => block && block.widgetKey === NAVIGATION_BLOCK,
		),
	)
}

/**
 * Whether the side region holds a menu block, so it renders as a column to
 * the left of the content rather than below it.
 *
 * @param {object} regions The resolved regions.
 * @return {boolean} True when the side region carries a menu block.
 * @spec openspec/changes/site-navigation-block/specs/portaliq-cms/spec.md#requirement-a-page-with-a-menu-block-must-leave-the-header-menu-out
 */
export function sideMenuOf(regions) {
	return ((regions && regions.aside) || []).some(
		(block) => block && block.widgetKey === NAVIGATION_BLOCK,
	)
}
