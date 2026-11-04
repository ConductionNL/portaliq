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

/** The own-area pages for working with others, when the portal switches them on. */
const COLLABORATE_SECTIONS = ['samenwerken', 'acties', 'contacts']

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
 * A translator that calls the resident's own area what the portal calls it.
 *
 * A portal that names its account button ("Mijn Zuiddrecht") means that name
 * for the area behind it too: the breadcrumb, the menu and the link in the
 * header would otherwise read "Mijn omgeving" under a button that says
 * something else. Without a name the translator is handed back as it is.
 *
 * @param {(key: string, vars?: object) => string} t The translator.
 * @param {string} name The portal's `accountLabel`, possibly empty.
 * @return {(key: string, vars?: object) => string} The translator to use.
 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-the-own-area-must-carry-the-name-the-portal-gives-it
 */
export function withAreaName(t, name) {
	const own = String(name ?? '').trim()
	if (own === '') {
		return t
	}
	return (key, vars) => (key === 'My area' ? own : t(key, vars))
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
 * What the menu items are built from: the translator, the inbox's unread
 * count, the real address of a route, the known rows (for a `badge` count)
 * and the resident's open tasks (for the tasks section's count).
 *
 * @typedef {object} MenuContext
 * @property {Array<object>} [entries] The signed-in navigation.
 * @property {(key: string, vars?: object) => string} t The translator.
 * @property {number} unread The inbox's unread count.
 * @property {(route: string) => string} hrefFor A real address for a route.
 * @property {Record<string, Array<object>>} [recordRows] Known rows, by `<app>:<collection>`.
 * @property {number} [openTasks] The resident's open tasks.
 */

/**
 * One menu item for a navigation entry.
 *
 * @param {object} entry The navigation entry.
 * @param {MenuContext} ctx What the item is built from.
 * @return {object} `{key, name, link, href, icon?, badge?, badgeLabel?}`.
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-the-resident-menu-must-show-icons-and-counts-in-groups-req-smo-006
 */
function itemFor(entry, ctx) {
	const link = routeForNav(entry)
	const item = {
		key: entry.key,
		name: entry.label,
		link,
		href: ctx.hrefFor(link),
	}
	// The page's declared icon, drawn before its label (REQ-SMO-006).
	if (typeof entry.icon === 'string' && entry.icon !== '') {
		item.icon = entry.icon
	}
	return withBadge(item, { entry, ctx })
}

/**
 * An item with the count its entry shows, if any: the unread messages beside
 * the inbox, the open tasks beside "My tasks", or the rows a page's `badge`
 * counts (resident-menu-badges-and-cards) once those rows are known.
 *
 * @param {object} item The item (changed in place).
 * @param {{entry: object, ctx: MenuContext}} source The entry and what it is built from.
 * @return {object} The item.
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-the-resident-menu-must-show-icons-and-counts-in-groups-req-smo-006
 */
function withBadge(item, { entry, ctx }) {
	const { t, unread, recordRows = {}, openTasks = 0 } = ctx
	if (entry.special === 'inbox' && Number(unread) > 0) {
		item.badge = String(unread)
		item.badgeLabel = t('{count} unread', { count: unread })
	}
	if (entry.special === 'tasks' && Number(openTasks) > 0) {
		item.badge = String(openTasks)
		item.badgeLabel = t('{count} open', { count: openTasks })
	}
	const counted = badgeRows(entry, recordRows)
	if (counted && counted.length > 0) {
		item.badge = String(counted.length)
		item.badgeLabel = t(entry.page.badge.label || '{count} open', {
			count: counted.length,
		})
	}
	return item
}

/**
 * The rows a page's `badge` counts, or null when it declares none or they
 * are not known yet.
 *
 * @param {object} entry A navigation entry.
 * @param {Record<string, Array<object>>} recordRows The known rows.
 * @return {Array<object>|null} The rows.
 * @spec openspec/changes/resident-menu-badges-and-cards/specs/site-resident-menu/spec.md#requirement-a-menu-entry-may-show-the-count-of-a-collection
 */
