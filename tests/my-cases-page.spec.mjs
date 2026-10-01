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

import babel from '@babel/core'
import assert from 'node:assert/strict'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { compileLoading } from './support/compile-loading.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const OUT_DIR = join(ROOT, 'node_modules', '.cache', 'portaliq-tests-my-cases')

/**
 * Compile one portal file under src/portal and import it.
 *
 * @param {string} relative The path under src/portal.
 * @return {Promise<object>} The module.
 */
async function load(relative) {
	const source = join(ROOT, 'src', 'portal', relative)
	const compiled = babel.transformSync(readFileSync(source, 'utf8'), {
		filename: source,
		babelrc: false,
		configFile: false,
		presets: [['@babel/preset-react', { runtime: 'automatic' }]],
	})
	mkdirSync(OUT_DIR, { recursive: true })
	const flat = (path) => path.replace(/[\\/]/g, '_').replace(/\.jsx?$/, '.mjs')
	const code = compiled.code
		.replace(/from '\.\/([A-Za-z]+)\.jsx'/g, (whole, name) => `from './${flat('components/' + name + '.jsx')}'`)
		.replace(/from '\.\.\/lib\/([A-Za-z]+)\.js'/g, (whole, name) => `from './${flat('lib/' + name + '.js')}'`)
		.replace(/from '\.\.\/\.\.\/shared\/([A-Za-z]+)\.js'/g, (whole, name) => `from '${pathToFileURL(join(ROOT, 'src', 'shared', name + '.js')).href}'`)
	const out = join(OUT_DIR, flat(relative))
	writeFileSync(out, code)
	return import(pathToFileURL(out).href)
}

compileLoading(OUT_DIR)
const { createPortalApi } = await load('lib/portalApi.js')
const { splitCases, caseTarget, caseTitle } = await import(pathToFileURL(join(ROOT, 'src', 'shared', 'myCases.js')).href)
const { default: MyCasesPage } = await load('components/MyCasesPage.jsx')
const { createElement } = await import('react')
const { renderToStaticMarkup } = await import('react-dom/server')

const t = (key, vars = {}) => key.replace(/\{(\w+)\}/g, (_, name) => String(vars[name] ?? ''))

const DOSSIQ = { appId: 'dossiq', label: 'Zaken', register: 'dossiq', schema: 'zaak', collection: 'mijnZaken' }
const PIPELINQ = { appId: 'pipelinq', label: 'Aanvragen', register: 'pipelinq', schema: 'lead', collection: 'mijnAanvragen' }
const CASES = [
	{ id: 'z-2', title: 'Parkeervergunning', created: '2026-09-20T10:00:00+00:00', _source: PIPELINQ, _closed: false },
	{ id: 'z-1', title: 'Kapvergunning', created: '2026-09-10T10:00:00+00:00', _source: DOSSIQ, _closed: false },
	{ id: 'z-0', reference: 'ZAAK-0', created: '2026-01-10T10:00:00+00:00', _source: DOSSIQ, _closed: true },
]

/**
 * Install a fetch double that records every call and answers one reply.
 *
 * @param {{ok: boolean, status: number, body: object}} reply The answer.
 * @return {Array<object>} The recorded calls.
 */
function answer(reply) {
	const calls = []
	globalThis.window = { localStorage: { getItem: () => 'token-1', setItem() {}, removeItem() {} } }
	globalThis.fetch = async (url, init) => {
		calls.push({ url, init })
		return { ok: reply.ok, status: reply.status, json: async () => reply.body }
	}
	return calls
}

test('fetchMyCases reads the merged list with the bearer, and names a mandate only when one is chosen', async () => {
	const calls = answer({ ok: true, status: 200, body: { cases: CASES, mandates: [], activeMandate: null } })
	const api = createPortalApi({ apiBase: '/apps/portaliq/portal/api' })

	const own = await api.fetchMyCases()
	assert.equal(calls[0].url, '/apps/portaliq/portal/api/my-cases')
	assert.equal(calls[0].init.headers.Authorization, 'Bearer token-1')
	assert.equal(own.ok, true)
	assert.equal(own.cases.length, 3)
	assert.deepEqual(own.mandates, [])

	await api.fetchMyCases('mandate 1')
	assert.equal(calls[1].url, '/apps/portaliq/portal/api/my-cases?mandate=mandate%201')
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
	assert.deepEqual(open.map((row) => row.id), ['z-2', 'z-1'])
	assert.deepEqual(closed.map((row) => row.id), ['z-0'])
	assert.deepEqual(splitCases(null), { open: [], closed: [] })
})

