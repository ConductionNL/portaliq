// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The signed-in navigation, built from what the auth edge answers: every
// contribution's `pages` (contribution-manifest-v3) plus the shell's own
// sections (my cases, tasks, messages, news, inbox, access, details, account).
// Shared by the React portal and the Vue site renderer, so both offer the same
// sections in the same order, and imports nothing, so node tests cover it.

/** The fixed nav keys of the shell's own sections. */
export const NAV_KEYS = {
	inbox: '__inbox__',
	tasks: '__tasks__',
	messages: '__messages__',
	news: '__news__',
	access: '__access__',
	cases: '__cases__',
	details: '__details__',
	contacts: '__contacts__',
	account: '__account__',
}

/**
 * Sections that are never the page a signed-in resident lands on: they are
 * there to visit, not to open with.
 */
const NEVER_DEFAULT = [
	'inbox',
	'access',
	'details',
	'contacts',
	'samenwerken',
	'acties',
	'account',
]

/**
 * The in-site route every signed-in section lives under. A CMS page with this
 * route is shadowed by the signed-in area, so no seeded portal uses it.
 */
export const ACCOUNT_ROUTE = '/mijn'

/** The segment under a theme's route that lists all its products. */
export const THEME_PRODUCTS_SEGMENT = 'producten'

/**
 * Flatten every contribution's pages into one navigable list, each tagged with
 * its owning contribution so a block's refs resolve in the right scope, and
 * add the shell's own sections. My cases opens the list; the inbox, access,
 * details and account come last so they are never the default.
 *
 * @param {Array|null} contributions The aggregate's `contributions`.
 * @param {(key: string) => string} t The translator.
 * @param {object} [enabled] Which shell sections the backend announced.
 * @param {boolean} [enabled.tasks] `tasks.enabled` on the aggregate.
 * @param {boolean} [enabled.messages] The subject takes part in a thread.
 * @param {boolean} [enabled.news] The guardian's feed holds news.
 * @param {boolean} [enabled.access] Signed in with the contributions loaded.
 * @param {boolean} [enabled.cases] `cases.enabled` on the aggregate.
 * @param {Array<object>} [enabled.pages] The own-area pages the portal switched on: `{special, label, icon}`.
 * @param {Array<object>} [enabled.themes] The life domains with something for this resident.
 * @param {Array<object>} [enabled.stepUp] The aggregate's `stepUp`: collections the login is too low for.
 * @return {Array<object>} `{key, label, icon, page?, contribution?, special?, stepUp?}` entries.
 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t6
 */
export function buildNav(contributions, t, enabled = {}) {
	const nav = []
	for (const contribution of contributions || []) {
		for (const page of contribution.pages || []) {
			nav.push({
				key: `${contribution.app}:${page.id}`,
				label: page.label || page.id,
				icon: page.icon,
				page,
				contribution,
			})
		}
	}
	if (enabled.cases === true) {
		nav.unshift({
			key: NAV_KEYS.cases,
			label: t('My cases'),
			icon: 'FolderAccount',
			special: 'cases',
		})
	}
	if (enabled.tasks === true) {
		nav.push({
			key: NAV_KEYS.tasks,
			label: t('My tasks'),
			icon: 'CheckboxMarkedOutline',
			special: 'tasks',
		})
	}
	if (enabled.messages === true) {
		nav.push({
			key: NAV_KEYS.messages,
			label: t('Conversations'),
			icon: 'MessageText',
			special: 'messages',
		})
	}
	if (enabled.news === true) {
		nav.push({
			key: NAV_KEYS.news,
			label: t('News'),
			icon: 'Newspaper',
			special: 'news',
		})
	}
	// The inbox only once something else is there: on the pre-load render it
	// would be the sole entry and lock the default page to an empty inbox.
	if (nav.length > 0) {
		nav.push({
			key: NAV_KEYS.inbox,
			label: t('Inbox'),
			icon: 'Email',
			special: 'inbox',
		})
	}
	for (const page of enabled.pages || []) {
		nav.push({
			key: `__${page.special}__`,
			label: t(page.label),
			icon: page.icon,
			special: page.special,
		})
	}
	for (const theme of enabled.themes || []) {
		nav.push({
			key: `__theme__:${theme.slug}`,
			label: theme.title,
			icon: 'Tag',
			special: `thema/${theme.slug}`,
			theme,
		})
	}
	if (enabled.access === true) {
		nav.push({
			key: NAV_KEYS.access,
			label: t('Access to cases'),
			icon: 'AccountKey',
			special: 'access',
		})
		nav.push({
			key: NAV_KEYS.details,
			label: t('My details'),
			icon: 'CardAccountDetails',
			special: 'details',
		})
		nav.push({
			key: NAV_KEYS.account,
			label: t('My account'),
			icon: 'AccountCog',
			special: 'account',
		})
	}
	// A page the login is too low for says which login reaches it, last, and
	// never as the page the resident opens on (REQ-SCR-004).
	nav.push(...stepUpEntries(enabled.stepUp, nav))
	return nav
}