export function badgeRows(entry, recordRows) {
	const collection = entry?.page?.badge?.collection
	if (typeof collection !== 'string' || collection === '') {
		return null
	}
	const rows = recordRows?.[`${entry.contribution?.app || ''}:${collection}`]
	return Array.isArray(rows) ? rows : null
}

/**
 * How many open tasks a "My tasks" read holds: none when the read failed, so
 * the menu never shows a count it could not ask for.
 *
 * @param {{results?: Array<object>, failed?: boolean}|null} page The answer of the portal api's `fetchTasks()`.
 * @return {number} The count.
 * @spec openspec/changes/resident-menu-badges-and-cards/specs/site-resident-menu/spec.md#requirement-a-menu-entry-may-show-the-count-of-a-collection
 */
export function openTaskCount(page) {
	if (!page || page.failed === true || !Array.isArray(page.results)) {
		return 0
	}
	return page.results.length
}

/** The name a portal's layout calls each item by: a section, or `app:page`. */
const NAMES = new WeakMap()

/**
 * The resident menu in groups: cases and tasks first, then the groups of the
 * contributed pages (a page's declared `group`, shared across apps, else one
 * group per app named as the app names itself), then messages and news, then
 * the resident's details and account. An empty group is left out.
 *
 * TWO ITEMS NEVER READ THE SAME. The shell's own sections and an app's pages
 * come from two sources, and both may use one name: the shell's "Mijn zaken"
 * lists cases from every app, an app's "Mijn zaken" only its own. Where a
 * name occurs more than once, the app's item carries its app's name too, so
 * a screen reader user hears two different links.
 *
 * A page that declares `menu: false` keeps its route and stays out of the
 * menu. A page that declares `perRecord` is listed once per row of that
 * collection, under a group named after the row, linking to the page with
 * that row chosen (`/mijn/<app>/<page>/<id>`); until those rows are known
 * it is listed once, as any page (site-mijn-omgeving-components REQ-SMO-020).
 *
 * @param {Array<object>} nav The signed-in navigation (src/shared/portalNav.js).
 * @param {object} menu What the menu is built from.
 * @param {(key: string, vars?: object) => string} menu.t The translator.
 * @param {number} menu.unread The inbox's unread count.
 * @param {(route: string) => string} menu.hrefFor A real address for a route.
 * @param {Record<string, Array<object>>} [menu.recordRows] The rows of each
 *   `perRecord` collection, by `<app>:<collection>`.
 * @param {Array<{title: string, items: Array<string>}>|null} [menu.layout] The
 *   portal's own groups (`residentMenu.groups`), or none.
 * @param {Array<string>|null} [menu.leaveOut] Items the portal leaves out of the
 *   menu by name (`residentMenu.leaveOut`, resident-menu-leave-out).
 * @param {number} [menu.openTasks] The resident's open tasks, counted beside "My tasks".
 * @return {Array<{key: string, title: string, items: Array<object>}>} The groups.
 * @spec openspec/changes/site-resident-menu/specs/site-resident-menu/spec.md#requirement-the-residents-own-items-must-sit-in-a-menu-beside-the-content-req-srm-002
 * @spec openspec/changes/resident-sees-words-not-codes/specs/site-resident-menu/spec.md#requirement-a-contributed-page-may-name-the-menu-group-it-belongs-to-req-srm-005
 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/site-resident-menu/spec.md#requirement-a-portal-may-lay-out-the-resident-menu-and-its-cases-page
 * @spec openspec/changes/resident-menu-leave-out/specs/site-resident-menu/spec.md#requirement-a-portal-may-leave-items-out-of-the-resident-menu
 */
