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
 * One menu item for a navigation entry.
 *
 * @param {object} entry The navigation entry.
 * @param {(key: string, vars?: object) => string} t The translator.
 * @param {number} unread The inbox's unread count.
 * @param {(route: string) => string} hrefFor A real address for a route.
 * @param {Record<string, Array<object>>} [recordRows] Known rows, for a `badge` count.
 * @return {object} `{key, name, link, href, icon?, badge?, badgeLabel?}`.
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-the-resident-menu-must-show-icons-and-counts-in-groups-req-smo-006
 */
function itemFor(entry, t, unread, hrefFor, recordRows = {}) {
	const link = routeForNav(entry)
	const item = {
		key: entry.key,
		name: entry.label,
		link,
		href: hrefFor(link),
	}
	// The page's declared icon, drawn before its label (REQ-SMO-006).
	if (typeof entry.icon === 'string' && entry.icon !== '') {
		item.icon = entry.icon
	}
	if (entry.special === 'inbox' && Number(unread) > 0) {
		item.badge = String(unread)
		item.badgeLabel = t('{count} unread', { count: unread })
	}
	// A page that counts the rows of one of its collections (`badge`,
	// resident-menu-badges-and-cards): the count, once those rows are known.
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

/** The name a portal's layout calls each item by: a section, or `app:page`. */
const NAMES = new WeakMap()

/** The items that are one row of a page listed once per row. */
const ROW_ITEMS = new WeakSet()

/** The items of a contribution's home page, which `overview` stands for. */
const HOME_ITEMS = new WeakSet()

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
 * @param {(key: string, vars?: object) => string} t The translator.
 * @param {number} unread The inbox's unread count.
 * @param {(route: string) => string} hrefFor A real address for a route.
 * @param {Record<string, Array<object>>} [recordRows] The rows of each
 *   `perRecord` collection, by `<app>:<collection>`.
 * @param {Array<{title: string, items: Array<string>}>|null} [layout] The
 *   portal's own groups (`residentMenu.groups`), or none.
 * @param {Array<string>|null} [leaveOut] Items the portal leaves out of the
 *   menu by name (`residentMenu.leaveOut`, resident-menu-leave-out).
 * @return {Array<{key: string, title: string, items: Array<object>}>} The groups.
 * @spec openspec/changes/site-resident-menu/specs/site-resident-menu/spec.md#requirement-the-residents-own-items-must-sit-in-a-menu-beside-the-content-req-srm-002
 * @spec openspec/changes/resident-sees-words-not-codes/specs/site-resident-menu/spec.md#requirement-a-contributed-page-may-name-the-menu-group-it-belongs-to-req-srm-005
 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/site-resident-menu/spec.md#requirement-a-portal-may-lay-out-the-resident-menu-and-its-cases-page
 * @spec openspec/changes/resident-menu-leave-out/specs/site-resident-menu/spec.md#requirement-a-portal-may-leave-items-out-of-the-resident-menu
 */
export function residentMenuGroups(
	nav,
	t,
	unread,
	hrefFor,
	recordRows = {},
	layout = null,
	leaveOut = null,
) {
	const entries = Array.isArray(nav) ? nav : []
	const named = (item, name) => {
		NAMES.set(item, name)
		return item
	}
	const sectionGroup = (key, title, sections) => ({
		key,
		title,
		items: sections
			.map((special) => entries.find((entry) => entry.special === special))
			.filter(Boolean)
			.map((entry) =>
				named(itemFor(entry, t, unread, hrefFor, recordRows), entry.special),
			),
	})

	const appGroups = []
	const recordGroups = []
	for (const entry of entries) {
		if (entry.special || entry.page?.menu === false) {
			continue
		}
		const rows = perRecordRows(entry, recordRows)
		if (rows) {
			// With a declared group the rows are items of that group, each
			// with its subtitle ("Mijn kinderen": Vera, Sami); without one
			// each row heads a group of its own, as before.
			const grouped =
				typeof entry.page?.group === 'string'
				&& entry.page.group.trim() !== ''
			addPerRecordItems(
				grouped ? appGroups : recordGroups,
				entry,
				rows,
				t,
				unread,
				hrefFor,
				grouped,
			)
			continue
		}
		const { key, title } = pageGroupOf(entry)
		let group = appGroups.find((candidate) => candidate.key === key)
		if (!group) {
			group = { key, title, items: [] }
			appGroups.push(group)
		}
		const pageItem = named(
			{
				...itemFor(entry, t, unread, hrefFor, recordRows),
				source: appNameOf(entry),
			},
			`${entry.contribution?.app || ''}:${entry.page?.id || ''}`,
		)
		if (entry.page?.home === true) {
			HOME_ITEMS.add(pageItem)
		}
		group.items.push(pageItem)
	}

	const groups = [
		sectionGroup('cases', t('Cases and tasks'), CASE_SECTIONS),
		...appGroups,
		...recordGroups,
		sectionGroup('messages', t('Messages and news'), MESSAGE_SECTIONS),
		sectionGroup('profile', t('Your details and account'), PROFILE_SECTIONS),
	].filter((group) => group.items.length > 0)

	const counts = new Map()
	for (const group of groups) {
		for (const item of group.items) {
			if (item.perRecord) {
				continue
			}
			const name = item.name.toLowerCase()
			counts.set(name, (counts.get(name) || 0) + 1)
		}
	}
	for (const group of groups) {
		for (const item of group.items) {
			const source = item.source
			delete item.source
			delete item.perRecord
			if (source !== undefined && counts.get(item.name.toLowerCase()) > 1) {
				item.name = t('{label} ({source})', { label: item.name, source })
			}
		}
	}
	return laidOut(withoutLeftOut(groups, leaveOut), layout, t, hrefFor)
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
 * @param {Array<{title: string, items: Array<string>}>|null} layout The portal's groups.
 * @param {(key: string, vars?: object) => string} t The translator.
 * @param {(route: string) => string} hrefFor A real address for a route.
 * @return {Array<{key: string, title: string, items: Array<object>}>}
 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/site-resident-menu/spec.md#requirement-a-portal-may-lay-out-the-resident-menu-and-its-cases-page
 */
export function laidOut(groups, layout, t, hrefFor) {
	if (!Array.isArray(layout) || layout.length === 0) {
		return groups
	}
	const byName = new Map()
	for (const group of groups) {
		for (const item of group.items) {
			const name = NAMES.get(item)
			if (!name) {
				continue
			}
			if (!byName.has(name)) {
				byName.set(name, [])
			}
			// One item per name, except a page listed once per row.
			if (byName.get(name).length === 0 || ROW_ITEMS.has(item)) {
				byName.get(name).push(item)
			}
		}
	}
	const plain = (item) => {
		const copy = { ...item }
		delete copy.icon
		return copy
	}
	const placed = new Set()
	const out = []
	// A layout that names `overview` stands for the home pages too: they are
	// what `/mijn` shows, so they are not listed a second time
	// (resident-menu-follows-the-boards).
	if (
		layout.some((group) =>
			(Array.isArray(group?.items) ? group.items : []).some(
				(declared) => layoutItem(declared).name === 'overview',
			),
		)
	) {
		for (const group of groups) {
			for (const item of group.items) {
				if (HOME_ITEMS.has(item)) {
					placed.add(item)
				}
			}
		}
	}
	layout.forEach((group, index) => {
		const title = String(group?.title ?? '').trim()
		const items = []
		for (const declared of Array.isArray(group?.items) ? group.items : []) {
			// An item is a name, or `{item, label}` to give it the board's
			// word ("Berichten" for the conversations), resident-menu-follows-the-boards.
			const { name, label } = layoutItem(declared)
			if (name === 'overview') {
				items.push({
					key: '__overview__',
					name: label || t('Overview'),
					link: ACCOUNT_ROUTE,
					href: hrefFor(ACCOUNT_ROUTE),
				})
				continue
			}
			// A page listed once per row places all its rows here.
			for (const item of byName.get(name) || []) {
				if (placed.has(item)) {
					continue
				}
				placed.add(item)
				const copy = plain(item)
				if (label && !ROW_ITEMS.has(item)) {
					copy.name = label
				}
				items.push(copy)
			}
		}
		if (items.length > 0) {
			out.push({ key: `layout:${index}`, title, items })
		}
	})
	for (const group of groups) {
		const rest = group.items.filter((item) => !placed.has(item))
		if (rest.length === 0) {
			continue
		}
		// The site's own group under the same heading as a declared one
		// joins it, so one heading never stands twice.
		const same = out.find((candidate) => candidate.title === group.title)
		if (same) {
			same.items.push(...rest.map(plain))
		} else {
			out.push({ ...group, items: rest.map(plain) })
		}
	}
	return out
}

/**
 * One item of a declared group: its name, and the label the portal gives
 * it, if any (resident-menu-follows-the-boards).
 *
 * @param {string|{item: string, label?: string}} declared The declared item.
 * @return {{name: string, label: string}} The name and the label ('' for none).
 * @spec openspec/changes/resident-menu-follows-the-boards/specs/site-resident-menu/spec.md#requirement-a-declared-menu-item-may-carry-the-boards-word
 */
export function layoutItem(declared) {
	if (declared && typeof declared === 'object') {
		return {
			name: String(declared.item ?? ''),
			label: String(declared.label ?? '').trim(),
		}
	}
	return { name: String(declared ?? ''), label: '' }
}

/**
 * The person block at the top of the menu: the initials, the name from the
 * session and a second line from the first row of the collection the portal
 * names (`residentMenu.person`: `{collection: 'app:id', fields: [...]}`),
 * joined by " · " ("4 havo · klas H4b"). Null when the portal declares no
 * person block or the session carries no name.
 *
 * @param {object|null} session The session.
 * @param {object|null} declared The portal's `residentMenu.person`.
 * @param {Record<string, Array<object>>} [recordRows] The rows loaded for the menu.
 * @return {{initials: string, name: string, subline: string}|null} The person.
 * @spec openspec/changes/resident-menu-follows-the-boards/specs/site-resident-menu/spec.md#requirement-the-menu-may-open-with-the-person-and-their-class
 */
export function menuPerson(session, declared, recordRows = {}) {
	if (!session || !declared || typeof declared !== 'object') {
		return null
	}
	const name = String(session.displayName || session.name || '').trim()
	if (
		name === ''
		|| name === String(session.subjectRef || '')
		|| /^\d+$/.test(name)
	) {
		return null
	}
	const words = name.split(/\s+/)
	const initials = (
		words[0][0] + (words.length > 1 ? words[words.length - 1][0] : '')
	).toUpperCase()
	return { initials, name, subline: menuSubline(declared, recordRows) }
}

/**
 * The second line of the person block or the organisation card: the declared
 * fields of the first row of the declared collection, joined by " · ".
 *
 * @param {object|null} declared The portal's `residentMenu.person`.
 * @param {Record<string, Array<object>>} [recordRows] The rows loaded for the menu.
 * @return {string} The line, or ''.
 * @spec openspec/changes/resident-menu-follows-the-boards/specs/site-resident-menu/spec.md#requirement-the-menu-may-open-with-the-person-and-their-class
 */
export function menuSubline(declared, recordRows = {}) {
	const key = String(declared?.collection ?? '')
	const row = (recordRows?.[key] || [])[0]
	if (!row || !Array.isArray(declared?.fields)) {
		return ''
	}
	return declared.fields
		.map((field) => row[field])
		.filter(
			(value) =>
				(typeof value === 'string' && value.trim() !== '')
				|| typeof value === 'number',
		)
		.map((value) => String(value).trim())
		.join(' · ')
}

/**
 * The route an address of the own area stands for, when the portal gives the
 * item a second address (`residentMenu.routes`: `{"berichten": "messages"}`),
 * so `/mijn/berichten` opens the conversations. '' when the route is no such
 * address or the item it names is not in the navigation.
 *
 * @param {Array<object>} nav The navigation.
 * @param {string} route The route on screen.
 * @param {Record<string, string>|null} routes The portal's addresses.
 * @return {string} The route to open instead, or ''.
 * @spec openspec/changes/resident-menu-follows-the-boards/specs/site-resident-menu/spec.md#requirement-a-portal-may-give-an-item-of-the-own-area-a-second-address
 */
export function aliasedRoute(nav, route, routes) {
	if (!routes || typeof routes !== 'object') {
		return ''
	}
	const match = /^\/mijn\/([a-z0-9-]+)$/.exec(String(route || ''))
	const target = match ? routes[match[1]] : undefined
	if (typeof target !== 'string' || target === '') {
		return ''
	}
	const entry = (Array.isArray(nav) ? nav : []).find((candidate) =>
		candidate.special
			? candidate.special === target
			: `${candidate.contribution?.app || ''}:${candidate.page?.id || ''}`
				=== target,
	)
	return entry ? routeForNav(entry) : ''
}

/**
 * Read the rows of every collection a page lists itself per row of
 * (`perRecord`), scoped to the resident, by `<app>:<collection>`. A
 * collection that cannot be read is left out, and its pages are then listed
 * once, as any page.
 *
 * @param {Array<object>|null} contributions The aggregate's `contributions`.
 * @param {object} api The portal api (`fetchCollection`).
 * @param {Array<string>} [extra] More collections to read, as `app:id`.
 * @return {Promise<Record<string, Array<object>>>} The rows.
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-place-itself-in-the-menu-per-record-or-as-home-req-smo-020
 */
export async function loadPerRecordRows(contributions, api, extra = []) {
	const wanted = new Map()
	for (const contribution of Array.isArray(contributions) ? contributions : []) {
		// A collection the portal names for the menu itself, as `app:id`
		// (`residentMenu.person`, resident-menu-follows-the-boards).
		for (const key of Array.isArray(extra) ? extra : []) {
			const collection = (contribution?.collections || []).find(
				(candidate) =>
					candidate && `${contribution.app}:${candidate.id}` === key,
			)
			if (collection) {
				wanted.set(key, collection)
			}
		}
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
 * List a `perRecord` page once per row, each under the row's own group.
 *
 * @param {Array<object>} groups The record groups so far (changed in place).
 * @param {object} entry The page's navigation entry.
 * @param {Array<object>} rows The rows.
 * @param {(key: string, vars?: object) => string} t The translator.
 * @param {number} unread The inbox's unread count.
 * @param {(route: string) => string} hrefFor A real address for a route.
 * @param {boolean} [grouped] Whether the rows are items of the page's declared group.
 * @return {void}
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-place-itself-in-the-menu-per-record-or-as-home-req-smo-020
 * @spec openspec/changes/resident-menu-badges-and-cards/specs/site-resident-menu/spec.md#requirement-a-per-record-page-in-a-group-lists-each-row-with-its-subtitle
 */
function addPerRecordItems(
	groups,
	entry,
	rows,
	t,
	unread,
	hrefFor,
	grouped = false,
) {
	const app = entry.contribution?.app || ''
	const fields = titleFieldsOf(entry.page)
	const subtitleFields =
		entry.page?.records?.subtitleFields || entry.page?.record?.subtitleFields
	for (const row of rows) {
		const id = String(row?.id || row?.uuid || row?.['@self']?.id || '')
		const title = recordName(row, fields)
		if (id === '' || title === '') {
			continue
		}
		const { key, title: groupTitle } = grouped
			? pageGroupOf(entry)
			: { key: `record:${app}:${entry.page.perRecord}:${id}`, title }
		let group = groups.find((candidate) => candidate.key === key)
		if (!group) {
			group = { key, title: groupTitle, items: [] }
			groups.push(group)
		}
		const item = itemFor(entry, t, unread, hrefFor)
		if (grouped) {
			// The row is the item: its name, and its subtitle as a second line.
			item.name = title
			const subline = Array.isArray(subtitleFields)
				? subtitleFields
						.map((field) => row?.[field])
						.filter(
							(value) =>
								typeof value === 'string' && value.trim() !== '',
						)
						.join(' · ')
				: ''
			if (subline !== '') {
				item.subline = subline
			}
		}
		const link = `${item.link}/${encodeURIComponent(id)}`
		const rowItem = {
			...item,
			key: `${entry.key}:${id}`,
			link,
			href: hrefFor(link),
			// One page per row reads the same under each row's heading on
			// purpose; it never makes another item take its app's name.
			perRecord: true,
		}
		// A declared group names the page once and gets every row
		// (resident-menu-follows-the-boards).
		NAMES.set(rowItem, `${app}:${entry.page?.id || ''}`)
		ROW_ITEMS.add(rowItem)
		group.items.push(rowItem)
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

/**
 * A navigation entry with the label the portal's menu layout gives its item
 * (`{item, label}` in `residentMenu.groups`), so the page's heading and
 * breadcrumb read what the menu reads. The entry as it is without one.
 *
 * @param {object|null} entry The navigation entry.
 * @param {Array<{title: string, items: Array}>|null} layout The portal's groups.
 * @return {object|null} The entry.
 * @spec openspec/changes/mijn-messages-follow-the-boards/specs/site-mijn-omgeving/spec.md#requirement-the-messages-item-opens-the-conversations-under-the-boards-title
 */
export function withLayoutLabel(entry, layout) {
	if (!entry || !Array.isArray(layout)) {
		return entry
	}
	const name = entry.special
		? entry.special
		: `${entry.contribution?.app || ''}:${entry.page?.id || ''}`
	for (const group of layout) {
		for (const declared of Array.isArray(group?.items) ? group.items : []) {
			const { name: item, label } = layoutItem(declared)
			if (item === name && label !== '') {
				return { ...entry, label }
			}
		}
	}
	return entry
}