/**
 * The navigation entries for the aggregate's `stepUp`, one per collection, at
 * the route the dropped page would have had (`/mijn/<app>/<collection>`),
 * skipping any route a real page already holds. The server drops a page whose
 * blocks all read such a collection, so without this entry the resident would
 * never learn the page exists (site-member-voting-record-and-confidential-papers
 * design D5).
 *
 * @param {Array<object>|null} stepUp The aggregate's `stepUp`: `{app, collection, label, minTrust}`.
 * @param {Array<object>} nav The navigation built so far.
 * @return {Array<object>} `{key, label, icon, stepUp}` entries.
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t6
 */
export function stepUpEntries(stepUp, nav = []) {
	const taken = new Set((nav || []).map((entry) => entry.key))
	const entries = []
	for (const item of Array.isArray(stepUp) ? stepUp : []) {
		const app = typeof item?.app === 'string' ? item.app : ''
		const collection =
			typeof item?.collection === 'string' ? item.collection : ''
		const key = `${app}:${collection}`
		if (app === '' || collection === '' || taken.has(key)) {
			continue
		}
		taken.add(key)
		entries.push({
			key,
			label:
				typeof item.label === 'string' && item.label !== ''
					? item.label
					: collection,
			icon: 'Lock',
			stepUp: {
				app,
				collection,
				minTrust: typeof item.minTrust === 'string' ? item.minTrust : '',
			},
		})
	}
	return entries
}

/**
 * Which of the shell's own sections the answers announce: tasks and my cases
 * when the aggregate says so, messages when the subject takes part in a
 * thread, news when the feed holds an item, and access, details and account
 * once a signed-in resident's contributions have loaded.
 *
 * @param {object} state What the shell loaded.
 * @param {object|null} state.session The session, or null.
 * @param {object|null} state.contributions The contributions aggregate, or null.
 * @param {Array|null} state.threads The message threads.
 * @param {Array|null} state.news The news feed.
 * @return {{tasks: boolean, messages: boolean, news: boolean, access: boolean, cases: boolean, pages: Array<object>, themes: Array<object>}}
 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
 */
export function shellSections({ session, contributions, threads, news }) {
	return {
		tasks: contributions?.tasks?.enabled === true,
		messages: Array.isArray(threads) && threads.length > 0,
		news: Array.isArray(news) && news.length > 0,
		access: Boolean(session && contributions),
		cases: contributions?.cases?.enabled === true,
		pages:
			session && Array.isArray(contributions?.areaPages)
				? contributions.areaPages
				: [],
		themes: Array.isArray(contributions?.themes) ? contributions.themes : [],
		stepUp:
			session && Array.isArray(contributions?.stepUp)
				? contributions.stepUp
				: [],
	}
}

/**
 * The entry a signed-in resident opens on: the first content page, never the
 * inbox, access, details or account section; the first entry when nothing
 * else is there.
 *
 * @param {Array<object>} nav The navigation.
 * @return {string|null} The key, or null for an empty navigation.
 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
 */
