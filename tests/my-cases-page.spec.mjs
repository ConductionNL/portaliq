#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// my-cases-page.spec.mjs: the signed-in portal's "My cases" page
// (cases-my-cases-page). The API reads the merged list with the bearer; the
// page lists every app's cases in one list, each naming where it comes from;
// open and closed cases sit on their own tabs, and the "Closed" tab is only
// there when a collection can tell them apart; a case opens on the page of
// the app it came from, and one no page shows is not a link.
//
// Usage:
//   node --test tests/my-cases-page.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { createPortalApi } from '../src/shared/portalApi.js'
import { buildNav, shellSections } from '../src/shared/portalNav.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const { splitCases, caseTarget, caseTitle } = await import(
	pathToFileURL(join(ROOT, 'src', 'shared', 'myCases.js')).href
)

/**
 * A translator that only fills placeholders.
 *
 * @param {string} key The string.
 * @param {object} vars The placeholder values.
 * @return {string} The filled string.
 */
function t(key, vars = {}) {
	return key.replace(/\{(\w+)\}/g, (_, name) => String(vars[name] ?? ''))
}

const DOSSIQ = {
	appId: 'dossiq',
	label: 'Zaken',
	register: 'dossiq',
	schema: 'zaak',
	collection: 'mijnZaken',
}
const PIPELINQ = {
	appId: 'pipelinq',
	label: 'Aanvragen',
	register: 'pipelinq',
	schema: 'lead',
	collection: 'mijnAanvragen',
}
const CASES = [
	{
		id: 'z-2',
		title: 'Parkeervergunning',
		created: '2026-09-20T10:00:00+00:00',
		_source: PIPELINQ,
		_closed: false,
	},
	{
		id: 'z-1',
		title: 'Kapvergunning',
		created: '2026-09-10T10:00:00+00:00',
		_source: DOSSIQ,
		_closed: false,
	},
	{
		id: 'z-0',
		reference: 'ZAAK-0',
		created: '2026-01-10T10:00:00+00:00',
		_source: DOSSIQ,
		_closed: true,
	},
]

/**
 * Install a fetch double that records every call and answers one reply.
 *
 * @param {{ok: boolean, status: number, body: object}} reply The answer.
 * @return {Array<object>} The recorded calls.
 */
function answer(reply) {
	const calls = []
	globalThis.window = {
		localStorage: { getItem: () => 'token-1', setItem() {}, removeItem() {} },
	}
	globalThis.fetch = async (url, init) => {
		calls.push({ url, init })
		return { ok: reply.ok, status: reply.status, json: async () => reply.body }
	}
	return calls
}

test('fetchMyCases reads the merged list with the bearer, and names a mandate only when one is chosen', async () => {
	const calls = answer({
		ok: true,
		status: 200,
		body: { cases: CASES, mandates: [], activeMandate: null },
	})
	const api = createPortalApi({ apiBase: '/apps/portaliq/portal/api' })

	const own = await api.fetchMyCases()
	assert.equal(calls[0].url, '/apps/portaliq/portal/api/my-cases')
	assert.equal(calls[0].init.headers.Authorization, 'Bearer token-1')
	assert.equal(own.ok, true)
	assert.equal(own.cases.length, 3)
	assert.deepEqual(own.mandates, [])

	await api.fetchMyCases('mandate 1')
	assert.equal(
		calls[1].url,
		'/apps/portaliq/portal/api/my-cases?mandate=mandate%201',
	)
})

test('a refused list comes back as a refusal, never as an empty list', async () => {
	answer({ ok: false, status: 409, body: { error: 'group_too_large', bound: 50 } })
	const api = createPortalApi({ apiBase: '/apps/portaliq/portal/api' })

	const refused = await api.fetchMyCases('mandate-1')
	assert.equal(refused.ok, false)
	assert.equal(refused.error, 'group_too_large')
	assert.deepEqual(refused.cases, [])
})

test('open and closed cases are split by the server marker, keeping the order', () => {
	const { open, closed } = splitCases(CASES)
	assert.deepEqual(
		open.map((row) => row.id),
		['z-2', 'z-1'],
	)
	assert.deepEqual(
		closed.map((row) => row.id),
		['z-0'],
	)
	assert.deepEqual(splitCases(null), { open: [], closed: [] })
})

test('a case opens where it came from, and names itself by title or reference', () => {
	assert.deepEqual(caseTarget(CASES[1]), {
		app: 'dossiq',
		collection: 'mijnZaken',
		id: 'z-1',
	})
	assert.deepEqual(caseTarget({ '@self': { id: 'x' }, _source: DOSSIQ }), {
		app: 'dossiq',
		collection: 'mijnZaken',
		id: 'x',
	})
	assert.equal(caseTarget({ id: 'y' }), null)
	assert.equal(caseTitle(CASES[0]), 'Parkeervergunning')
	assert.equal(caseTitle(CASES[2]), 'ZAAK-0')
})

