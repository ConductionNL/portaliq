#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// access-request-asker.spec.mjs: the asker's side of an access request
// (identity-access-requests T04, T05). A signed-in portal user asks for access
// to a named party's cases with a reason, and sees every request they made
// with its state and, for a refusal, the reason given.
//
// Usage:
//   node --test tests/access-request-asker.spec.mjs

import assert from 'node:assert/strict'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { buildNav, defaultNavKey, shellSections } from '../src/shared/portalNav.js'
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

const { createPortalApi } = await load('../shared/portalApi.js')
const {
	default: AccessRequestsPage,
	newestFirst,
	requestProblem,
	stateLabel,
} = await load('components/AccessRequestsPage.jsx')

const BASE = '/apps/portaliq/portal/api'
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

test('asking posts the party and the reason with the bearer, and a refusal names its error', async () => {
	const calls = stubBrowser([
		{ status: 200, body: { state: 'pending' } },
		{ status: 400, body: { error: 'reason_required' } },
	])
	const api = createPortalApi({ apiBase: BASE })

	const asked = await api.requestAccess(
		'87654321',
		'I keep the books for this company.',
	)
	assert.deepEqual(asked, { ok: true, error: '' })
	assert.equal(calls[0].url, `${BASE}/identity/access-requests`)
	assert.equal(calls[0].init.method, 'POST')
	assert.equal(calls[0].init.headers.Authorization, 'Bearer token-1')
	assert.deepEqual(JSON.parse(calls[0].init.body), {
		onBehalfOf: '87654321',
		reason: 'I keep the books for this company.',
	})

	const refused = await api.requestAccess('87654321', '')
	assert.deepEqual(refused, { ok: false, error: 'reason_required' })
})

test('the asker reads their own requests, and a failure reads as none', async () => {
	stubBrowser([
		{
			status: 200,
			body: {
				requests: [{ id: 'r1', onBehalfOf: '87654321', state: 'pending' }],
			},
		},
		{ status: 401, body: { authenticated: false } },
	])
	const api = createPortalApi({ apiBase: BASE })
	assert.equal((await api.fetchMyAccessRequests()).length, 1)
	assert.deepEqual(await api.fetchMyAccessRequests(), [])
})

test('a request needs a party and a reason before it is sent', () => {
	assert.equal(
		requestProblem({ onBehalfOf: '', reason: 'x' }),
		'Say whose cases you need access to.',
	)
	assert.equal(
		requestProblem({ onBehalfOf: '87654321', reason: '   ' }),
		'Give a reason for your request.',
	)
	assert.equal(
		requestProblem({ onBehalfOf: '87654321', reason: 'Bookkeeper' }),
		'',
	)
})

test('the newest request comes first', () => {
	const sorted = newestFirst([
		{ id: 'old', requestedAt: '2026-09-01T10:00:00+00:00' },
		{ id: 'new', requestedAt: '2026-09-20T10:00:00+00:00' },
		{ id: 'none' },
	])
	assert.deepEqual(
		sorted.map((r) => r.id),
		['new', 'old', 'none'],
	)
})

test('each state reads as words', () => {
	assert.equal(stateLabel('pending'), 'Waiting for an answer')
	assert.equal(stateLabel('granted'), 'Granted')
	assert.equal(stateLabel('refused'), 'Refused')
	assert.equal(stateLabel('something-else'), 'Waiting for an answer')
})

test('the list shows a pending request and a refusal with its reason', () => {
	const html = renderToStaticMarkup(
		createElement(AccessRequestsPage, {
			api: {},
			t,
			initialRequests: [
				{
					id: 'r1',
					onBehalfOf: '87654321',
					state: 'pending',
					requestedAt: '2026-09-20T10:00:00+00:00',
				},
				{
					id: 'r2',
					onBehalfOf: '11223344',
					state: 'refused',
					decisionReason: 'No authorisation from the company',
					requestedAt: '2026-09-10T10:00:00+00:00',
				},
			],
		}),
	)
	assert.match(html, /87654321/)
	assert.match(html, /Waiting for an answer/)
	assert.match(html, /Refused/)
	assert.match(html, /No authorisation from the company/)
	assert.match(html, /Ask for access/)
	// The form has a label for each field, so a screen reader names them.
	assert.match(html, /<label for="access-request-party"/)
	assert.match(html, /<label for="access-request-reason"/)
})

test('with no requests yet the page says so', () => {
	const html = renderToStaticMarkup(
		createElement(AccessRequestsPage, { api: {}, t, initialRequests: [] }),
	)
	assert.match(html, /You have not asked for access yet\./)
})

