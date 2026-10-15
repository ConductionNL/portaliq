#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// step-up-prompt.spec.mjs: asking for a higher login
// (site-member-voting-record-and-confidential-papers T6, REQ-SCR-004, board
// Inloggen). A collection the login is too low for becomes a page in the
// signed-in area at the route its page would have had; that page names the
// login that reaches it, offers only the routes that do, comes back to the
// same page after signing in, and says so plainly when no route reaches it.
//
// Usage:
//   node --test tests/step-up-prompt.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	buildNav,
	defaultNavKey,
	navEntryForRoute,
	routeForNav,
	shellSections,
	stepUpEntries,
} from '../src/shared/portalNav.js'
import { providerPhrase, stepUpWays, withReturnTo } from '../src/site/lib/stepUp.js'
import { mountSfc } from './support/mount-sfc.mjs'
import { renderSfc } from './support/render-sfc.mjs'

const PROMPT = 'src/site/components/mijn/StepUpPrompt.vue'
const AREA = 'src/site/components/AccountArea.vue'

function t(key, vars = {}) {
	return key.replace(/\{(\w+)\}/g, (_, name) => String(vars[name] ?? ''))
}

const papers = {
	app: 'decidiq',
	collection: 'confidentialPapers',
	label: 'Vertrouwelijke stukken',
	minTrust: 'substantial',
}

const edge = '/apps/portaliq/portal/api/auth'
const routes = [
	{
		key: 'nextcloud',
		mode: 'nextcloud',
		label: 'Inloggen met uw account',
		href: `${edge}/login?portal=zuiddrecht&returnTo=%2Fmijn`,
	},
	{
		key: 'digid',
		mode: 'digid',
		label: 'Inloggen met DigiD',
		card: {
			title: 'Als inwoner',
			text: 'Voor uw eigen zaken, berichten en dossiers.',
		},
		href: `${edge}/login?provider=digid&portal=zuiddrecht&returnTo=%2Fmijn`,
	},
	{
		key: 'eherkenning',
		mode: 'eherkenning',
		label: 'Inloggen met eHerkenning',
		card: { title: 'Namens een bedrijf' },
		href: `${edge}/login?provider=eherkenning&portal=zuiddrecht&returnTo=%2Fmijn`,
	},
	{
		key: 'example-resident:pieter',
		mode: 'example-resident',
		label: 'Inloggen als Pieter',
		href: `${edge}/example?id=zd&returnTo=%2Fmijn`,
	},
]

const back = '/apps/portaliq/site/zuiddrecht/mijn/decidiq/confidentialPapers'

test('each step-up collection becomes one entry at the route its page would have had', () => {
	const entries = stepUpEntries([papers])
	assert.equal(entries.length, 1)
	assert.equal(entries[0].key, 'decidiq:confidentialPapers')
	assert.equal(entries[0].label, 'Vertrouwelijke stukken')
	assert.deepEqual(entries[0].stepUp, {
		app: 'decidiq',
		collection: 'confidentialPapers',
		minTrust: 'substantial',
	})
	assert.equal(
		entries[0].page,
		undefined,
		'no page to load, so nothing of it leaks',
	)
	assert.equal(routeForNav(entries[0]), '/mijn/decidiq/confidentialPapers')
})

test('a step-up entry never takes a route a real page holds, and a malformed item is skipped', () => {
	const nav = [
		{ key: 'decidiq:confidentialPapers', page: { id: 'confidentialPapers' } },
	]
	assert.deepEqual(stepUpEntries([papers], nav), [])
	assert.deepEqual(
		stepUpEntries([
			{ app: 'decidiq' },
			{ collection: 'x' },
			null,
			'x',
			papers,
			papers,
		]).map((entry) => entry.key),
		['decidiq:confidentialPapers'],
		'one entry per collection, none without app and collection',
	)
	assert.equal(
		stepUpEntries([{ ...papers, label: '' }])[0].label,
		'confidentialPapers',
	)
	assert.deepEqual(stepUpEntries(null), [])
})

test('the navigation lists the step-up page last, finds it by route and never opens on it', () => {
	const aggregate = {
		contributions: [{ app: 'decidiq', pages: [] }],
		stepUp: [papers],
	}
	const sections = shellSections({
		session: { subjectRef: 'p' },
		contributions: aggregate,
	})
	assert.deepEqual(sections.stepUp, [papers])
	const nav = buildNav(aggregate.contributions, t, sections)
	assert.equal(nav[nav.length - 1].key, 'decidiq:confidentialPapers')
	assert.equal(
		navEntryForRoute(nav, '/mijn/decidiq/confidentialPapers').stepUp.collection,
		'confidentialPapers',
	)
	assert.notEqual(defaultNavKey(nav), 'decidiq:confidentialPapers')
	assert.equal(
		defaultNavKey(stepUpEntries([papers]).concat([{ key: 'b', label: 'B' }])),
		'b',
		'a real page is the default even after a step-up entry',
	)
})

