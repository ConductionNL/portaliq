/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * THE RESIDENT'S OWN MENU, APART FROM THE WEBSITE'S.
 *
 * The blue bar carries the website's pages (the CMS header menu). Everything
 * a signed-in resident does in their own area goes into a menu beside the
 * content, shown on every `/mijn` page and on no public page, so a public
 * page keeps its full width. This file decides what that menu holds and in
 * which groups. It imports only shared code, so
 * tests/site-resident-menu.spec.mjs runs it as node.
 *
 * @spec openspec/changes/site-resident-menu/specs/site-resident-menu/spec.md#requirement-the-residents-own-items-must-sit-in-a-menu-beside-the-content-req-srm-002
 */

import {
	ACCOUNT_ROUTE,
	isAccountRoute,
	routeForNav,
} from '../../shared/portalNav.js'

/** Shell sections about cases, in the first group. */
const CASE_SECTIONS = ['cases', 'tasks', 'access']

/** Shell sections about messages, in the group after the apps. */
const MESSAGE_SECTIONS = ['inbox', 'messages', 'news']

/** Shell sections about the resident, in the last group. */
const PROFILE_SECTIONS = ['details', 'account']

/**
 * Whether the resident menu shows: signed in, on a `/mijn` page, with
 * something to put in it. A public page never shows it.
 *
 * @param {object|null} session The session, or null.
 * @param {string} route The route on screen.
 * @param {Array<object>} nav The signed-in navigation.
 * @return {boolean} True when the menu shows.
 * @spec openspec/changes/site-resident-menu/specs/site-resident-menu/spec.md#requirement-the-residents-own-items-must-sit-in-a-menu-beside-the-content-req-srm-002
 */
export function showsResidentMenu(session, route, nav) {
	return (
		Boolean(session)
		&& isAccountRoute(route)
		&& Array.isArray(nav)
		&& nav.length > 0
	)
}

/**
 * The top right link to the resident's own area, or null when signed out.
 *
 * @param {object|null} session The session, or null.
 * @param {(key: string) => string} t The translator.
 * @param {(route: string) => string} hrefFor A real address for a route.
 * @return {{route: string, href: string, label: string}|null} The link.
 * @spec openspec/changes/site-resident-menu/specs/site-resident-menu/spec.md#requirement-the-header-must-hold-the-name-the-way-to-the-own-area-and-sign-out-req-srm-003
 */
export function ownAreaLink(session, t, hrefFor) {
	if (!session) {
		return null
	}
	return {
		route: ACCOUNT_ROUTE,
		href: hrefFor(ACCOUNT_ROUTE),
		label: t('My area'),
	}
}

/**
 * One menu item for a navigation entry.
 *
 * @param {object} entry The navigation entry.
 * @param {(key: string, vars?: object) => string} t The translator.
 * @param {number} unread The inbox's unread count.
 * @param {(route: string) => string} hrefFor A real address for a route.
 * @return {object} `{key, name, link, href, badge?, badgeLabel?}`.
 */
function itemFor(entry, t, unread, hrefFor) {
	const link = routeForNav(entry)
	const item = {
		key: entry.key,
		name: entry.label,
		link,
		href: hrefFor(link),
	}
	if (entry.special === 'inbox' && Number(unread) > 0) {
		item.badge = String(unread)
		item.badgeLabel = t('{count} unread', { count: unread })
	}
	return item
}

/**
 * The resident menu in groups: cases and tasks first, then one group per app
 * that contributes pages (named as the app names itself), then messages and
 * news, then the resident's details and account. An empty group is left out.
 *
 * TWO ITEMS NEVER READ THE SAME. The shell's own sections and an app's pages
 * come from two sources, and both may use one name: the shell's "Mijn zaken"
 * lists cases from every app, an app's "Mijn zaken" only its own. Where a
 * name occurs more than once, the app's item carries its group's name too,
 * so a screen reader user hears two different links.
 *
 * @param {Array<object>} nav The signed-in navigation (src/shared/portalNav.js).
 * @param {(key: string, vars?: object) => string} t The translator.
 * @param {number} unread The inbox's unread count.
 * @param {(route: string) => string} hrefFor A real address for a route.
 * @return {Array<{key: string, title: string, items: Array<object>}>} The groups.
 * @spec openspec/changes/site-resident-menu/specs/site-resident-menu/spec.md#requirement-the-residents-own-items-must-sit-in-a-menu-beside-the-content-req-srm-002
 */
export function residentMenuGroups(nav, t, unread, hrefFor) {
	const entries = Array.isArray(nav) ? nav : []
	const sectionGroup = (key, title, sections) => ({
		key,
		title,
		items: sections
			.map((special) => entries.find((entry) => entry.special === special))
			.filter(Boolean)
			.map((entry) => itemFor(entry, t, unread, hrefFor)),
	})

	const appGroups = []
	for (const entry of entries) {
		if (entry.special) {
			continue
		}
		const app = entry.contribution?.app || ''
		let group = appGroups.find((candidate) => candidate.app === app)
		if (!group) {
			group = {
				key: `app:${app}`,
				app,
				title: entry.contribution?.label || app,
				items: [],
			}
			appGroups.push(group)
		}
		group.items.push(itemFor(entry, t, unread, hrefFor))
	}

	const groups = [
		sectionGroup('cases', t('Cases and tasks'), CASE_SECTIONS),
		...appGroups.map(({ key, title, items }) => ({ key, title, items })),
		sectionGroup('messages', t('Messages and news'), MESSAGE_SECTIONS),
		sectionGroup('profile', t('Your details and account'), PROFILE_SECTIONS),
	].filter((group) => group.items.length > 0)

	const counts = new Map()
	for (const group of groups) {
		for (const item of group.items) {
			const name = item.name.toLowerCase()
			counts.set(name, (counts.get(name) || 0) + 1)
		}
	}
	for (const group of groups) {
		if (!group.key.startsWith('app:')) {
			continue
		}
		for (const item of group.items) {
			if (counts.get(item.name.toLowerCase()) > 1) {
				item.name = t('{label} ({source})', {
					label: item.name,
					source: group.title,
				})
			}
		}
	}
	return groups
}
