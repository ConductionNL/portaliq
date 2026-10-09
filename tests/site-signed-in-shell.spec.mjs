#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-signed-in-shell.spec.mjs: the signed-in shell the site renderer shares
// with the React portal. The navigation built from the contributions answer,
// its in-site routes, the page registry later pages register in, the account
// menu and "logged in as" line, the portal API on the site's own bearer, and
// both languages for every string the shell shows.
//
// Usage:
//   node --test tests/site-signed-in-shell.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { createPortalApi } from '../src/shared/portalApi.js'
import {
	ACCOUNT_ROUTE,
	buildNav,
	defaultNavKey,
	isAccountRoute,
	navEntryForRoute,
	routeForNav,
	shellSections,
} from '../src/shared/portalNav.js'
import {
	accountCrumbs,
	accountRedirect,
	loggedInAs,
} from '../src/site/lib/accountArea.js'
import { signInRoutes } from '../src/site/lib/authApi.js'
import {
	CONTRIBUTION_PAGE,
	registerSitePage,
	sitePageKeys,
	sitePageLoader,
} from '../src/site/pages/registry.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const bundle = (locale) => JSON.parse(readFileSync(join(ROOT, 'src', 'shared', 'i18n', `${locale}.json`), 'utf8'))
const identity = (key, vars = {}) => key.replace(/\{(\w+)\}/g, (_, name) => String(vars[name] ?? ''))

/**
 * A translator over one shared bundle, the way src/shared/i18n/index.js builds
 * one (that module imports the JSON through webpack, which node cannot).
 *
 * @param {string} locale The language.
 * @return {(key: string, vars?: object) => string} The translator.
 */
function createTranslator(locale) {
	const strings = bundle(locale)
	return (key, vars = {}) => identity(strings[key] || key, vars)
}

/** A guardian's contributions answer: one app with two pages, cases and tasks on. */
const CONTRIBUTIONS = {
	contributions: [
		{
			app: 'learniq',
			pages: [
				{ id: 'children', label: 'My children' },
				{ id: 'absence', label: "My child's absence excuses" },
			],
		},
	],
	cases: { enabled: true },
	tasks: { enabled: true },
	unreadCount: 3,
}

test('the navigation leads with my cases, keeps the inbox and account last, and opens on a content page', () => {
	const nav = buildNav(CONTRIBUTIONS.contributions, identity, shellSections({ session: {}, contributions: CONTRIBUTIONS, threads: [], news: [] }))
	assert.deepEqual(nav.map((entry) => entry.key), [
		'__cases__', 'learniq:children', 'learniq:absence', '__tasks__', '__inbox__', '__access__', '__details__', '__account__',
	])
	assert.equal(defaultNavKey(nav), '__cases__')
	assert.equal(defaultNavKey(nav.filter((entry) => entry.special === 'inbox' || entry.special === 'account')), '__inbox__')
	assert.equal(defaultNavKey([]), null)
})

test('the shell sections follow what the answers announce', () => {
	assert.deepEqual(shellSections({ session: null, contributions: null, threads: null, news: null }), {
		tasks: false, messages: false, news: false, access: false, cases: false,
	})
	assert.deepEqual(shellSections({ session: {}, contributions: CONTRIBUTIONS, threads: [{ id: 't' }], news: [{ id: 'n' }] }), {
		tasks: true, messages: true, news: true, access: true, cases: true,
	})
	// Without any page or section there is no inbox either.
	assert.deepEqual(buildNav([], identity, {}), [])
})

test('every entry has its own in-site route under /mijn, and the route finds it back', () => {
	const nav = buildNav(CONTRIBUTIONS.contributions, identity, { cases: true })
	assert.equal(routeForNav(nav[0]), '/mijn/cases')
	assert.equal(routeForNav(nav[1]), '/mijn/learniq/children')
	for (const entry of nav) {
		assert.equal(navEntryForRoute(nav, routeForNav(entry)), entry)
	}
	assert.equal(isAccountRoute(ACCOUNT_ROUTE), true)
	assert.equal(isAccountRoute('/mijn/inbox'), true)
	assert.equal(isAccountRoute('/mijnheer'), false)
	assert.equal(isAccountRoute('/'), false)
	assert.equal(navEntryForRoute(nav, '/zoeken'), null)
})

