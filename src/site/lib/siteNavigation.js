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

/** The menu block's widget key. */
export const NAVIGATION_BLOCK = 'siteNavigation'

/**
 * The regions whose menu block replaces the header's menu.
 *
 * @type {Array<string>}
 */
const MENU_REGIONS = ['aside', 'main']

/**
 * The navigation in groups: the resident's own items first, in the groups of
 * the menu beside `/mijn` (residentMenuGroups, site-resident-menu), then
 * each header menu (the site's own pages) under its title. A portal menu
 * item's children follow it in the same group, so every page the header
 * offers is here. A group without items is left out.
 *
 * @param {object} input What the shell holds.
 * @param {Array<object>} input.residentGroups `{key, title, items}` groups from residentMenuGroups, or [] signed out.
 * @param {Array<object>} input.menus The header menus (position 0).
 * @return {Array<{id: string, title: string, items: Array<object>}>} The groups.
 * @spec openspec/changes/site-navigation-block/specs/portaliq-cms/spec.md#requirement-a-menu-block-must-show-the-portals-navigation-in-groups
 */
export function navigationGroups({ residentGroups, menus }) {
	const groups = (residentGroups || []).map((group) => ({
		id: String(group.key || group.id || group.title),
		title: String(group.title || ''),
		items: group.items || [],
	}))

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
