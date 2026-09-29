#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// case-withdraw-screen.spec.mjs: a resident withdraws their own request from
// the portal case screen (case-actions-withdraw-screen). The API posts only
// the reason, with the bearer, to the existing withdraw route; the screen
// shows exactly what the server declares (nothing, a button, or the closed
// reason); the confirmation step sends nothing until the resident confirms;
// a withdrawn request shows when and why, and no control to undo it.
//
// Usage:
//   node --test tests/case-withdraw-screen.spec.mjs

import babel from '@babel/core'
import assert from 'node:assert/strict'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { compileLoading } from './support/compile-loading.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const OUT_DIR = join(ROOT, 'node_modules', '.cache', 'portaliq-tests-withdraw')

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
	const out = join(OUT_DIR, flat(relative))
	writeFileSync(out, code)
	return import(pathToFileURL(out).href)
}

compileLoading(OUT_DIR)
const { createPortalApi } = await load('lib/portalApi.js')
const { withdrawalView, caseFieldNames } = await load('lib/withdrawal.js')
const { default: WithdrawCaseConfirm } = await load('components/WithdrawCaseConfirm.jsx')
const { createElement } = await import('react')
const { renderToStaticMarkup } = await import('react-dom/server')

const t = (key, vars = {}) => key.replace(/\{(\w+)\}/g, (_, name) => String(vars[name] ?? ''))
const COLLECTION = { id: 'mijnAanvragen', register: 'dossiq', schema: 'aanvraag' }

test('withdrawCitizenCase posts only the reason, with the bearer, to the withdraw route', async () => {
	const calls = []
	globalThis.window = { localStorage: { getItem: () => 'token-1', setItem() {}, removeItem() {} } }
	globalThis.fetch = async (url, init) => {
		calls.push({ url, init })
		return { ok: true, status: 200, json: async () => ({ case: { id: 'c 1', status: 'ingetrokken' }, withdrawal: { declared: true, open: false } }) }
	}
	const api = createPortalApi({ apiBase: '/apps/portaliq/portal/api' })

	const result = await api.withdrawCitizenCase(COLLECTION, 'c 1', 'Ik ben toch niet verhuisd.')

	assert.equal(calls.length, 1)
	assert.equal(calls[0].url, '/apps/portaliq/portal/api/citizen/cases/dossiq/aanvraag/c%201/withdraw')
	assert.equal(calls[0].init.method, 'POST')
	assert.equal(calls[0].init.headers.Authorization, 'Bearer token-1')
	assert.deepEqual(JSON.parse(calls[0].init.body), { reason: 'Ik ben toch niet verhuisd.' })
	assert.equal(result.ok, true)
	assert.equal(result.case.status, 'ingetrokken')
	assert.deepEqual(result.withdrawal, { declared: true, open: false })
})

test('a refusal comes back with the server sentence', async () => {
	globalThis.fetch = async () => ({ ok: false, status: 409, json: async () => ({ error: 'withdrawal-not-open', message: 'Deze aanvraag is al besloten.' }) })
	const api = createPortalApi({ apiBase: '/apps/portaliq/portal/api' })

	const result = await api.withdrawCitizenCase(COLLECTION, 'c1', '')

	assert.deepEqual(result, { ok: false, status: 409, message: 'Deze aanvraag is al besloten.', error: 'withdrawal-not-open' })
})

test('the screen shows exactly what the server declares', () => {
	assert.deepEqual(withdrawalView({ declared: false, open: false, reason: 'This request cannot be withdrawn from the portal.' }, {}), { kind: 'none' })
	assert.deepEqual(withdrawalView(undefined, {}), { kind: 'none' })
	assert.deepEqual(withdrawalView({ declared: true, open: true, confirmText: 'Wij stoppen de behandeling.' }, {}), { kind: 'button', confirmText: 'Wij stoppen de behandeling.' })
	assert.deepEqual(withdrawalView({ declared: true, open: false, reason: 'Deze aanvraag is al besloten.' }, {}), { kind: 'closed', reason: 'Deze aanvraag is al besloten.' })
	assert.deepEqual(
		withdrawalView({ declared: true, open: false, reason: 'This request has already been withdrawn.' }, { withdrawnAt: '2026-09-29T10:00:00+00:00', withdrawalReason: 'Ik ben toch niet verhuisd.' }),
		{ kind: 'withdrawn', withdrawnAt: '2026-09-29T10:00:00+00:00', reason: 'Ik ben toch niet verhuisd.' },
	)
})

test('the withdrawal fields are not listed as ordinary answers', () => {
	assert.deepEqual(
		caseFieldNames({ '@self': {}, _files: [], naam: 'Jansen', withdrawnAt: 'x', withdrawalReason: 'y', adres: 'Straat 1' }),
		['naam', 'adres'],
	)
})

test('the confirmation says what withdrawing means, asks an optional reason, and offers both ways out', () => {
	const html = renderToStaticMarkup(createElement(WithdrawCaseConfirm, { t, busy: false, onConfirm() {}, onCancel() {} }))
	assert.match(html, /<h4[^>]*tabindex="-1"[^>]*>Withdraw this request\?<\/h4>/)
	assert.match(html, /If you withdraw, we stop handling your request\. You cannot undo this\./)
	assert.match(html, /<label for="portaliq-withdraw-reason">Why are you withdrawing\? \(optional\)<\/label>/)
	assert.match(html, /<textarea id="portaliq-withdraw-reason"/)
	assert.match(html, />Withdraw request<\/button>/)
	assert.match(html, />Keep my request<\/button>/)

	const own = renderToStaticMarkup(createElement(WithdrawCaseConfirm, { t, confirmText: 'Wij stoppen de behandeling.', busy: true, onConfirm() {}, onCancel() {} }))
	assert.match(own, /Wij stoppen de behandeling\./)
	assert.doesNotMatch(own, /You cannot undo this/)
	assert.match(own, /<button[^>]*disabled=""[^>]*>Withdraw request<\/button>/)
})

test('the case screen uses them, and both locales carry the strings', () => {
	const screen = readFileSync(join(ROOT, 'src', 'portal', 'components', 'CitizenCase.jsx'), 'utf8')
	assert.match(screen, /withdrawalView\(state\.data\.withdrawal, caseRow\)/)
	assert.match(screen, /caseFieldNames\(caseRow\)/)
	assert.match(screen, /<WithdrawCaseConfirm/)
	assert.match(screen, /api\.withdrawCitizenCase\(/)
	assert.doesNotMatch(screen, /undo|reopen/i)
	const nl = {
		'Withdraw this request': 'Deze aanvraag intrekken',
		'Withdraw this request?': 'Deze aanvraag intrekken?',
		'If you withdraw, we stop handling your request. You cannot undo this.': 'Als u intrekt, stoppen wij met de behandeling. U kunt dit niet terugdraaien.',
		'Why are you withdrawing? (optional)': 'Waarom trekt u de aanvraag in? (niet verplicht)',
		'Withdraw request': 'Aanvraag intrekken',
		'Keep my request': 'Aanvraag houden',
		'Your request has been withdrawn.': 'Uw aanvraag is ingetrokken.',
		'Withdrawn on {date}.': 'Ingetrokken op {date}.',
		'Your reason: {reason}': 'Uw reden: {reason}',
	}
	for (const locale of ['en', 'nl']) {
		const bundle = JSON.parse(readFileSync(join(ROOT, 'src', 'portal', 'i18n', `${locale}.json`), 'utf8'))
		for (const [key, dutch] of Object.entries(nl)) {
			assert.equal(bundle[key], locale === 'nl' ? dutch : key, `${locale}: ${key}`)
		}
	}
})
