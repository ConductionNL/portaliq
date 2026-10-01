#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// ways-in-screens.spec.mjs: the ways into the portal without an account
// (identity-ways-in-screens T02-T07). "Create an account" solves the
// portal's challenge in the browser the way the server checks it; a mailed
// link's fragment is consumed once; a reference link opens one case read
// only; an invitation is accepted; and the doors render only when the runtime
// config's `waysIn` opens them.
//
// Usage:
//   node --test tests/ways-in-screens.spec.mjs

import assert from 'node:assert/strict'
import { createHash, webcrypto } from 'node:crypto'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'

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
	const out = join(OUT_DIR, relative.replace(/[\\/]/g, '_').replace(/\.jsx?$/, '.mjs'))
	writeFileSync(out, compiled.code
		.replace("'../lib/waysIn.js'", `'${pathToFileURL(join(OUT_DIR, 'lib_waysIn.mjs')).href}'`))
	return import(pathToFileURL(out).href)
}

const { createPortalApi } = await load('lib/portalApi.js')
const { consumeWayInFragment, leadingZeroBits, solveChallenge, wayInRefusalText, registrationOutcomeText } = await load('lib/waysIn.js')
const { CreateAccountForm, ReferenceLinkForm, ReferenceCaseView, WayInLink } = await load('components/WaysIn.jsx')

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
 * Stub the browser: no stored bearer and a recording fetch.
 *
 * @param {Array<{status: number, body: object}>} answers The answers, in order.
 * @return {Array<object>} The recorded calls.
 */
function stubBrowser(answers) {
	const calls = []
	const queue = [...answers]
	globalThis.window = { localStorage: { getItem: () => null, setItem() {}, removeItem() {} } }
	globalThis.fetch = async (url, init = {}) => {
		calls.push({ url: String(url), init })
		const next = queue.shift() || { status: 200, body: {} }
		return { ok: next.status >= 200 && next.status < 300, status: next.status, json: async () => next.body }
	}
	return calls
}

test('a mailed link is read once from the fragment and stripped from the address bar', () => {
	for (const kind of ['activate', 'invitation', 'reference']) {
		const replaced = []
		const location = { hash: `#${kind}=abc%20123`, href: `https://portal.example/apps/portaliq/portal?portal=gemeente-x#${kind}=abc%20123` }
		const history = { replaceState: (_, __, url) => replaced.push(url) }
		assert.deepEqual(consumeWayInFragment(location, history), { kind, token: 'abc 123' })
		assert.deepEqual(replaced, ['https://portal.example/apps/portaliq/portal?portal=gemeente-x'])
	}
	assert.equal(consumeWayInFragment({ hash: '#token=bearer', href: 'x' }, {}), null, 'the sign-in fragment is not ours')
	assert.equal(consumeWayInFragment({ hash: '#confirm-email=abc', href: 'x' }, {}), null)
	assert.equal(consumeWayInFragment({ hash: '', href: 'x' }, {}), null)
})

test('the browser solves the challenge the way PortalChallengeService::solves() checks it', async () => {
	assert.equal(leadingZeroBits(Uint8Array.from([0, 0x0f, 0xff])), 12)
	assert.equal(leadingZeroBits(Uint8Array.from([0x80])), 0)
	const solution = await solveChallenge('nonce-1', 10, webcrypto.subtle)
	assert.notEqual(solution, '')
	// The server's check, in node: sha256(nonce:solution) carries the bits.
	const digest = createHash('sha256').update(`nonce-1:${solution}`).digest()
	assert.ok(leadingZeroBits(digest) >= 10)
	assert.equal(await solveChallenge('nonce-1', 10, null), '', 'no digest, no solution')
})

test('registration asks for the portal\'s challenge and sends the solution, the honeypot and the portal', async () => {
	const calls = stubBrowser([
		{ status: 200, body: { nonce: 'n', expiresAt: 99, signature: 's', difficulty: 1, honeypotField: 'website' } },
		{ status: 200, body: { status: 'pending', awaiting: 'activation' } },
	])
	const api = createPortalApi({ apiBase: BASE, organisationSlug: 'gemeente-x' })
	const challenge = await api.challenge('registration')
	assert.equal(calls[0].url, `${BASE}/identity/challenge?surface=registration&portal=gemeente-x`)
	const answer = await api.registerAccount({ email: 'ans@example.org', displayName: 'Ans', challenge, solution: '7', honeypot: { field: challenge.honeypotField, value: '' } })
	assert.equal(calls[1].url, `${BASE}/identity/register`)
	assert.equal(calls[1].init.method, 'POST')
	assert.deepEqual(JSON.parse(calls[1].init.body), {
		portal: 'gemeente-x',
		email: 'ans@example.org',
		displayName: 'Ans',
		nonce: 'n',
		expiresAt: 99,
		signature: 's',
		solution: '7',
		website: '',
	})
	assert.equal(answer.data.awaiting, 'activation')
})