export function residentMenuGroups(nav, menu) {
	const {
		t,
		unread,
		hrefFor,
		recordRows = {},
		layout = null,
		leaveOut = null,
		openTasks = 0,
	} = menu
	const ctx = {
		entries: Array.isArray(nav) ? nav : [],
		t,
		unread,
		hrefFor,
		recordRows,
		openTasks,
	}
	const { appGroups, recordGroups } = contributedGroups(ctx)
	const groups = [
		sectionGroup(ctx, ['cases', t('Cases and tasks'), CASE_SECTIONS]),
		themeGroup(ctx),
		sectionGroup(ctx, ['collaborate', t('Collaborate'), COLLABORATE_SECTIONS]),
		...appGroups,
		...recordGroups,
		sectionGroup(ctx, ['messages', t('Messages and news'), MESSAGE_SECTIONS]),
		sectionGroup(ctx, [
			'profile',
			t('Your details and account'),
			PROFILE_SECTIONS,
		]),
	].filter((group) => group.items.length > 0)
	withDistinctNames(groups, t)
	return laidOut(withoutLeftOut(groups, leaveOut), { layout, t, hrefFor })
}

/**
 * An item, remembered under the name a portal's layout calls it by.
 *
 * @param {object} item The item.
 * @param {string} name A section, or `app:page`.
 * @return {object} The item.
 */
function named(item, name) {
	NAMES.set(item, name)
	return item
}

/**
 * A group of the shell's own sections, in the order given.
 *
 * @param {MenuContext} ctx What the items are built from.
 * @param {[string, string, Array<string>]} group The group's key, heading and sections.
 * @return {{key: string, title: string, items: Array<object>}} The group.
 * @spec openspec/changes/site-resident-menu/specs/site-resident-menu/spec.md#requirement-the-residents-own-items-must-sit-in-a-menu-beside-the-content-req-srm-002
 */
function sectionGroup(ctx, [key, title, sections]) {
	return {
		key,
		title,
		items: sections
			.map((special) => ctx.entries.find((entry) => entry.special === special))
			.filter(Boolean)
			.map((entry) => named(itemFor(entry, ctx), entry.special)),
	}
}

/**
 * The life domains the portal groups the resident's items by (life-domain-theme-pages).
 *
 * @param {MenuContext} ctx What the items are built from.
 * @return {{key: string, title: string, items: Array<object>}} The group.
 * @spec openspec/changes/site-resident-menu/specs/site-resident-menu/spec.md#requirement-the-residents-own-items-must-sit-in-a-menu-beside-the-content-req-srm-002
 */
function themeGroup(ctx) {
	return {
		key: 'themes',
		title: ctx.t('Themes'),
		items: ctx.entries
			.filter((entry) => entry.theme)
			.map((entry) => named(itemFor(entry, ctx), entry.key)),
	}
}

/**
 * The groups of the contributed pages: a page in its declared or its app's
 * group, a `perRecord` page once per known row. A page with `menu: false`
 * is left out.
 *
 * @param {MenuContext} ctx What the items are built from.
 * @return {{appGroups: Array<object>, recordGroups: Array<object>}} The groups.
 * @spec openspec/changes/resident-sees-words-not-codes/specs/site-resident-menu/spec.md#requirement-a-contributed-page-may-name-the-menu-group-it-belongs-to-req-srm-005
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-place-itself-in-the-menu-per-record-or-as-home-req-smo-020
 */
function contributedGroups(ctx) {
	const appGroups = []
	const recordGroups = []
	for (const entry of ctx.entries) {
		if (entry.special || entry.page?.menu === false) {
			continue
		}
		const rows = perRecordRows(entry, ctx.recordRows)
		if (rows) {
			// With a declared group the rows are items of that group, each
			// with its subtitle ("Mijn kinderen": Vera, Sami); without one
			// each row heads a group of its own, as before.
			const grouped =
				typeof entry.page?.group === 'string'
				&& entry.page.group.trim() !== ''
			addPerRecordItems(
				{ groups: grouped ? appGroups : recordGroups, entry, rows, grouped },
				ctx,
			)
			continue
		}
		groupIn(appGroups, pageGroupOf(entry)).items.push(
			named(
				{ ...itemFor(entry, ctx), source: appNameOf(entry) },
				`${entry.contribution?.app || ''}:${entry.page?.id || ''}`,
			),
		)
	}
	return { appGroups, recordGroups }
}

/**
 * The group with a key, added at the end when there is none yet.
 *
 * @param {Array<object>} groups The groups (changed in place).
 * @param {{key: string, title: string}} heading The group's key and heading.
 * @return {{key: string, title: string, items: Array<object>}} The group.
 */