test('a page not offered opens the default page; an offered page and the bare /mijn (the home) stay', () => {
	const nav = buildNav(CONTRIBUTIONS.contributions, identity, { cases: true })
	// site-mijn-omgeving-components REQ-SMO-007: /mijn is the home now.
	assert.equal(accountRedirect(nav, '/mijn'), '')
	assert.equal(accountRedirect(nav, '/mijn/other/page'), '/mijn')
	assert.equal(accountRedirect(nav, '/mijn/learniq/children'), '')
	assert.equal(accountRedirect(nav, '/zoeken'), '')
	assert.equal(accountRedirect([], '/mijn'), '')
})

test('the page registry resolves the entry key, then the section, then any contribution page, else nothing', async () => {
	const nav = buildNav(CONTRIBUTIONS.contributions, identity, { cases: true, access: true })
	const inbox = nav.find((entry) => entry.special === 'inbox')
	const children = nav.find((entry) => entry.key === 'learniq:children')
	assert.deepEqual(sitePageKeys(children), ['learniq:children', CONTRIBUTION_PAGE])
	assert.deepEqual(sitePageKeys(inbox), ['__inbox__', 'inbox'])

	// Slice d registers the inbox; every contribution page has slice b's
	// page from the start.
	assert.equal(typeof sitePageLoader(inbox), 'function')
	assert.equal(typeof sitePageLoader(children), 'function')

	const inboxPage = async () => ({ name: 'InboxPage' })
	const anyPage = async () => ({ name: 'ContributionPage' })
	const childrenPage = async () => ({ name: 'ChildrenPage' })
	registerSitePage('inbox', inboxPage)
	registerSitePage(CONTRIBUTION_PAGE, anyPage)
	assert.equal(sitePageLoader(inbox), inboxPage)
	assert.equal(sitePageLoader(children), anyPage)
	registerSitePage('learniq:children', childrenPage)
	assert.equal(sitePageLoader(children), childrenPage)
	assert.equal(sitePageLoader(nav.find((entry) => entry.key === 'learniq:absence')), anyPage)

	assert.throws(() => registerSitePage('', inboxPage), TypeError)
	assert.throws(() => registerSitePage('news', null), TypeError)
})

test('the breadcrumb of a signed-in page leads home, then to the own area', () => {
	const nav = buildNav(CONTRIBUTIONS.contributions, identity, { cases: true })
	assert.deepEqual(accountCrumbs(nav[1], identity, (route) => route).map((crumb) => crumb.label), ['Home', 'My area', 'My children'])
	assert.equal(accountCrumbs(null, identity, (route) => route).length, 2)
})

test('the header says who is signed in, in the site language', () => {
	const nl = createTranslator('nl')
	const fatima = { subjectRef: '99930md1id6bp47', displayName: 'Fatima Hulstkamp' }
	assert.equal(loggedInAs(fatima, nl), 'Ingelogd als Fatima Hulstkamp')
	assert.equal(loggedInAs(fatima, createTranslator('en')), 'Logged in as Fatima Hulstkamp')
	// The reference is never shown, not even when no name is known
	// (site-header-names-the-person).
	assert.equal(loggedInAs({ subjectRef: '99930md1id6bp47' }, nl), 'Ingelogd')
	assert.equal(loggedInAs({ subjectRef: 's1', sub: 's1', subject: 's1' }, nl), 'Ingelogd')
	assert.equal(loggedInAs({ subjectRef: 's1', displayName: '  ' }, nl), 'Ingelogd')
	assert.equal(loggedInAs({ subjectRef: 's1', displayName: 's1' }, nl), 'Ingelogd')
	// A number is never a name: a BSN shown as the display name reads as
	// "Ingelogd" (resident-sees-words-not-codes).
	assert.equal(loggedInAs({ subjectRef: 's1', displayName: '999993653' }, nl), 'Ingelogd')
	assert.equal(loggedInAs({ subjectRef: 's1', name: ' 123456782 ' }, nl), 'Ingelogd')
	assert.equal(loggedInAs({}, nl), 'Ingelogd')
	assert.equal(loggedInAs(null, nl), '')
})

