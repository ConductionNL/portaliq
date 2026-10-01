#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// case-timeline.spec.mjs: a resident sees the history of their case in the
// portal (portaliq#723).
//
// Usage:
//   node --test tests/case-timeline.spec.mjs
//
// dossiq declared `timeline: {label: 'Wat er is gebeurd', provider:
// 'caseTimeline'}` on its "Mijn zaken" collection and portaliq never read it.
// These pin the portal half: the SPA asks the scoped timeline route for the
// selected case, and renders what comes back under the declared label, newest
// first, with an empty state, without dropping or adding an entry.

import assert from 'node:assert/strict'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { compileLoading, LOADING_MODULE } from './support/compile-loading.mjs'

const require = createRequire(import.meta.url)
const babel = require('@babel/core')
const { createElement } = require('react')
const { renderToStaticMarkup } = require('react-dom/server')

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const OUT_DIR = join(ROOT, 'node_modules', '.cache', 'portaliq-tests')

/**
 * Compile one portal source file with the portal build's React preset and
 * import it from where `react` resolves.
 *
 * @param {string} relative - the path under src/portal
 * @return {Promise<object>} the module
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
	const out = join(OUT_DIR, relative.replace(/[\\/]/g, '_').replace(/\.jsx?$/, '.mjs'))
	compileLoading(OUT_DIR)
	writeFileSync(out, compiled.code.replace("'./Loading.jsx'", `'${LOADING_MODULE}'`))
	return import(pathToFileURL(out).href)
}

const { default: TimelineList, newestFirst } = await load('components/TimelineList.jsx')
const { createPortalApi } = await load('lib/portalApi.js')

const ENTRIES = [
	{ id: 'e1', kind: 'contact-moment', message: 'Telefonisch gesproken', occurredAt: '2026-09-18T09:00:00+00:00' },
	{ id: 'e3', kind: 'status-move', message: 'Besluit genomen', occurredAt: '2026-09-25T15:00:00+00:00' },
	{ id: 'e2', kind: 'letter', message: 'Brief verstuurd', occurredAt: '2026-09-20T10:00:00+00:00' },
]

test('the entries render under the declared label, newest first, none dropped', () => {
	const html = renderToStaticMarkup(createElement(TimelineList, { label: 'Wat er is gebeurd', entries: ENTRIES }))

	assert.match(html, /<h4>Wat er is gebeurd<\/h4>/)
	const order = ['Besluit genomen', 'Brief verstuurd', 'Telefonisch gesproken'].map((text) => html.indexOf(text))
	assert.ok(order.every((at) => at > -1), 'every entry the provider returned is shown')
	assert.deepEqual([...order].sort((a, b) => a - b), order, 'newest first')
})

test('ordering never changes the list the provider returned', () => {
	const given = [...ENTRIES]

	assert.deepEqual(newestFirst(given).map((e) => e.id), ['e3', 'e2', 'e1'])
	assert.deepEqual(given, ENTRIES)
})

test('a case with no history says so', () => {
	const html = renderToStaticMarkup(createElement(TimelineList, { label: 'Wat er is gebeurd', entries: [] }))

	assert.match(html, /Er is nog niets gebeurd\./)
	assert.doesNotMatch(html, /<ol>/)
})

test('the SPA asks the scoped timeline route of the selected case', async () => {
	const calls = []
	globalThis.window = { localStorage: { getItem: () => 'token-1', setItem() {}, removeItem() {} } }
	globalThis.fetch = async (url, init) => {
		calls.push({ url, init })
		return { ok: true, json: async () => ({ label: 'Wat er is gebeurd', entries: ENTRIES }) }
	}
	const api = createPortalApi({ apiBase: '/apps/portaliq/portal/api' })

	const timeline = await api.fetchTimeline({ id: 'mijnZaken', register: 'dossiq', schema: 'case' }, 'case 1')

	assert.equal(calls.length, 1)
	assert.equal(calls[0].url, '/apps/portaliq/portal/api/collections/dossiq/case/case%201/timeline?collection=mijnZaken')
	assert.equal(calls[0].init.headers.Authorization, 'Bearer token-1')
	assert.deepEqual(timeline.entries, ENTRIES)
})

test('a refused or failed timeline read is null, not an empty history', async () => {
	globalThis.fetch = async () => ({ ok: false, status: 404, json: async () => ({ error: 'not_found' }) })
	const api = createPortalApi({ apiBase: '/apps/portaliq/portal/api' })

	assert.equal(await api.fetchTimeline({ id: 'mijnZaken', register: 'dossiq', schema: 'case' }, 'x'), null)
})

// The same promises, held by the site's Vue timeline (site-reaches-portal-
// parity slice b, REQ-SRP-019). The React half above goes when /portal retires.

const { renderSfc } = await import('./support/render-sfc.mjs')
const { newestFirst: siteNewestFirst } = await import('../src/site/components/collections/timeline.js')
const VUE_TIMELINE = 'src/site/components/collections/TimelineList.vue'
const siteT = (key) => key

test('site: the entries render under the declared label, newest first, none dropped', async () => {
	const html = await renderSfc(VUE_TIMELINE, { label: 'Wat er is gebeurd', entries: ENTRIES, t: siteT, locale: 'nl' })

	assert.match(html, /<h3 class="utrecht-heading-4">Wat er is gebeurd<\/h3>/)
	const order = ['Besluit genomen', 'Brief verstuurd', 'Telefonisch gesproken'].map((text) => html.indexOf(text))
	assert.ok(order.every((at) => at > -1), 'every entry the provider returned is shown')
	assert.deepEqual([...order].sort((a, b) => a - b), order, 'newest first')
	assert.match(html, /<time class="pq-timeline__moment" datetime="2026-09-25T15:00:00\+00:00">/)
})

test('site: ordering never changes the list the provider returned', () => {
	const given = [...ENTRIES]
	assert.deepEqual(siteNewestFirst(given).map((e) => e.id), ['e3', 'e2', 'e1'])
	assert.deepEqual(given, ENTRIES)
})

test('site: a case with no history says so, and one still loading says that', async () => {
	const empty = await renderSfc(VUE_TIMELINE, { label: 'Wat er is gebeurd', entries: [], t: siteT })
	assert.match(empty, /Nothing has happened yet\./)
	assert.doesNotMatch(empty, /<ol/)
	const loading = await renderSfc(VUE_TIMELINE, { entries: null, t: siteT })
	assert.match(loading, /aria-busy="true"/)
	assert.match(loading, /What happened/)
})
