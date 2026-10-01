#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// account-page.spec.mjs: the "My account" page (identity-profile-page
// T07-T10). Name, e-mail addresses, phone numbers, the contact channel and
// removing the account; the confirmation link is consumed once, and the
// prompt for a missing address lasts until it is dismissed.
//
// Usage:
//   node --test tests/account-page.spec.mjs

import assert from 'node:assert/strict'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { buildNav, defaultNavKey } from '../src/shared/portalNav.js'
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
	writeFileSync(out, compiled.code
		.replace("'./Loading.jsx'", `'${LOADING_MODULE}'`)
		.replace("'../lib/account.js'", `'${pathToFileURL(join(OUT_DIR, 'lib_account.mjs')).href}'`))
	return import(pathToFileURL(out).href)
}

const { createPortalApi } = await load('../shared/portalApi.js')
const { consumeConfirmEmail, refusalText, promptDismissed, dismissPrompt } = await load('lib/account.js')
const { default: AccountPage, ContactPrompt } = await load('components/AccountPage.jsx')

const BASE = '/apps/portaliq/portal/api'
const nl = JSON.parse(readFileSync(join(ROOT, 'src', 'shared', 'i18n', 'nl.json'), 'utf8'))
const en = JSON.parse(readFileSync(join(ROOT, 'src', 'shared', 'i18n', 'en.json'), 'utf8'))

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

const DETAILS = {
	displayName: 'Ans de Vries',
	email: 'a@example.nl',
	contactAddresses: [
		{ kind: 'email', value: 'a@example.nl', confirmed: true, preferred: true },
		{ kind: 'email', value: 'c@example.nl', confirmed: false, preferred: false },
		{ kind: 'phone', value: '+31612345678', confirmed: false, preferred: true },
	],
	pendingEmail: 'c***@example.nl',
	contactChannel: 'post',
}

test('the page shows the name, both kinds of address with the preferred ones marked, and the channel', () => {
	const html = renderToStaticMarkup(createElement(AccountPage, { api: {}, t, initialDetails: DETAILS }))
	assert.match(html, /value="Ans de Vries"/)
	assert.match(html, /a@example\.nl/)
	assert.match(html, /c@example\.nl/)
	assert.match(html, /Waiting for confirmation/)
	assert.match(html, /\+31612345678/)
	assert.equal((html.match(/Preferred/g) || []).length, 2, 'one preferred e-mail and one preferred phone')
	assert.match(html, /checked="" value="post"/)
	assert.match(html, /Remove my account/)
	assert.doesNotMatch(html, /Your cases stay with the organisation\./, 'the removal text shows in the confirmation step only')
})

test('an unconfirmed address offers a fresh link and no preferred button', () => {
	const html = renderToStaticMarkup(createElement(AccountPage, { api: {}, t, initialDetails: DETAILS }))
	assert.match(html, /Send the link again/)
	assert.equal((html.match(/Make preferred/g) || []).length, 0, 'the only confirmed e-mail and the only phone are already preferred')
})

test('the removal step says the cases stay', () => {
	const html = renderToStaticMarkup(createElement(AccountPage, { api: {}, t, initialDetails: DETAILS, initialConfirmRemove: true }))
	assert.match(html, /Your portal account is removed\. Your cases stay with the organisation\./)
	assert.match(html, /Yes, remove my account/)
})

test('the api calls the own-account routes with the bearer and keeps the refusal', async () => {
	const calls = stubBrowser([
		{ status: 200, body: DETAILS },
		{ status: 200, body: { added: true, value: 'b@example.nl', confirmationPending: true, confirmationSent: true } },
		{ status: 400, body: { error: 'confirm_first' } },
		{ status: 200, body: { removed: true } },
		{ status: 200, body: { channel: 'phone' } },
		{ status: 200, body: { confirmed: true } },
		{ status: 200, body: { removed: true } },
		{ status: 200, body: { updated: true } },
	])
	const api = createPortalApi({ apiBase: BASE })

	assert.deepEqual(await api.getDetails(), DETAILS)
	const added = await api.addContactAddress('email', 'b@example.nl')
	assert.equal(added.ok, true)
	assert.equal(added.data.confirmationPending, true)
	assert.deepEqual(await api.preferContactAddress('email', 'c@example.nl'), { ok: false, status: 400, error: 'confirm_first', data: { error: 'confirm_first' } })
	await api.removeContactAddress('phone', '+31612345678')
	await api.setContactChannel('phone')
	await api.confirmEmail('secret-1')
	await api.removeOwnAccount()
	await api.setDisplayName('Ans')

	assert.equal(calls[0].url, `${BASE}/identity/details`)
	assert.deepEqual(calls.slice(1).map((c) => [c.init.method, c.url.replace(BASE, '')]), [
		['POST', '/identity/addresses'],
		['POST', '/identity/addresses/preferred'],
		['POST', '/identity/addresses/remove'],
		['PUT', '/identity/contact-channel'],
		['POST', '/identity/email/confirm'],
		['POST', '/identity/remove'],
		['PATCH', '/identity/details'],
	])
	assert.equal(calls[1].init.headers.Authorization, 'Bearer token-1')
	assert.deepEqual(JSON.parse(calls[1].init.body), { kind: 'email', value: 'b@example.nl' })
	assert.deepEqual(JSON.parse(calls[5].init.body), { token: 'secret-1' })
	assert.deepEqual(JSON.parse(calls[7].init.body), { displayName: 'Ans' })
})