test('the sign-in routes are labelled in the site language and keep the portal and the page to return to', () => {
	globalThis.window = { location: { pathname: '/apps/portaliq/site', search: '?portal=wilgenboom&route=%2Fmijn' } }
	try {
		const site = { slug: 'wilgenboom', authentication: { modes: ['public', 'digid'] } }
		const [digid] = signInRoutes(site, '/apps/portaliq/portal/api', createTranslator('en'))
		assert.equal(digid.label, 'Log in with DigiD')
		assert.match(digid.href, /provider=digid/)
		assert.match(digid.href, /portal=wilgenboom/)
		assert.match(digid.href, /returnTo=%2Fapps%2Fportaliq%2Fsite%3Fportal%3Dwilgenboom%26route%3D%252Fmijn/)
		assert.equal(signInRoutes(site, '/x', createTranslator('nl'))[0].label, 'Inloggen met DigiD')
		// Without a translator the labels stay the Dutch the site always showed.
		assert.equal(signInRoutes(site, '/x')[0].label, 'Inloggen met DigiD')
	} finally {
		delete globalThis.window
	}
})

test('the portal API reads and writes the bearer through the store the site hands it', async () => {
	let stored = 'site-bearer'
	const calls = []
	globalThis.fetch = async (url, init = {}) => {
		calls.push({ url, init })
		if (url.endsWith('/session/refresh')) {
			return { ok: true, json: async () => ({ token: 'rotated' }) }
		}
		return { ok: true, json: async () => CONTRIBUTIONS }
	}
	try {
		const api = createPortalApi(
			{ apiBase: '/apps/portaliq/portal/api', organisationSlug: 'wilgenboom' },
			{ getToken: () => stored, setToken: (token) => { stored = token } },
		)
		const answer = await api.getContributions()
		assert.equal(answer.unreadCount, 3)
		assert.equal(calls[0].url, '/apps/portaliq/portal/api/contributions')
		assert.equal(calls[0].init.headers.Authorization, 'Bearer site-bearer')
		assert.equal(calls[0].init.headers['X-Portaliq-Portal'], 'wilgenboom')
		await api.refreshSession()
		assert.equal(stored, 'rotated')
	} finally {
		delete globalThis.fetch
	}
})

test('every string the signed-in shell shows is in both languages', () => {
	const en = bundle('en')
	const nl = bundle('nl')
	const sources = [
		'src/site/App.vue',
		'src/site/components/AccountArea.vue',
		'src/site/pages/PlaceholderPage.vue',
		'src/site/lib/accountArea.js',
		'src/shared/portalNav.js',
	]
	const keys = new Set()
	for (const file of sources) {
		const text = readFileSync(join(ROOT, file), 'utf8')
		for (const match of text.matchAll(/\bt\(\s*'((?:[^'\\]|\\.)+)'/g)) {
			keys.add(match[1].replace(/\\'/g, "'"))
		}
	}
	assert.ok(keys.size > 20, `found ${keys.size} strings`)
	for (const key of keys) {
		assert.ok(key in en, `en lacks "${key}"`)
		assert.ok(key in nl, `nl lacks "${key}"`)
	}
	assert.equal(nl['This part of your portal is not available on this site yet.'], 'Dit deel van uw portaal is op deze site nog niet beschikbaar.')
})