test('signed out there is no step-up entry', () => {
	assert.deepEqual(
		shellSections({ session: null, contributions: { stepUp: [papers] } }).stepUp,
		[],
	)
})

test('a sign-in link comes back to the page the resident asked from', () => {
	const href = withReturnTo(routes[1].href, back)
	assert.equal(
		href,
		`${edge}/login?provider=digid&portal=zuiddrecht&returnTo=${encodeURIComponent(back)}`,
	)
	assert.equal(
		withReturnTo('/login#x', '/mijn/a'),
		'/login?returnTo=%2Fmijn%2Fa#x',
		'a link without a query gets one, before its hash',
	)
	assert.equal(
		withReturnTo(routes[1].href, ''),
		routes[1].href,
		'no path, no change',
	)
	assert.equal(
		withReturnTo(routes[1].href, 'https://evil.example/x'),
		routes[1].href,
		'only an in-site path is a place to come back to',
	)
})

test('only DigiD, eHerkenning and eIDAS reach a higher login', () => {
	const ways = stepUpWays(routes, back)
	assert.deepEqual(
		ways.map((way) => way.mode),
		['digid', 'eherkenning'],
	)
	for (const way of ways) {
		assert.match(way.href, new RegExp(`returnTo=${encodeURIComponent(back)}$`))
	}
	assert.equal(providerPhrase(ways, t), 'DigiD or eHerkenning')
	assert.equal(providerPhrase(ways.slice(0, 1), t), 'DigiD')
	assert.equal(
		providerPhrase([...ways, { mode: 'eidas' }], t),
		'DigiD, eHerkenning or eIDAS',
	)
	assert.equal(providerPhrase([], t), '')
})

test('the Dutch sentence is the one the spec names', () => {
	// The bundle the site's translator reads (src/shared/i18n/index.js).
	const bundle = JSON.parse(
		readFileSync(new URL('../src/shared/i18n/nl.json', import.meta.url), 'utf8'),
	)
	const nl = (key, vars) => t(bundle[key] ?? key, vars)
	const ways = stepUpWays(routes, back)
	assert.equal(
		nl('For these papers you must log in with {providers}.', {
			providers: providerPhrase(ways, nl),
		}),
		'Voor deze stukken moet u inloggen met DigiD of eHerkenning.',
	)
	assert.equal(
		nl('Logging in with DigiD is not available here yet.'),
		'Inloggen met DigiD is hier nog niet beschikbaar.',
	)
})

test('the prompt shows the cards that reach the level and no item or count', async () => {
	const html = await renderSfc(PROMPT, { stepUp: papers, routes, back, t })
	assert.match(
		html,
		/For these papers you must log in with DigiD or eHerkenning\./,
	)
	assert.equal((html.match(/data-testid="site-step-up-card"/g) || []).length, 2)
	assert.match(html, /Als inwoner/)
	assert.match(html, /Voor uw eigen zaken, berichten en dossiers\./)
	assert.match(html, /Namens een bedrijf/)
	assert.doesNotMatch(html, /Inloggen met uw account|Inloggen als Pieter/)
	assert.match(
		html,
		/data-mode="digid"[^>]*>|class="[^"]*primary-action[^"]*"[^>]*data-mode="digid"/,
	)
	assert.ok(
		html.includes(`returnTo=${encodeURIComponent(back)}`),
		'each card comes back to this page',
	)
	assert.doesNotMatch(html, /site-step-up-none/)
})

test('without a route that reaches the level the prompt says so instead of a dead button', async () => {
	const html = await renderSfc(PROMPT, {
		stepUp: papers,
		routes: routes.filter((way) => !['digid', 'eherkenning'].includes(way.mode)),
		back,
		t,
	})
	assert.match(html, /Logging in with DigiD is not available here yet\./)
	assert.doesNotMatch(html, /site-step-up-card|site-step-up-route/)
})

test('the signed-in area shows the prompt under the page title on a step-up entry', async () => {
	const [entry] = stepUpEntries([papers])
	const area = await mountSfc(AREA, {
		sessionKnown: true,
		session: { subjectRef: 'pieter' },
		nav: [entry],
		entry,
		contributions: { contributions: [], stepUp: [papers] },
		signInRoutes: routes,
		t,
		currentRoute: '/mijn/decidiq/confidentialPapers',
	})
	await area.flush()
	assert.equal(
		area.textOf(area.find('site-account-title')),
		'Vertrouwelijke stukken',
	)
	assert.ok(area.find('site-step-up'), 'the prompt is the page')
	assert.equal(
		area.textOf(area.find('site-step-up-text')),
		'For these papers you must log in with DigiD or eHerkenning.',
	)
	assert.equal(
		area.findAll((n) => n.props?.['data-testid'] === 'site-step-up-card').length,
		2,
	)
})