export function defaultNavKey(nav) {
	if (!Array.isArray(nav) || nav.length === 0) {
		return null
	}
	const first =
		nav.find((entry) => !NEVER_DEFAULT.includes(entry.special) && !entry.stepUp)
		|| nav[0]
	return first.key
}

/**
 * The in-site route of one entry: `/mijn/<section>` for a shell section,
 * `/mijn/<app>/<page id>` for a contribution's page.
 *
 * @param {object} entry A navigation entry.
 * @return {string} The route.
 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
 */
export function routeForNav(entry) {
	if (entry.special) {
		return `${ACCOUNT_ROUTE}/${entry.special}`
	}
	const app = encodeURIComponent(
		entry.contribution?.app || entry.stepUp?.app || '',
	)
	const page = encodeURIComponent(entry.page?.id || entry.stepUp?.collection || '')
	return `${ACCOUNT_ROUTE}/${app}/${page}`
}

/**
 * The route of a theme's full product list, expired ones last:
 * `/mijn/thema/<slug>/producten`. '' for an entry that is no theme.
 *
 * @param {object|null} entry A navigation entry.
 * @return {string} The route, or ''.
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t05b
 */
export function themeProductsRoute(entry) {
	return entry?.theme ? `${routeForNav(entry)}/${THEME_PRODUCTS_SEGMENT}` : ''
}

/**
 * Whether a route is the full product list of this theme entry.
 *
 * @param {object|null} entry A navigation entry.
 * @param {string} route The route on screen.
 * @return {boolean}
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t05b
 */
export function isThemeProductsRoute(entry, route) {
	const products = themeProductsRoute(entry)
	return products !== '' && String(route || '') === products
}

/**
 * Whether an in-site route belongs to the signed-in area.
 *
 * @param {string} route The route.
 * @return {boolean}
 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
 */
export function isAccountRoute(route) {
	const value = String(route || '')
	return value === ACCOUNT_ROUTE || value.startsWith(`${ACCOUNT_ROUTE}/`)
}

/**
 * The entry an in-site route names, or null when it names none (the bare
 * `/mijn`, or a page the resident's contributions do not offer).
 *
 * @param {Array<object>} nav The navigation.
 * @param {string} route The route.
 * @return {object|null} The entry.
 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
 */
export function navEntryForRoute(nav, route) {
	if (!isAccountRoute(route)) {
		return null
	}
	const exact = (nav || []).find((entry) => routeForNav(entry) === route)
	if (exact) {
		return exact
	}
	// The full product list of a theme is the theme's own page, one segment
	// deeper: `/mijn/thema/<slug>/producten` (life-domain-theme-pages T05b).
	const products = (nav || []).find((entry) => isThemeProductsRoute(entry, route))
	if (products) {
		return products
	}
	// A record page with one record chosen: `/mijn/<app>/<page>/<id>`
	// (site-mijn-omgeving-components REQ-SMO-008, REQ-SMO-020).
	const id = recordIdOfRoute(route)
	if (id === '') {
		return null
	}
	const pageRoute = String(route).slice(0, String(route).lastIndexOf('/'))
	return (
		(nav || []).find(
			(entry) =>
				!entry.special
				&& (entry.page?.record || entry.page?.records)
				&& routeForNav(entry) === pageRoute,
		) || null
	)
}

/**
 * The record a route chooses on a record page: the fourth segment of
 * `/mijn/<app>/<page>/<id>`, or ''.
 *
 * @param {string} route The route.
 * @return {string} The record id, decoded.
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-the-resident-must-see-and-switch-for-whom-they-act-req-smo-008
 */
export function recordIdOfRoute(route) {
	const parts = String(route || '').split('/')
	if (parts.length !== 5 || `/${parts[1]}` !== ACCOUNT_ROUTE || parts[4] === '') {
		return ''
	}
	try {
		return decodeURIComponent(parts[4])
	} catch {
		return ''
	}
}