test('the portal offers the page to every signed-in user', () => {
	const app = readFileSync(join(ROOT, 'src', 'portal', 'App.jsx'), 'utf8')
	assert.match(
		app,
		/import AccessRequestsPage from '@portal\/components\/AccessRequestsPage\.jsx'/,
	)
	assert.equal(shellSections({ session: {}, contributions: {} }).access, true)
	assert.equal(shellSections({ session: null, contributions: {} }).access, false)
	// The shared navigation offers it, after the content pages, never first.
	const nav = buildNav([{ app: 'a', pages: [{ id: 'p' }] }], (key) => key, { access: true })
	assert.ok(nav.some((entry) => entry.special === 'access'))
	assert.equal(defaultNavKey(nav), 'a:p')
	assert.match(app, /active\.special === 'access' && \(\s*<AccessRequestsPage/)
})

test('every new string has a Dutch translation', () => {
	const nl = JSON.parse(
		readFileSync(join(ROOT, 'src', 'shared', 'i18n', 'nl.json'), 'utf8'),
	)
	const en = JSON.parse(
		readFileSync(join(ROOT, 'src', 'shared', 'i18n', 'en.json'), 'utf8'),
	)
	for (const key of [
		'Access to cases',
		'Ask for access',
		'Whose cases do you need access to?',
		'Why do you need access?',
		'Your requests',
		'Waiting for an answer',
		'Granted',
		'Refused',
		'Reason: {reason}',
		'You have not asked for access yet.',
		'Say whose cases you need access to.',
		'Give a reason for your request.',
		'Your request has been sent.',
		'Your request could not be sent. Try again later.',
		'Ask for access to the cases of a company or person you act for. The organisation answers your request.',
		'For {party}',
		'Asked on {date}',
	]) {
		assert.ok(
			typeof nl[key] === 'string' && nl[key] !== '',
			`nl.json lacks "${key}"`,
		)
		assert.equal(en[key], key, `en.json lacks "${key}"`)
		assert.doesNotMatch(nl[key], /—/)
	}
})

// The Vue port on the site (site-reaches-portal-parity T19, REQ-SRP-039).

const { renderSfc, loadSfc } = await import('./support/render-sfc.mjs')
const site = await import(pathToFileURL(join(ROOT, 'src', 'site', 'pages', 'e', 'accessRequests.js')).href)
const SITE_PAGE = 'src/site/pages/e/AccessRequestsPage.vue'

test('site: a request needs a party and a reason, the newest comes first, and each state reads as words', () => {
	assert.equal(site.requestProblem({ onBehalfOf: ' ', reason: 'x' }), 'Say whose cases you need access to.')
	assert.equal(site.requestProblem({ onBehalfOf: '1', reason: ' ' }), 'Give a reason for your request.')
	assert.equal(site.requestProblem({ onBehalfOf: '1', reason: 'x' }), '')
	assert.deepEqual(
		site.newestFirst([{ id: 'a' }, { id: 'b', requestedAt: '2026-01-01' }, { id: 'c', requestedAt: '2026-02-01' }]).map((r) => r.id),
		['c', 'b', 'a'],
	)
	assert.equal(site.stateLabel('granted'), 'Granted')
	assert.equal(site.stateLabel('refused'), 'Refused')
	assert.equal(site.stateLabel('pending'), 'Waiting for an answer')
	assert.equal(site.sendProblem({ ok: false, error: 'reason_required' }), 'Give a reason for your request.')
	assert.equal(site.sendProblem({ ok: false, error: 'other' }), 'Your request could not be sent. Try again later.')
})

test('site: the list shows a pending request and a refusal with its reason, with a label for each field', async () => {
	const html = await renderSfc(SITE_PAGE, {
		api: {},
		t,
		locale: 'en',
		initialRequests: [
			{ id: 'r2', onBehalfOf: '11223344', state: 'refused', decisionReason: 'No authorisation from the company', requestedAt: '2026-09-10T10:00:00+00:00' },
			{ id: 'r1', onBehalfOf: '87654321', state: 'pending', requestedAt: '2026-09-20T10:00:00+00:00' },
		],
	})
	assert.ok(html.indexOf('87654321') < html.indexOf('11223344'), 'newest first')
	assert.match(html, /data-state="pending"/)
	assert.match(html, /Waiting for an answer/)
	assert.match(html, /Reason: No authorisation from the company/)
	assert.match(html, /Asked on September 20, 2026|Asked on 20 September 2026/)
	assert.match(html, /<label for="access-request-party"/)
	assert.match(html, /<label for="access-request-reason"/)
	const empty = await renderSfc(SITE_PAGE, { api: {}, t, initialRequests: [] })
	assert.match(empty, /You have not asked for access yet\./)
})

test('site: sending checks the form first, then asks, empties the form and lists the request as waiting', async () => {
	const page = await loadSfc(SITE_PAGE)
	const asked = []
	const api = {
		requestAccess: async (party, reason) => { asked.push([party, reason]); return { ok: true } },
		fetchMyAccessRequests: async () => [{ id: 'n', onBehalfOf: '87654321', state: 'pending' }],
	}
	const vm = { api, draft: { onBehalfOf: '', reason: '' }, problem: '', notice: '', busy: false, requests: [] }
	await page.methods.send.call(vm)
	assert.equal(vm.problem, 'Say whose cases you need access to.')
	assert.equal(asked.length, 0, 'nothing is sent while the form is incomplete')

	vm.draft = { onBehalfOf: ' 87654321 ', reason: ' I am the director ' }
	await page.methods.send.call(vm)
	assert.deepEqual(asked, [['87654321', 'I am the director']])
	assert.equal(vm.problem, '')
	assert.equal(vm.notice, 'Your request has been sent.')
	assert.deepEqual(vm.draft, { onBehalfOf: '', reason: '' })
	assert.equal(vm.requests[0].state, 'pending')

	vm.api = { requestAccess: async () => ({ ok: false, error: 'reason_required' }) }
	vm.draft = { onBehalfOf: '1', reason: 'x' }
	await page.methods.send.call(vm)
	assert.equal(vm.problem, 'Give a reason for your request.')
})