test('site: the shell offers "My cases" first when the server announces it, and both locales carry the strings', () => {
	const registry = readFileSync(
		join(ROOT, 'src', 'site', 'pages', 'registry.js'),
		'utf8',
	)
	assert.match(registry, /cases: accountPages\.__cases__/)
	assert.equal(
		shellSections({ contributions: { cases: { enabled: true } } }).cases,
		true,
	)
	assert.equal(shellSections({ contributions: {} }).cases, false)
	// The navigation itself is shared with the site renderer: My cases leads it.
	const nav = buildNav(
		[{ app: 'learniq', pages: [{ id: 'children', label: 'Children' }] }],
		(key) => key,
		{ cases: true },
	)
	assert.equal(nav[0].label, 'My cases')
	assert.equal(nav[0].special, 'cases')
	const area = readFileSync(
		join(ROOT, 'src', 'site', 'components', 'AccountArea.vue'),
		'utf8',
	)
	assert.match(
		area,
		/closedMarker: this\.contributions\?\.cases\?\.closedMarker === true/,
	)
	assert.match(
		area,
		/canOpen: \(target\) => navKeyFor\(this\.nav, target\) !== null/,
	)
	assert.match(area, /openCase: \(target, row\) => this\.openCase\(target, row\)/)
	const nl = {
		'My cases': 'Mijn zaken',
		'Open ({count})': 'Lopend ({count})',
		'Closed ({count})': 'Afgerond ({count})',
		'No cases yet.': 'Nog geen zaken.',
		'No closed cases.': 'Geen afgeronde zaken.',
		'Your cases could not be loaded. Try again later.':
			'Uw zaken konden niet worden geladen. Probeer het later opnieuw.',
	}
	for (const locale of ['en', 'nl']) {
		const bundle = JSON.parse(
			readFileSync(
				join(ROOT, 'src', 'shared', 'i18n', `${locale}.json`),
				'utf8',
			),
		)
		for (const [key, dutch] of Object.entries(nl)) {
			assert.equal(
				bundle[key],
				locale === 'nl' ? dutch : key,
				`${locale}: ${key}`,
			)
		}
	}
})

// The Vue port on the site (site-reaches-portal-parity T20, REQ-SRP-040).

const { renderSfc, loadSfc } = await import('./support/render-sfc.mjs')
const SITE_PAGE = 'src/site/pages/e/MyCasesPage.vue'

test("site: every app's cases are in one list, each naming its source, with the tabs counted", async () => {
	const html = await renderSfc(SITE_PAGE, {
		api: {},
		t,
		initialData: { ok: true, cases: CASES },
		closedMarker: true,
		canOpen: (target) => target.app === 'dossiq',
	})
	assert.match(html, /<h1[^>]*>My cases<\/h1>/)
	assert.match(html, /role="tab"[^>]*aria-selected="true"[^>]*>Open \(2\)</)
	assert.match(html, /role="tab"[^>]*aria-selected="false"[^>]*>Closed \(1\)</)
	assert.match(html, /role="tabpanel"/)
	assert.ok(html.indexOf('Parkeervergunning') < html.indexOf('Kapvergunning'))
	assert.match(html, /Parkeervergunning[\s\S]*Aanvragen/)
	assert.match(html, /Kapvergunning[\s\S]*Zaken/)
	assert.doesNotMatch(html, /ZAAK-0/)
	assert.match(html, /<button[^>]*>Kapvergunning<\/button>/)
	assert.doesNotMatch(html, /<button[^>]*>Parkeervergunning<\/button>/)
})

test("site: without the shell's page lookup a case is listed but not a button", async () => {
	const html = await renderSfc(SITE_PAGE, {
		api: {},
		t,
		initialData: { ok: true, cases: CASES },
	})
	assert.match(html, /<span class="pq-cases__title">Kapvergunning<\/span>/)
	assert.doesNotMatch(html, /pq-cases__open/)
})

test('site: the closed tab lists the closed cases, is not there when nothing can be closed, and nothing reads "No cases yet."', async () => {
	const closed = await renderSfc(SITE_PAGE, {
		api: {},
		t,
		initialData: { ok: true, cases: CASES },
		closedMarker: true,
		initialTab: 'closed',
	})
	assert.match(closed, /ZAAK-0/)
	assert.doesNotMatch(closed, /Kapvergunning/)
	const unmarked = await renderSfc(SITE_PAGE, {
		api: {},
		t,
		initialData: { ok: true, cases: CASES.slice(0, 2) },
	})
	assert.doesNotMatch(unmarked, /Closed/)
	assert.doesNotMatch(unmarked, /role="tab"/)
	const none = await renderSfc(SITE_PAGE, {
		api: {},
		t,
		initialData: { ok: true, cases: [] },
		closedMarker: true,
	})
	assert.match(none, /No cases yet\./)
	const failed = await renderSfc(SITE_PAGE, {
		api: {},
		t,
		initialData: { ok: false, error: 'other', cases: [] },
	})
	assert.match(
		failed,
		/role="alert"[^>]*>Your cases could not be loaded\. Try again later\.</,
	)
})

test('site: opening a case hands the shell the target and the row', async () => {
	const page = await loadSfc(SITE_PAGE)
	const opened = []
	const vm = {
		shown: CASES.slice(0, 2),
		locale: 'nl',
		canOpen: () => true,
		openCase: (target, row) => opened.push([target, row.id]),
	}
	const rows = page.computed.rows.call(vm)
	assert.equal(rows[1].openable, true)
	assert.equal(rows[1].source, 'Zaken')
	assert.match(rows[1].date, /2026/)
	vm.openCase(rows[1].target, rows[1].row)
	assert.deepEqual(opened, [
		[{ app: 'dossiq', collection: 'mijnZaken', id: 'z-1' }, 'z-1'],
	])
})
