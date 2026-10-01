/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The site's signed-in shell in plain functions: the account menu in the
 * shape SiteMenu renders, the "logged in as" line, the breadcrumb of a
 * signed-in page and which route to open. Imports only shared code, so
 * tests/site-signed-in-shell.spec.mjs runs it as node.
 *
 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
 */

import {
	ACCOUNT_ROUTE,
	defaultNavKey,
	isAccountRoute,
	navEntryForRoute,
	routeForNav,
} from '../../shared/portalNav.js'

/**
 * The signed-in navigation as one SiteMenu menu.
 *
 * @param {Array<object>} nav The navigation (src/shared/portalNav.js).
 * @param {(key: string, vars?: object) => string} t The translator.
 * @param {number} unread The inbox's unread count.
 * @param {(route: string) => string} hrefFor A real address for a route.
 * @return {{title: string, items: Array<object>}} The menu.
 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
 */
export function accountMenu(nav, t, unread, hrefFor) {
	return {
		title: t('My overview'),
		items: (nav || []).map((entry) => {
			const link = routeForNav(entry)
			const item = { name: entry.label, link, href: hrefFor(link) }
			if (entry.special === 'inbox' && Number(unread) > 0) {
				item.badge = String(unread)
				item.badgeLabel = t('{count} unread', { count: unread })
			}
			return item
		}),
	}
}

/**
 * The "logged in as" line for a session.
 *
 * @param {object|null} session The session.
 * @param {(key: string, vars?: object) => string} t The translator.
 * @return {string} The line, or '' without a session.
 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
 */
export function loggedInAs(session, t) {
	if (!session) {
		return ''
	}
	const who =
		session.name || session.subjectRef || session.subject || session.sub || ''
	return who ? t('Logged in as {subjectRef}', { subjectRef: who }) : t('Logged in')
}

/**
 * Where an account route should go instead, or '' to stay: the bare `/mijn`
 * and a page the navigation does not offer open the default entry, once the
 * navigation has loaded.
 *
 * @param {Array<object>} nav The navigation.
 * @param {string} route The route on screen.
 * @return {string} The route to replace it with, or ''.
 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
 */
export function accountRedirect(nav, route) {
	if (!isAccountRoute(route) || !Array.isArray(nav) || nav.length === 0) {
		return ''
	}
	if (navEntryForRoute(nav, route)) {
		return ''
	}
	const key = defaultNavKey(nav)
	const entry = nav.find((candidate) => candidate.key === key)
	return entry ? routeForNav(entry) : ''
}

/**
 * The breadcrumb of a signed-in page: home, the overview, the page.
 *
 * @param {object|null} entry The entry on screen, or null.
 * @param {(key: string) => string} t The translator.
 * @param {(route: string) => string} hrefFor A real address for a route.
 * @return {Array<{route: string, label: string, href: string}>} The crumbs.
 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
 */
export function accountCrumbs(entry, t, hrefFor) {
	const crumbs = [
		{ route: '/', label: t('Home'), href: hrefFor('/') },
		{
			route: ACCOUNT_ROUTE,
			label: t('My overview'),
			href: hrefFor(ACCOUNT_ROUTE),
		},
	]
	if (entry) {
		const route = routeForNav(entry)
		crumbs.push({ route, label: entry.label, href: hrefFor(route) })
	}
	return crumbs
}