test('the other ways in call their own routes and keep the refusal', async () => {
	const calls = stubBrowser([
		{ status: 403, body: { error: 'activation_not_valid' } },
		{ status: 200, body: { sent: true } },
		{ status: 200, body: { bearer: 'ref-bearer', caseReference: 'Z-1' } },
		{ status: 200, body: { case: { status: 'In behandeling' }, caseReference: 'Z-1', readOnly: true } },
		{ status: 403, body: { error: 'invitation_not_valid' } },
	])
	const api = createPortalApi({ apiBase: BASE, organisationSlug: 'gemeente-x' })
	assert.equal((await api.activateAccount('a1')).error, 'activation_not_valid')
	await api.requestReferenceLink({ register: 'dossiq', schema: 'caseType', caseType: 't1', caseReference: 'Z-1', email: 'anna@example.nl' })
	assert.equal((await api.redeemReferenceLink('r1')).data.bearer, 'ref-bearer')
	const read = await api.referenceCase('ref-bearer')
	assert.equal((await api.acceptInvitation('i1')).error, 'invitation_not_valid')

	assert.deepEqual(calls.map((c) => c.url), [
		`${BASE}/identity/activate`,
		`${BASE}/identity/reference-link`,
		`${BASE}/identity/reference-link/redeem`,
		`${BASE}/identity/reference-case`,
		`${BASE}/identity/invitation/accept`,
	])
	assert.deepEqual(JSON.parse(calls[1].init.body), { portal: 'gemeente-x', register: 'dossiq', schema: 'caseType', caseType: 't1', caseReference: 'Z-1', email: 'anna@example.nl' })
	assert.equal(calls[3].init.headers.Authorization, 'Bearer ref-bearer', 'the reference session is its own bearer')
	assert.equal(read.case.status, 'In behandeling')
})

test('the outcomes read as the spec says', () => {
	assert.equal(wayInRefusalText('invitation_not_valid'), 'This invitation is no longer valid.')
	assert.equal(wayInRefusalText('link_not_valid'), 'This link is no longer valid.')
	assert.equal(wayInRefusalText('activation_not_valid'), 'This link is no longer valid.')
	assert.equal(wayInRefusalText('anything'), 'That did not work. Try again later.')
	assert.equal(registrationOutcomeText('approval'), 'We will let you know when your account is ready.')
	assert.match(registrationOutcomeText('activation'), /Follow the link in it to activate your account\./)
})

test('"Create an account" has a labelled form and a trap field out of sight', () => {
	const html = renderToStaticMarkup(createElement(CreateAccountForm, { api: {}, t }))
	assert.match(html, /<h2[^>]*>Create an account<\/h2>/)
	assert.match(html, /<label for="portaliq-register-email">E-mail address<\/label>/)
	assert.match(html, /class="portaliq-way-in__trap" aria-hidden="true"/)
	assert.match(html, /tabindex="-1"/)
})

test('the reference form lists the case types only when there is a choice', () => {
	const one = [{ register: 'dossiq', schema: 'caseType', caseType: 't1', label: 'Parkeervergunning' }]
	const two = [...one, { register: 'dossiq', schema: 'caseType', caseType: 't2', label: 'Bouwvergunning' }]
	assert.doesNotMatch(renderToStaticMarkup(createElement(ReferenceLinkForm, { api: {}, t, caseTypes: one })), /<select/)
	const html = renderToStaticMarkup(createElement(ReferenceLinkForm, { api: {}, t, caseTypes: two }))
	assert.match(html, /<select/)
	assert.match(html, /Parkeervergunning/)
	assert.match(html, /Bouwvergunning/)
	assert.match(html, /Follow a case with its case number/)
})

test('a case opened with a link shows its fields and offers nothing to change', () => {
	const html = renderToStaticMarkup(createElement(ReferenceCaseView, {
		t,
		caseReference: 'Z-2026-0042',
		record: { status: 'In behandeling', history: [{ date: '2026-09-01', text: 'Ontvangen' }], '@self': { id: 'x' } },
	}))
	assert.match(html, /Case Z-2026-0042/)
	assert.match(html, /In behandeling/)
	assert.match(html, /2026-09-01 · Ontvangen/)
	assert.match(html, /You cannot change anything here\./)
	assert.doesNotMatch(html, /<button|<form|<input/, 'read only')
	assert.doesNotMatch(html, /@self/)
})

test('an invitation shows the portal and an accept button', () => {
	const html = renderToStaticMarkup(createElement(WayInLink, {
		api: {},
		t,
		link: { kind: 'invitation', token: 'i1' },
		emailSignIn: 'E-mail',
		portalName: 'Gemeente X',
	}))
	assert.match(html, /You are invited to Gemeente X/)
	assert.match(html, /<button type="button">Accept<\/button>/)
})

test('every string of the ways in is in both locales, without em-dashes', () => {
	const sources = ['components/WaysIn.jsx', 'lib/waysIn.js']
		.map((f) => readFileSync(join(ROOT, 'src', 'portal', f), 'utf8'))
		.join('\n')
	const keys = [...sources.matchAll(/(?:\bt\(|return |: |\|\| )'([A-Z][^']+)'/g)].map((m) => m[1])
	assert.ok(keys.length > 20, `found ${keys.length} keys`)
	for (const key of keys) {
		assert.ok(key in nl, `nl.json lacks "${key}"`)
		assert.ok(key in en, `en.json lacks "${key}"`)
		assert.doesNotMatch(nl[key], /—/, 'no em-dashes')
	}
})

test('the sign-in screen consumes the link and opens only the doors waysIn opens', () => {
	const app = readFileSync(join(ROOT, 'src', 'portal', 'App.jsx'), 'utf8')
	assert.match(app, /import \{ CreateAccountForm, ReferenceLinkForm, WayInLink \} from '@portal\/components\/WaysIn\.jsx'/)
	assert.match(app, /consumeWayInFragment\(window\.location, window\.history\)/)
	assert.match(app, /config\.waysIn\?\.register === true && <CreateAccountForm/)
	assert.match(app, /config\.waysIn\?\.reference === true && \(/)
	assert.match(app, /emailSignIn=\{config\.waysIn\?\.emailSignIn \|\| ''\}/)
})