function groupIn(groups, { key, title }) {
	let group = groups.find((candidate) => candidate.key === key)
	if (!group) {
		group = { key, title, items: [] }
		groups.push(group)
	}
	return group
}

/**
 * Where a name is used by more than one item, every app's item carries its
 * app's name too. Rows of a `perRecord` page never count. The bookkeeping
 * (`source`, `perRecord`) is taken off every item.
 *
 * @param {Array<{items: Array<object>}>} groups The groups (changed in place).
 * @param {(key: string, vars?: object) => string} t The translator.
 * @return {void}
 * @spec openspec/changes/site-resident-menu/specs/site-resident-menu/spec.md#requirement-the-residents-own-items-must-sit-in-a-menu-beside-the-content-req-srm-002
 */
function withDistinctNames(groups, t) {
	const counts = new Map()
	for (const item of groups.flatMap((group) => group.items)) {
		if (!item.perRecord) {
			const name = item.name.toLowerCase()
			counts.set(name, (counts.get(name) || 0) + 1)
		}
	}
	for (const item of groups.flatMap((group) => group.items)) {
		const source = item.source
		delete item.source
		delete item.perRecord
		if (source !== undefined && counts.get(item.name.toLowerCase()) > 1) {
			item.name = t('{label} ({source})', { label: item.name, source })
		}
	}
}

/**
 * The menu item a route belongs to, so the menu marks where the resident is
 * on a page one level deeper too: the route itself when an item links to it,
 * else the deepest item the route lies under, else, for a cases collection's
 * page or a record of it (a case opened from "Mijn zaken"), the cases section. A route
 * that belongs to no item is handed back, and nothing is marked.
 *
 * @param {string} route The route on screen.
 * @param {object} menu The menu.
 * @param {Array<{items: Array<{link: string}>}>} menu.groups The menu's groups.
 * @param {Array<object>} [menu.nav] The signed-in navigation.
 * @param {object|null} [menu.contributions] The contributions aggregate (`{contributions: [{app, collections}]}`).
 * @return {string} The route of the item to mark, or the route itself.
 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/site-resident-menu/spec.md#requirement-a-portal-may-lay-out-the-resident-menu-and-its-cases-page
 */
export function menuCurrentRoute(route, { groups, nav = [], contributions = null }) {
	const links = (Array.isArray(groups) ? groups : [])
		.flatMap((group) => (Array.isArray(group?.items) ? group.items : []))
		.map((item) => item?.link)
		.filter((link) => typeof link === 'string' && link !== '')
	if (typeof route !== 'string' || route === '' || links.includes(route)) {
		return route || ''
	}
	const under = links
		.filter((link) => link !== ACCOUNT_ROUTE && route.startsWith(`${link}/`))
		.sort((a, b) => b.length - a.length)[0]
	return under || casesRouteFor(route, { links, nav, contributions })
}

/**
 * The cases section's link for a route on a cases collection's page, when
 * the menu holds that link; else the route itself.
 *
 * @param {string} route The route on screen.
 * @param {{links: Array<string>, nav: Array<object>, contributions: object|null}} menu The menu's links, the navigation and the contributions aggregate.
 * @return {string} The route to mark.
 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/site-resident-menu/spec.md#requirement-a-portal-may-lay-out-the-resident-menu-and-its-cases-page
 */
function casesRouteFor(route, { links, nav, contributions }) {
	const entries = Array.isArray(nav) ? nav : []
	const entry = entries.find((candidate) => {
		if (candidate.special) {
			return false
		}
		const own = routeForNav(candidate)
		return route === own || route.startsWith(`${own}/`)
	})
	const cases = entries.find((candidate) => candidate.special === 'cases')
	if (entry && cases && isCasesPage(entry, contributions)) {
		const link = routeForNav(cases)
		if (links.includes(link)) {
			return link
		}
	}
	return route
}

/**
 * Whether a navigation entry's page shows a `cases` collection.
 *
 * @param {object} entry A navigation entry.
 * @param {object|null} contributions The contributions aggregate.
 * @return {boolean}
 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/site-resident-menu/spec.md#requirement-a-portal-may-lay-out-the-resident-menu-and-its-cases-page
 */
