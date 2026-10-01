#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// my-cases-acting-for.spec.mjs: whom a person acts for in the portal
// (cases-my-cases-page REQ-CMC-003, REQ-CMC-004). The header offers
// "Acting for" with yourself and every mandate held; the choice is kept for
// the session and sent as `mandate` on the case list; a mandated case names
// its mandate, opens on its app's page and reads the case screen under that
// mandate; a group too large to list is refused with a sentence, never shown
// as a short list.
//
// Usage:
//   node --test tests/my-cases-acting-for.spec.mjs

import babel from '@babel/core'
import assert from 'node:assert/strict'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { compileLoading } from './support/compile-loading.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const OUT_DIR = join(ROOT, 'node_modules', '.cache', 'portaliq-tests-acting-for')

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
const { createPortalApi } = await load('../shared/portalApi.js')
const myCases = await import(pathToFileURL(join(ROOT, 'src', 'shared', 'myCases.js')).href)
const { default: MyCasesPage } = await load('components/MyCasesPage.jsx')
const { default: ActingForSwitcher } = await load('components/ActingForSwitcher.jsx')
const { createElement } = await import('react')
const { renderToStaticMarkup } = await import('react-dom/server')

const t = (key, vars = {}) => key.replace(/\{(\w+)\}/g, (_, name) => String(vars[name] ?? ''))
const MANDATES = [
	{ id: 'mandate-1', label: 'Bakkerij Jansen BV' },
	{ id: 'mandate-2', label: 'Mijn vader' },
]

/**
 * A sessionStorage double.
 *
 * @return {object} The storage.
 */
function storage() {
	const kept = new Map()
	return {
		getItem: (key) => (kept.has(key) ? kept.get(key) : null),
		setItem: (key, value) => kept.set(key, String(value)),
		removeItem: (key) => kept.delete(key),
	}
}

test('the case screen is read under the mandate the case was listed under', async () => {
	const calls = []
	globalThis.window = { localStorage: { getItem: () => 'token-1', setItem() {}, removeItem() {} } }
	globalThis.fetch = async (url, init) => {
		calls.push({ url, init })
		return { ok: true, status: 200, json: async () => ({ case: {} }) }
	}
	const api = createPortalApi({ apiBase: '/apps/portaliq/portal/api' })
	const collection = { register: 'dossiq', schema: 'case' }

	await api.fetchCitizenCase(collection, 'zaak 9')
	await api.fetchCitizenCase(collection, 'zaak 9', 'mandate 1')

	assert.equal(calls[0].url, '/apps/portaliq/portal/api/citizen/cases/dossiq/case/zaak%209')
	assert.equal(calls[1].url, '/apps/portaliq/portal/api/citizen/cases/dossiq/case/zaak%209?mandate=mandate%201')
})

test('acting for is yourself plus every mandate held, and the choice is kept for the session', () => {
	assert.equal(myCases.ACTING_FOR_SELF, 'self')
	assert.deepEqual(myCases.actingForOptions(MANDATES, t), [
		{ id: 'self', label: 'Yourself' },
		{ id: 'mandate-1', label: 'Bakkerij Jansen BV' },
		{ id: 'mandate-2', label: 'Mijn vader' },
	])

	const kept = storage()
	assert.equal(myCases.readActingFor(kept), 'self')
	myCases.keepActingFor(kept, 'mandate-2')
	assert.equal(myCases.readActingFor(kept), 'mandate-2')
	// A mandate no longer held falls back to yourself.
	assert.equal(myCases.actingForHeld('mandate-2', MANDATES), 'mandate-2')
	assert.equal(myCases.actingForHeld('mandate-9', MANDATES), 'self')
	// A browser that refuses storage acts for yourself, and never throws.
	const refusing = { getItem() { throw new Error('denied') }, setItem() { throw new Error('denied') } }
	assert.equal(myCases.readActingFor(refusing), 'self')
	assert.doesNotThrow(() => myCases.keepActingFor(refusing, 'mandate-1'))
	assert.equal(myCases.readActingFor(null), 'self')
})

test('the header switcher names yourself and each mandate, with the choice selected', () => {
	const html = renderToStaticMarkup(createElement(ActingForSwitcher, { t, mandates: MANDATES, value: 'mandate-1', onChange() {} }))
	assert.match(html, /<label[^>]*for="portaliq-acting-for"[^>]*>Acting for<\/label>/)
	assert.match(html, /<select[^>]*id="portaliq-acting-for"/)
	assert.match(html, /<option value="self">Yourself<\/option>/)
	assert.match(html, /<option value="mandate-1" selected="">Bakkerij Jansen BV<\/option>/)
	assert.match(html, /<option value="mandate-2">Mijn vader<\/option>/)

	assert.equal(renderToStaticMarkup(createElement(ActingForSwitcher, { t, mandates: [], value: 'self', onChange() {} })), '')
})

