/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The site's signed-in shell in plain functions: the "logged in as" line,
 * the breadcrumb of a signed-in page and which route to open. The resident's
 * own menu is lib/residentMenu.js. Imports only shared code, so
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
 * The "logged in as" line for a session.
 *
 * Names the person by the display name the session carries (the portal
 * account's, from provisioning or the broker). The subject reference is an
 * internal key and is never shown, and neither is a number (a BSN is no
 * name): without a name the line is a plain "Logged in"
 * (site-header-names-the-person, resident-sees-words-not-codes).
 *
 * @param {object|null} session The session.
 * @param {(key: string, vars?: object) => string} t The translator.
 * @return {string} The line, or '' without a session.
 * @spec openspec/changes/site-header-names-the-person/specs/portaliq-cms/spec.md#requirement-the-header-must-name-the-signed-in-person-never-their-reference
 * @spec openspec/changes/resident-sees-words-not-codes/specs/portaliq-cms/spec.md#requirement-the-header-must-never-name-a-person-by-a-number
 */
export function loggedInAs(session, t) {
	if (!session) {
		return ''
	}
	const name = String(session.displayName || session.name || '').trim()
	const reference = String(session.subjectRef || '')
	if (name === '' || name === reference || /^\d+$/.test(name)) {
		return t('Logged in')
	}
	return t('Logged in as {name}', { name })
}

/**
 * Where an account route should go instead, or '' to stay: a page the
 * navigation does not offer opens the default entry, once the navigation has
 * loaded. The bare `/mijn` stays: it is the home.
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
	// `/mijn` itself opens the resident's home, it no longer redirects
	// (site-mijn-omgeving-components REQ-SMO-007, design D4).
	if (route === ACCOUNT_ROUTE) {
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
			label: t('My area'),
			href: hrefFor(ACCOUNT_ROUTE),
		},
	]
	// A record page under Mijn zaken passes through it
	// (zuiddrecht-resident-pages-match-the-boards).
	if (entry?.page?.record?.under === 'cases') {
		const route = routeForNav({ special: 'cases' })
		crumbs.push({ route, label: t('My cases'), href: hrefFor(route) })
	}
	if (entry) {
		const route = routeForNav(entry)
		crumbs.push({ route, label: entry.label, href: hrefFor(route) })
	}
	return crumbs
}
