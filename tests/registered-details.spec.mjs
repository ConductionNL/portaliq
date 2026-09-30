#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// registered-details.spec.mjs: the "My details" section
// (identity-registered-details T05, T06, T07). A resident sees the BRP
// record, a business user the KvK record, and each empty state says why.
// The request links render only when the portal bound a form.
//
// Usage:
//   node --test tests/registered-details.spec.mjs

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
	const out = join(
		OUT_DIR,
		relative.replace(/[\\/]/g, '_').replace(/\.jsx?$/, '.mjs'),
	)
	compileLoading(OUT_DIR)
	writeFileSync(out, compiled.code.replace("'./Loading.jsx'", `'${LOADING_MODULE}'`))
	return import(pathToFileURL(out).href)
}

const { createPortalApi } = await load('lib/portalApi.js')
const {
	default: RegisteredDetailsPage,
	reasonText,
} = await load('components/RegisteredDetailsPage.jsx')

const BASE = '/apps/portaliq/portal/api'
const nl = JSON.parse(readFileSync(join(ROOT, 'src', 'portal', 'i18n', 'nl.json'), 'utf8'))
const en = JSON.parse(readFileSync(join(ROOT, 'src', 'portal', 'i18n', 'en.json'), 'utf8'))

/**
 * An identity translator with {name} substitution.
 *
 * @param {string} key The English source key.
 * @param {object} vars The substitutions.
 * @return {string} The text.
 */
function t(key, vars = {}) {
	return key.replace(/\{(\w+)\}/g, (_, name) => String(vars[name] ?? ''))
}

/**
 * Stub the browser: a stored bearer and a recording fetch.
 *
 * @param {Array<{status: number, body: object}>} answers The answers, in order.
 * @return {Array<object>} The recorded calls.
 */
function stubBrowser(answers) {
	const calls = []
	const queue = [...answers]
	globalThis.window = {
		localStorage: { getItem: () => 'token-1', setItem() {}, removeItem() {} },
	}
	globalThis.fetch = async (url, init = {}) => {
		calls.push({ url: String(url), init })
		const next = queue.shift() || { status: 200, body: {} }
		return {
			ok: next.status >= 200 && next.status < 300,
			status: next.status,
			json: async () => next.body,
		}
	}
	return calls
}

const PERSON = {
	available: true,
	kind: 'person',
	person: {
		name: 'Jan de Vries',
		birthDate: '1980-04-12',
		address: { street: 'Dorpsstraat', number: '12A', postcode: '1234AB', city: 'Utrecht' },
		residentsAtAddress: null,
	},
	links: { correction: '/apps/portaliq/site?portal=p&route=%2Fcorrectie', addressInvestigation: null },
}

const COMPANY = {
	available: true,
	kind: 'company',
	company: {
		tradeName: 'Bakkerij de Korenschoof',
		kvkNumber: '12345678',
		legalForm: 'Besloten Vennootschap',
		branches: [
			{ number: '000012345678', name: 'Bakkerij de Korenschoof', address: 'Marktplein 1, 3511AB Utrecht', main: true },
			{ number: '000087654321', name: 'Korenschoof Zuid', address: 'Laan 40, 3521CD Utrecht', main: false },
		],
	},
	links: { correction: null, addressInvestigation: null },
}

test('the section is read with the bearer and names no identifier, and a failure reads as unavailable', async () => {
	const calls = stubBrowser([
		{ status: 200, body: PERSON },
		{ status: 401, body: { authenticated: false } },
	])
	const api = createPortalApi({ apiBase: BASE })

	assert.deepEqual(await api.fetchRegisteredDetails(), PERSON)
	assert.equal(calls[0].url, `${BASE}/identity/registered-details`)
	assert.equal(calls[0].init.headers.Authorization, 'Bearer token-1')
	assert.deepEqual(await api.fetchRegisteredDetails(), { available: false, reason: 'source_unavailable' })
})