function isCasesPage(entry, contributions) {
	const app = entry.contribution?.app || ''
	const page = entry.page || {}
	const names = [
		page.record?.collection,
		...(Array.isArray(page.blocks) ? page.blocks : []).map(
			(block) => block?.collection,
		),
	].filter((name) => typeof name === 'string' && name !== '')
	const list = Array.isArray(contributions?.contributions)
		? contributions.contributions
		: []
	return list.some(
		(contribution) =>
			contribution?.app === app
			&& (Array.isArray(contribution.collections)
				? contribution.collections
				: []
			).some(
				(collection) =>
					collection?.kind === 'cases' && names.includes(collection.id),
			),
	)
}

/**
 * The groups without the items the portal leaves out by name, and without a
 * group that is empty then (resident-menu-leave-out).
 *
 * @param {Array<{key: string, title: string, items: Array<object>}>} groups The groups.
 * @param {Array<string>|null} leaveOut The names to leave out.
 * @return {Array<{key: string, title: string, items: Array<object>}>}
 * @spec openspec/changes/resident-menu-leave-out/specs/site-resident-menu/spec.md#requirement-a-portal-may-leave-items-out-of-the-resident-menu
 */
export function withoutLeftOut(groups, leaveOut) {
	const names = new Set(Array.isArray(leaveOut) ? leaveOut : [])
	if (names.size === 0) {
		return groups
	}
	return groups
		.map((group) => ({
			...group,
			items: group.items.filter((item) => !names.has(NAMES.get(item))),
		}))
		.filter((group) => group.items.length > 0)
}

/**
 * The groups in the portal's own layout, when it declares one: each declared
 * group in order with the items it names (`overview` opens `/mijn` itself),
 * without icons; then every item the layout does not name, in the groups the
 * site built, so nothing becomes unreachable. Without a layout the groups
 * are handed back as they are.
 *
 * @param {Array<{key: string, title: string, items: Array<object>}>} groups The site's own groups.
 * @param {object} portal The portal's layout.
 * @param {Array<{title: string, items: Array<string>}>|null} portal.layout The portal's groups.
 * @param {(key: string, vars?: object) => string} portal.t The translator.
 * @param {(route: string) => string} portal.hrefFor A real address for a route.
 * @return {Array<{key: string, title: string, items: Array<object>}>}
 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/site-resident-menu/spec.md#requirement-a-portal-may-lay-out-the-resident-menu-and-its-cases-page
 */
export function laidOut(groups, { layout, t, hrefFor }) {
	if (!Array.isArray(layout) || layout.length === 0) {
		return groups
	}
	const byName = new Map()
	for (const item of groups.flatMap((group) => group.items)) {
		const name = NAMES.get(item)
		if (name && !byName.has(name)) {
			byName.set(name, item)
		}
	}
	const placed = new Set()
	const out = []
	layout.forEach((group, index) => {
		const items = declaredItems(group, { byName, placed, t, hrefFor })
		if (items.length > 0) {
			out.push({
				key: `layout:${index}`,
				title: String(group?.title ?? '').trim(),
				items,
			})
		}
	})
	return withRestOf(out, { groups, placed })
}

/**
 * An item without its icon: the portal's own groups draw none.
 *
 * @param {object} item The item.
 * @return {object} A copy without `icon`.
 */
function plainItem(item) {
	const copy = { ...item }
	delete copy.icon
	return copy
}

/**
 * The items a declared group names, each placed once.
 *
 * @param {{items?: Array<string>}} group The declared group.
 * @param {{byName: Map<string, object>, placed: Set<object>, t: (key: string, vars?: object) => string, hrefFor: (route: string) => string}} found The items by name, the items placed so far (changed in place), the translator and the address of a route.
 * @return {Array<object>} The items.
 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/site-resident-menu/spec.md#requirement-a-portal-may-lay-out-the-resident-menu-and-its-cases-page
 */