test('a case opens where it came from, and names itself by title or reference', () => {
	assert.deepEqual(caseTarget(CASES[1]), { app: 'dossiq', collection: 'mijnZaken', id: 'z-1' })
	assert.deepEqual(caseTarget({ '@self': { id: 'x' }, _source: DOSSIQ }), { app: 'dossiq', collection: 'mijnZaken', id: 'x' })
	assert.equal(caseTarget({ id: 'y' }), null)
	assert.equal(caseTitle(CASES[0]), 'Parkeervergunning')
	assert.equal(caseTitle(CASES[2]), 'ZAAK-0')
})

test('every app\'s cases are in one list, each naming its source, with the tabs counted', () => {
	const html = renderToStaticMarkup(createElement(MyCasesPage, {
		api: {},
		t,
		initialData: { ok: true, cases: CASES },
		closedMarker: true,
		canOpen: (target) => target.app === 'dossiq',
		onOpenCase() {},
	}))
	assert.match(html, /<h2>My cases<\/h2>/)
	assert.match(html, /role="tab"[^>]*aria-selected="true"[^>]*>Open \(2\)</)
	assert.match(html, /role="tab"[^>]*aria-selected="false"[^>]*>Closed \(1\)</)
	// Newest first, each naming the app it comes from.
	assert.ok(html.indexOf('Parkeervergunning') < html.indexOf('Kapvergunning'))
	assert.match(html, /Parkeervergunning[\s\S]*Aanvragen/)
	assert.match(html, /Kapvergunning[\s\S]*Zaken/)
	assert.doesNotMatch(html, /ZAAK-0/)
	// A case no page of its app shows is not a link.
	assert.match(html, /<button[^>]*>Kapvergunning<\/button>/)
	assert.doesNotMatch(html, /<button[^>]*>Parkeervergunning<\/button>/)
})

test('the closed tab lists the closed cases, and is not there when nothing can be closed', () => {
	const closed = renderToStaticMarkup(createElement(MyCasesPage, {
		api: {}, t, initialData: { ok: true, cases: CASES }, closedMarker: true, initialTab: 'closed', canOpen: () => true, onOpenCase() {},
	}))
	assert.match(closed, /ZAAK-0/)
	assert.doesNotMatch(closed, /Kapvergunning/)

	const unmarked = renderToStaticMarkup(createElement(MyCasesPage, {
		api: {}, t, initialData: { ok: true, cases: CASES.slice(0, 2) }, closedMarker: false, canOpen: () => true, onOpenCase() {},
	}))
	assert.doesNotMatch(unmarked, /Closed/)
	assert.doesNotMatch(unmarked, /role="tab"/)
})

test('nothing to show reads "No cases yet."', () => {
	const html = renderToStaticMarkup(createElement(MyCasesPage, {
		api: {}, t, initialData: { ok: true, cases: [] }, closedMarker: true, canOpen: () => true, onOpenCase() {},
	}))
	assert.match(html, /No cases yet\./)
})

test('the shell offers "My cases" first when the server announces it, and both locales carry the strings', () => {
	const shell = readFileSync(join(ROOT, 'src', 'portal', 'App.jsx'), 'utf8')
	assert.match(shell, /import MyCasesPage from '@portal\/components\/MyCasesPage\.jsx'/)
	assert.match(shell, /state\.contributions\?\.cases\?\.enabled === true/)
	assert.match(shell, /nav\.unshift\(\{ key: CASES_KEY, label: t\('My cases'\)/)
	assert.match(shell, /<MyCasesPage/)
	assert.match(shell, /closedMarker=\{state\.contributions\?\.cases\?\.closedMarker === true\}/)
	assert.match(shell, /canOpen=\{\(target\) => navKeyFor\(nav, target\) !== null\}/)
	const nl = {
		'My cases': 'Mijn zaken',
		'Open ({count})': 'Lopend ({count})',
		'Closed ({count})': 'Afgerond ({count})',
		'No cases yet.': 'Nog geen zaken.',
		'No closed cases.': 'Geen afgeronde zaken.',
		'Your cases could not be loaded. Try again later.': 'Uw zaken konden niet worden geladen. Probeer het later opnieuw.',
	}
	for (const locale of ['en', 'nl']) {
		const bundle = JSON.parse(readFileSync(join(ROOT, 'src', 'portal', 'i18n', `${locale}.json`), 'utf8'))
		for (const [key, dutch] of Object.entries(nl)) {
			assert.equal(bundle[key], locale === 'nl' ? dutch : key, `${locale}: ${key}`)
		}
	}
})