test('a resident sees name, date of birth and address, and the count says it is not available', () => {
	const html = renderToStaticMarkup(createElement(RegisteredDetailsPage, { api: {}, t, locale: 'en', initialDetails: PERSON }))
	assert.match(html, /Jan de Vries/)
	assert.match(html, /April 12, 1980|12 April 1980/)
	assert.match(html, /Dorpsstraat 12A/)
	assert.match(html, /1234AB Utrecht/)
	assert.match(html, /The number of residents at this address is not available\./)
	assert.doesNotMatch(html, /999993653/)
})

test('a count of residents is shown as a number and no names', () => {
	const withCount = { ...PERSON, person: { ...PERSON.person, residentsAtAddress: 3 } }
	const html = renderToStaticMarkup(createElement(RegisteredDetailsPage, { api: {}, t, initialDetails: withCount }))
	assert.match(html, /3 people are registered at this address\./)
})

test('a bound correction form is a link, and an unbound one is not there', () => {
	const html = renderToStaticMarkup(createElement(RegisteredDetailsPage, { api: {}, t, initialDetails: PERSON }))
	assert.match(html, /href="\/apps\/portaliq\/site\?portal=p&amp;route=%2Fcorrectie"/)
	assert.match(html, /Report an error in these details/)
	assert.doesNotMatch(html, /Something wrong at this address\?/)

	const both = { ...PERSON, links: { correction: null, addressInvestigation: '/adres' } }
	const html2 = renderToStaticMarkup(createElement(RegisteredDetailsPage, { api: {}, t, initialDetails: both }))
	assert.doesNotMatch(html2, /Report an error in these details/)
	assert.match(html2, /Something wrong at this address\?/)
})

test('a business user sees the company with every branch', () => {
	const html = renderToStaticMarkup(createElement(RegisteredDetailsPage, { api: {}, t, initialDetails: COMPANY }))
	assert.match(html, /Bakkerij de Korenschoof/)
	assert.match(html, /12345678/)
	assert.match(html, /Besloten Vennootschap/)
	assert.match(html, /Korenschoof Zuid/)
	assert.match(html, /Laan 40, 3521CD Utrecht/)
	assert.match(html, /Main branch/)
	assert.doesNotMatch(html, /Report an error in these details/)
})

test('each empty state says why, and never shows an empty record', () => {
	assert.equal(reasonText('source_unavailable'), 'Your registered details cannot be shown right now.')
	assert.equal(reasonText('no_registration_identifier'), 'The portal cannot show registered details for this way of signing in.')
	assert.equal(reasonText('not_found'), 'No registered details were found for you.')
	assert.equal(reasonText('something-new'), 'Your registered details cannot be shown right now.')
	const html = renderToStaticMarkup(createElement(RegisteredDetailsPage, {
		api: {},
		t,
		initialDetails: { available: false, reason: 'source_unavailable' },
	}))
	assert.match(html, /Your registered details cannot be shown right now\./)
	assert.doesNotMatch(html, /Date of birth/)
})

test('every string of the section is in both locales', () => {
	const source = readFileSync(join(ROOT, 'src', 'portal', 'components', 'RegisteredDetailsPage.jsx'), 'utf8')
	const keys = [...source.matchAll(/(?:\bt\(|return )'([^']+)'/g)].map((m) => m[1])
	assert.ok(keys.length > 10, `found ${keys.length} keys`)
	for (const key of keys) {
		assert.ok(key in nl, `nl.json lacks "${key}"`)
		assert.ok(key in en, `en.json lacks "${key}"`)
	}
	assert.ok('My details' in nl)
	for (const text of Object.values(nl)) {
		assert.doesNotMatch(text, /—/, 'no em-dashes')
	}
})

test('the portal shell offers "My details" once signed in', () => {
	const app = readFileSync(join(ROOT, 'src', 'portal', 'App.jsx'), 'utf8')
	assert.match(app, /import RegisteredDetailsPage from '@portal\/components\/RegisteredDetailsPage\.jsx'/)
	assert.match(app, /label: t\('My details'\)/)
	assert.match(app, /active\.special === 'details'/)
})