test('the confirmation link is read once and stripped from the address bar', () => {
	const replaced = []
	const location = { hash: '#confirm-email=abc123', href: 'https://gemeente.example/apps/portaliq/portal#confirm-email=abc123' }
	const history = { replaceState: (_s, _t, url) => { replaced.push(url); location.hash = '' } }

	assert.equal(consumeConfirmEmail(location, history), 'abc123')
	assert.deepEqual(replaced, ['https://gemeente.example/apps/portaliq/portal'])
	assert.equal(consumeConfirmEmail(location, history), '')
	assert.equal(consumeConfirmEmail({ hash: '#token=x', href: 'x' }, history), '')
})

test('each refusal has its own sentence', () => {
	assert.equal(refusalText('confirm_first'), 'Confirm this address first.')
	assert.equal(refusalText('choose_another_preferred'), 'Choose another preferred address first.')
	assert.equal(refusalText('too_many_pending'), 'Five addresses are waiting for confirmation. Confirm or remove one first.')
	assert.equal(refusalText('exists'), 'This address is already on your account.')
	assert.equal(refusalText('invalid'), 'Check the address and try again.')
	assert.equal(refusalText('link_not_valid'), 'This link is no longer valid.')
	assert.equal(refusalText('whatever'), 'That did not work. Try again later.')
})

test('the prompt for an address links to My account and stays away once dismissed this session', () => {
	const html = renderToStaticMarkup(createElement(ContactPrompt, { t, onOpen() {}, onDismiss() {} }))
	assert.match(html, /Add an e-mail address so we can tell you when something changes\./)
	assert.match(html, /Go to My account/)
	assert.match(html, /role="status"/)

	const store = new Map()
	const session = { getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, v) }
	assert.equal(promptDismissed(session), false)
	dismissPrompt(session)
	assert.equal(promptDismissed(session), true)
	assert.equal(promptDismissed(null), false)
})

test('every string of the page is in both locales, without em-dashes', () => {
	const sources = ['components/AccountPage.jsx', 'lib/account.js']
		.map((f) => readFileSync(join(ROOT, 'src', 'portal', f), 'utf8'))
		.join('\n')
	const keys = [...sources.matchAll(/(?:\bt\(|return |: |\|\| )'([A-Z][^']+)'/g)].map((m) => m[1])
	assert.ok(keys.length > 20, `found ${keys.length} keys`)
	for (const key of keys) {
		assert.ok(key in nl, `nl.json lacks "${key}"`)
		assert.ok(key in en, `en.json lacks "${key}"`)
		assert.doesNotMatch(nl[key], /—/, 'no em-dashes')
	}
	assert.ok('My account' in nl)
})

test('the portal shell offers "My account", consumes the link and shows the prompt', () => {
	const app = readFileSync(join(ROOT, 'src', 'portal', 'App.jsx'), 'utf8')
	assert.match(app, /import AccountPage, \{ ContactPrompt \} from '@portal\/components\/AccountPage\.jsx'/)
	// The shared navigation offers it once signed in, and never as the first page.
	const nav = buildNav([{ app: 'a', pages: [{ id: 'p' }] }], (key) => key, { access: true })
	assert.ok(nav.some((entry) => entry.special === 'account' && entry.label === 'My account'))
	assert.equal(defaultNavKey(nav), 'a:p')
	assert.match(app, /active\.special === 'account'/)
	assert.match(app, /consumeConfirmEmail\(window\.location, window\.history\)/)
	assert.match(app, /state\.session\.contactPrompt === true/)
})