function declaredItems(group, { byName, placed, t, hrefFor }) {
	const items = []
	for (const name of Array.isArray(group?.items) ? group.items : []) {
		if (name === 'overview') {
			items.push({
				key: '__overview__',
				name: t('Overview'),
				link: ACCOUNT_ROUTE,
				href: hrefFor(ACCOUNT_ROUTE),
			})
			continue
		}
		const item = byName.get(String(name))
		if (item && !placed.has(item)) {
			placed.add(item)
			items.push(plainItem(item))
		}
	}
	return items
}

/**
 * The declared groups followed by every item the layout does not name, in
 * the site's own groups. The site's own group under the same heading as a
 * declared one joins it, so one heading never stands twice.
 *
 * @param {Array<object>} out The declared groups (changed in place).
 * @param {{groups: Array<object>, placed: Set<object>}} site The site's own groups and the items already placed.
 * @return {Array<object>} The groups.
 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/site-resident-menu/spec.md#requirement-a-portal-may-lay-out-the-resident-menu-and-its-cases-page
 */
function withRestOf(out, { groups, placed }) {
	for (const group of groups) {
		const rest = group.items.filter((item) => !placed.has(item))
		if (rest.length === 0) {
			continue
		}
		const same = out.find((candidate) => candidate.title === group.title)
		if (same) {
			same.items.push(...rest.map(plainItem))
		} else {
			out.push({ ...group, items: rest.map(plainItem) })
		}
	}
	return out
}

/**
 * Read the rows of every collection a page lists itself per row of
 * (`perRecord`), scoped to the resident, by `<app>:<collection>`. A
 * collection that cannot be read is left out, and its pages are then listed
 * once, as any page.
 *
 * @param {Array<object>|null} contributions The aggregate's `contributions`.
 * @param {object} api The portal api (`fetchCollection`).
 * @return {Promise<Record<string, Array<object>>>} The rows.
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-place-itself-in-the-menu-per-record-or-as-home-req-smo-020
 */
export async function loadPerRecordRows(contributions, api) {
	const wanted = new Map()
	for (const contribution of Array.isArray(contributions) ? contributions : []) {
		for (const page of contribution?.pages || []) {
			// The rows of a `perRecord` collection, and of a `badge` one to count.
			for (const id of [page?.perRecord, page?.badge?.collection]) {
				const collection = (contribution.collections || []).find(
					(candidate) => candidate && id && candidate.id === id,
				)
				if (collection) {
					wanted.set(`${contribution.app}:${collection.id}`, collection)
				}
			}
		}
	}
	const rows = {}
	if (typeof api?.fetchCollection !== 'function') {
		return rows
	}
	await Promise.all(
		[...wanted].map(async ([key, collection]) => {
			try {
				const answer = await api.fetchCollection(collection, {
					orNull: true,
				})
				if (Array.isArray(answer)) {
					rows[key] = answer
				}
			} catch {
				// Listed once, as any page.
			}
		}),
	)
	return rows
}

/**
 * The rows a `perRecord` page is listed for, or null when it declares none
 * or they are not known yet.
 *
 * @param {object} entry A navigation entry of a contributed page.
 * @param {Record<string, Array<object>>} recordRows The known rows.
 * @return {Array<object>|null} The rows.
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-place-itself-in-the-menu-per-record-or-as-home-req-smo-020
 */
export function perRecordRows(entry, recordRows) {
	const collection = entry.page?.perRecord
	if (typeof collection !== 'string' || collection === '') {
		return null
	}
	const rows = recordRows?.[`${entry.contribution?.app || ''}:${collection}`]
	return Array.isArray(rows) ? rows : null
}

/**
 * The title fields of a page's record collection, as its `record` or
 * `records` declares them.
 *
 * @param {object} page The page.
 * @return {Array<string>|undefined} The fields.
 */
function titleFieldsOf(page) {
	return page?.record?.titleFields || page?.records?.titleFields
}