test('a mandated case carries the mandate\'s label', () => {
	const html = renderToStaticMarkup(createElement(MyCasesPage, {
		api: {},
		t,
		initialData: {
			ok: true,
			cases: [{ id: 'z-9', title: 'Terrasvergunning', _source: { appId: 'dossiq', label: 'Zaken', collection: 'mijnZaken' }, _mandate: { id: 'mandate-1', label: 'Bakkerij Jansen BV' } }],
		},
		canOpen: () => true,
		onOpenCase() {},
	}))
	assert.match(html, /Terrasvergunning[\s\S]*data-testid="my-cases-mandate"[^>]*>Bakkerij Jansen BV</)
})

test('a group too large to list is refused with its sentence, and nothing else is listed', () => {
	const html = renderToStaticMarkup(createElement(MyCasesPage, {
		api: {},
		t,
		initialData: { ok: false, status: 409, error: 'group_too_large', cases: [] },
		canOpen: () => true,
		onOpenCase() {},
	}))
	assert.match(html, /role="alert"[^>]*>This organisation has too many cases to list here\. Choose a narrower mandate\.</)
	assert.doesNotMatch(html, /No cases yet/)
	assert.doesNotMatch(html, /my-cases-list/)
})

test('the shell, the page, the case screen and both locales are wired', () => {
	const shell = readFileSync(join(ROOT, 'src', 'portal', 'App.jsx'), 'utf8')
	assert.match(shell, /<ActingForSwitcher/)
	assert.match(shell, /mandateId=\{actingFor\}/)
	assert.match(shell, /keepActingFor\(sessionStore\(\), /)
	assert.match(shell, /onLoaded=\{/)
	assert.match(shell, /setOpenTarget\(\{ \.\.\.target, row \}\)/)
	const page = readFileSync(join(ROOT, 'src', 'portal', 'components', 'PageView.jsx'), 'utf8')
	assert.match(page, /rowFor\(loaded\.objects, openRecord\.id\) \|\| openRecord\.row \|\| null/)
	const screen = readFileSync(join(ROOT, 'src', 'portal', 'components', 'CitizenCase.jsx'), 'utf8')
	assert.match(screen, /api\.fetchCitizenCase\(collection, caseId, mandateId\)/)
	const nl = {
		'Acting for': 'Namens',
		Yourself: 'Uzelf',
		'This organisation has too many cases to list here. Choose a narrower mandate.': 'Deze organisatie heeft te veel zaken om hier te tonen. Kies een smallere machtiging.',
	}
	for (const locale of ['en', 'nl']) {
		const bundle = JSON.parse(readFileSync(join(ROOT, 'src', 'shared', 'i18n', `${locale}.json`), 'utf8'))
		for (const [key, dutch] of Object.entries(nl)) {
			assert.equal(bundle[key], locale === 'nl' ? dutch : key, `${locale}: ${key}`)
		}
	}
})

// The Vue port on the site (site-reaches-portal-parity T20, REQ-SRP-041).

const { renderSfc, loadSfc } = await import('./support/render-sfc.mjs')
const store = await import(pathToFileURL(join(ROOT, 'src', 'site', 'components', 'e', 'actingFor.js')).href)

test('site: the switcher names yourself and each mandate with the choice selected, and is absent without a mandate', async () => {
	const html = await renderSfc('src/site/components/e/ActingForSwitcher.vue', { t, mandates: MANDATES, value: 'mandate-1' })
	assert.match(html, /<label for="pq-acting-for"[^>]*>Acting for<\/label>/)
	assert.match(html, /<select id="pq-acting-for"/)
	assert.match(html, /<option value="self">Yourself<\/option>/)
	assert.match(html, /<option value="mandate-1" selected>Bakkerij Jansen BV<\/option>/)
	assert.match(html, /<option value="mandate-2">Mijn vader<\/option>/)
	assert.equal(await renderSfc('src/site/components/e/ActingForSwitcher.vue', { t, mandates: [] }), '<!---->')
})

test('site: the store learns the mandates from "My cases", keeps the choice for the session, and a refusal forgets nothing', () => {
	const kept = storage()
	store.learnMandates({ ok: true, mandates: MANDATES })
	assert.deepEqual(store.actingFor.mandates, MANDATES)
	store.chooseActingFor('mandate-2', kept)
	assert.equal(store.actingFor.id, 'mandate-2')
	assert.equal(myCases.readActingFor(kept), 'mandate-2')
	store.learnMandates({ ok: false, error: 'group_too_large' })
	assert.deepEqual(store.actingFor.mandates, MANDATES, 'a refusal never forgets the mandates')
	assert.equal(store.actingFor.id, 'mandate-2')
	store.learnMandates({ ok: true, mandates: [MANDATES[0]] })
	assert.equal(store.actingFor.id, 'self', 'a mandate no longer held falls back to yourself')
})

test('site: the header switcher with only `t` follows the store', async () => {
	store.learnMandates({ ok: true, mandates: MANDATES })
	store.chooseActingFor('mandate-1', storage())
	const html = await renderSfc('src/site/components/e/ActingForSwitcher.vue', { t })
	assert.match(html, /<option value="mandate-1" selected>Bakkerij Jansen BV<\/option>/)
	const switcher = await loadSfc('src/site/components/e/ActingForSwitcher.vue')
	const emitted = []
	switcher.methods.choose.call({ $emit: (e, id) => emitted.push([e, id]) }, 'mandate-2')
	assert.equal(store.actingFor.id, 'mandate-2')
	assert.deepEqual(emitted, [['change', 'mandate-2']])
	store.chooseActingFor('self', storage())
})

test('site: "My cases" is read under the mandate in effect and tells the store what it holds', async () => {
	const page = await loadSfc('src/site/pages/e/MyCasesPage.vue')
	const asked = []
	const emitted = []
	const vm = {
		request: 0,
		data: null,
		actingUnder: 'mandate-1',
		api: { fetchMyCases: async (id) => { asked.push(id); return { ok: true, cases: [], mandates: MANDATES } } },
		$emit: (e) => emitted.push(e),
	}
	await page.methods.load.call(vm)
	assert.deepEqual(asked, ['mandate-1'])
	assert.deepEqual(emitted, ['loaded'])
	assert.deepEqual(store.actingFor.mandates, MANDATES)
	assert.equal(page.computed.actingUnder.call({ mandateId: '' }), store.actingFor.id)
	assert.equal(page.computed.actingUnder.call({ mandateId: 'mandate-2' }), 'mandate-2')
})

test('site: a mandated case carries its label, a group too large is refused, and the case screen reads under the mandate', async () => {
	const html = await renderSfc('src/site/pages/e/MyCasesPage.vue', {
		api: {},
		t,
		initialData: { ok: true, cases: [{ id: 'z-9', title: 'Terrasvergunning', _source: { appId: 'dossiq', label: 'Zaken', collection: 'mijnZaken' }, _mandate: { id: 'mandate-1', label: 'Bakkerij Jansen BV' } }] },
	})
	assert.match(html, /Terrasvergunning[\s\S]*data-testid="my-cases-mandate"[^>]*>Bakkerij Jansen BV</)
	const refused = await renderSfc('src/site/pages/e/MyCasesPage.vue', { api: {}, t, initialData: { ok: false, status: 409, error: 'group_too_large', cases: [] } })
	assert.match(refused, /role="alert"[^>]*>This organisation has too many cases to list here\. Choose a narrower mandate\.</)
	assert.doesNotMatch(refused, /my-cases-list/)

	const screen = await loadSfc('src/site/components/e/CitizenCase.vue')
	assert.equal(screen.computed.mandateId.call({ row: { id: 'z-9', _mandate: { id: 'mandate-1' } } }), 'mandate-1')
	const read = []
	const vm = {
		caseId: 'z-9',
		mandateId: 'mandate-1',
		collection: { register: 'dossiq', schema: 'case' },
		api: { fetchCitizenCase: async (c, id, mandate) => { read.push([id, mandate]); return { case: {} } } },
	}
	await screen.methods.load.call(vm)
	assert.deepEqual(read, [['z-9', 'mandate-1']])
})

test('site: a case opened under a mandate uses the row the list handed over', () => {
	const loader = readFileSync(join(ROOT, 'src', 'site', 'pages', 'collections', 'collectionLoader.js'), 'utf8')
	assert.match(loader, /rowFor\(loaded\.objects, target\.id\) \|\| target\.row \|\| null/)
})