/**
 * List a `perRecord` page once per row, each under the row's own group, or,
 * when `grouped`, as items of the page's declared group with each row's name
 * and subtitle.
 *
 * @param {object} target Where the rows go.
 * @param {Array<object>} target.groups The groups so far (changed in place).
 * @param {object} target.entry The page's navigation entry.
 * @param {Array<object>} target.rows The rows.
 * @param {boolean} [target.grouped] Whether the rows are items of the page's declared group.
 * @param {MenuContext} ctx What the items are built from.
 * @return {void}
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-place-itself-in-the-menu-per-record-or-as-home-req-smo-020
 * @spec openspec/changes/resident-menu-badges-and-cards/specs/site-resident-menu/spec.md#requirement-a-per-record-page-in-a-group-lists-each-row-with-its-subtitle
 */
function addPerRecordItems({ groups, entry, rows, grouped = false }, ctx) {
	const app = entry.contribution?.app || ''
	const fields = titleFieldsOf(entry.page)
	for (const row of rows) {
		const id = String(row?.id || row?.uuid || row?.['@self']?.id || '')
		const title = recordName(row, fields)
		if (id === '' || title === '') {
			continue
		}
		const heading = grouped
			? pageGroupOf(entry)
			: { key: `record:${app}:${entry.page.perRecord}:${id}`, title }
		// A row's item never shows a page's `badge` count.
		const item = itemFor(entry, { ...ctx, recordRows: {}, openTasks: 0 })
		if (grouped) {
			nameAfterRow(item, { row, title, entry })
		}
		const link = `${item.link}/${encodeURIComponent(id)}`
		groupIn(groups, heading).items.push({
			...item,
			key: `${entry.key}:${id}`,
			link,
			href: ctx.hrefFor(link),
			// One page per row reads the same under each row's heading on
			// purpose; it never makes another item take its app's name.
			perRecord: true,
		})
	}
}

/**
 * Make an item the row's own: its name, and its subtitle as a second line.
 *
 * @param {object} item The item (changed in place).
 * @param {{row: object, title: string, entry: object}} source The row, its name and the page's navigation entry.
 * @return {void}
 * @spec openspec/changes/resident-menu-badges-and-cards/specs/site-resident-menu/spec.md#requirement-a-per-record-page-in-a-group-lists-each-row-with-its-subtitle
 */
function nameAfterRow(item, { row, title, entry }) {
	item.name = title
	const subtitleFields =
		entry.page?.records?.subtitleFields || entry.page?.record?.subtitleFields
	const subline = Array.isArray(subtitleFields)
		? subtitleFields
				.map((field) => row?.[field])
				.filter((value) => typeof value === 'string' && value.trim() !== '')
				.join(' · ')
		: ''
	if (subline !== '') {
		item.subline = subline
	}
}

/**
 * A row's name: its title fields, else name, title or given name.
 *
 * @param {object} row The row.
 * @param {Array<string>} [fields] The title fields.
 * @return {string} The name.
 */
function recordName(row, fields) {
	const names =
		Array.isArray(fields) && fields.length > 0
			? fields
			: ['name', 'title', 'givenName']
	return names
		.map((field) => row?.[field])
		.filter((value) => typeof value === 'string' && value.trim() !== '')
		.join(' ')
}

/**
 * The name an app goes by in the menu: its display name, else its id.
 *
 * @param {object} entry A navigation entry of a contributed page.
 * @return {string} The name.
 */
function appNameOf(entry) {
	return entry.contribution?.label || entry.contribution?.app || ''
}

/**
 * The group a contributed page sits in. A page that declares `group` shares
 * one heading with every page of that group, from any app; a page without
 * one sits under its app's name, as before.
 *
 * @param {object} entry A navigation entry of a contributed page.
 * @return {{key: string, title: string}} The group's key and heading.
 * @spec openspec/changes/resident-sees-words-not-codes/specs/site-resident-menu/spec.md#requirement-a-contributed-page-may-name-the-menu-group-it-belongs-to-req-srm-005
 */
export function pageGroupOf(entry) {
	const declared = entry.page?.group
	if (typeof declared === 'string' && declared.trim() !== '') {
		const title = declared.trim()
		return { key: `group:${title.toLowerCase()}`, title }
	}
	return {
		key: `app:${entry.contribution?.app || ''}`,
		title: appNameOf(entry),
	}
}
