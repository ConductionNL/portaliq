#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-cosign.spec.mjs: the co-signer's page of a form that needs a second
// signature (resident-identity-in-forms REQ-RIF-003, board MedeOndertekenen).
// The route, the code as the mail shows it, the code kept over the DigiD round
// trip, the calls to the co-sign routes, what the review shows, the page in
// each state, the waiting line on the confirmation and the status page, the
// shell's wiring, and both languages for every string the page shows.
//
// Usage:
//   node --test tests/site-cosign.spec.mjs
//
// @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { createPortalApi } from '../src/shared/portalApi.js'
import {
	answerCosign,
	CODE_KEY,
	COSIGN_ROUTE,
	cosignView,
	digidHref,
	forgetCode,
	heldLine,
	isCosignRoute,
	normaliseCode,
	openCosign,
	recallCode,
	rememberCode,
} from '../src/site/lib/cosign.js'
import { cosignWaiting, statusView } from '../src/site/lib/intakeApi.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const bundle = (locale) => JSON.parse(readFileSync(join(ROOT, 'src', 'shared', 'i18n', `${locale}.json`), 'utf8'))
const t = (key, vars = {}) => key.replace(/\{(\w+)\}/g, (match, name) => (name in vars ? String(vars[name]) : match))

/**
 * A stand-in for `fetch` that records each call and answers in turn.
 *
 * @param {Array<{status: number, body: object}>} answers The answers.
 * @return {Function} The fetch, with `calls`.
 */
function fakeFetch(answers) {
	const calls = []
	const impl = async (url, init = {}) => {
		calls.push({ url, init })
		const next = answers.shift() || { status: 500, body: {} }
		return { ok: next.status >= 200 && next.status < 300, status: next.status, json: async () => next.body }
	}
	impl.calls = calls
	return impl
}

/**
 * A stand-in session storage.
 *
 * @return {object}
 */
function memoryStorage() {
	const items = new Map()
	return {
		getItem: (key) => (items.has(key) ? items.get(key) : null),
		setItem: (key, value) => items.set(key, String(value)),
		removeItem: (key) => items.delete(key),
		items,
	}
}

test('the co-sign route opens with or without a trailing slash and nothing else', () => {
	assert.equal(COSIGN_ROUTE, '/mede-ondertekenen')
	assert.ok(isCosignRoute('/mede-ondertekenen'))
	assert.ok(isCosignRoute('/mede-ondertekenen/'))
	assert.ok(!isCosignRoute('/mede-ondertekenen/extra'))
	assert.ok(!isCosignRoute('/'))
	assert.ok(!isCosignRoute(undefined))
})

test('a typed code reads as the mail shows it, whatever its case, spaces or dash', () => {
	assert.equal(normaliseCode('7rq k2m'), '7RQ-K2M')
	assert.equal(normaliseCode(' 7RQ-K2M '), '7RQ-K2M')
	assert.equal(normaliseCode('7RQK2M'), '7RQ-K2M')
	assert.equal(normaliseCode('7RQ-K2'), '', 'too short')
	assert.equal(normaliseCode('7RQ-K2MM'), '', 'too long')
	assert.equal(normaliseCode(null), '')
})

test('the code waits in session storage over the DigiD round trip and is dropped after', () => {
	const storage = memoryStorage()
	assert.equal(recallCode(storage), '')
	assert.ok(rememberCode('7RQ-K2M', storage))
	assert.equal(storage.items.get(CODE_KEY), '7RQ-K2M')
	assert.equal(recallCode(storage), '7RQ-K2M')
	forgetCode(storage)
	assert.equal(recallCode(storage), '')

	const broken = { getItem: () => { throw new Error('blocked') }, setItem: () => { throw new Error('blocked') }, removeItem: () => { throw new Error('blocked') } }
	assert.equal(rememberCode('7RQ-K2M', broken), false)
	assert.equal(recallCode(broken), '')
	assert.doesNotThrow(() => forgetCode(broken))
})

test('the DigiD sign-in names the portal and comes back to the page, and never carries the code', () => {
	const href = digidHref('/index.php/apps/portaliq/portal/api/', 'gemeente-x')
	const url = new URL(href, 'https://example.org')
	assert.equal(url.pathname, '/index.php/apps/portaliq/portal/api/session/oidc/start')
	assert.equal(url.searchParams.get('provider'), 'digid')
	assert.equal(url.searchParams.get('portal'), 'gemeente-x')
	assert.equal(url.searchParams.get('returnTo'), '/mede-ondertekenen')
	assert.doesNotMatch(href, /7RQ/)
	assert.equal(new URL(digidHref('/api', ''), 'https://example.org').searchParams.has('portal'), false)
})

test('opening sends the code and the bearer, and the request comes back read-only', async () => {
	const fetchImpl = fakeFetch([{
		status: 200,
		body: { submission: { reference: 'AANVRAAG-1', route: 'woo', answers: { onderwerp: 'Parkeren', aantal: 2, handtekening: 'data:image/png;base64,AAA', leeg: '', lijst: ['a'] }, deadline: '2026-10-22T10:00:00+00:00' } },
	}])

	const opened = await openCosign({ base: '/api/', portal: 'gemeente-x', token: 'tok', fetchImpl }, '7RQ-K2M')

	assert.equal(fetchImpl.calls[0].url, '/api/cosign?code=7RQ-K2M&portal=gemeente-x')
	assert.equal(fetchImpl.calls[0].init.headers.Authorization, 'Bearer tok')
	assert.equal(opened.status, 'ok')
	assert.deepEqual(opened.submission, {
		reference: 'AANVRAAG-1',
		deadline: '22 oktober 2026',
		lines: [{ label: 'onderwerp', value: 'Parkeren' }, { label: 'aantal', value: '2' }],
	})
})

test('without a session the page asks for DigiD and nothing is sent', async () => {
	const fetchImpl = fakeFetch([])
	assert.deepEqual(await openCosign({ base: '/api', token: '', fetchImpl }, '7RQ-K2M'), { status: 'signIn', submission: null })
	assert.equal(fetchImpl.calls.length, 0)
})

test('each refusal of the server has its own status, and a failure is an error', async () => {
	const answers = [
		[401, 'sign_in_required', 'signIn'],
		[404, 'unknown', 'notFound'],
		[410, 'expired', 'expired'],
		[403, 'same_subject', 'sameSubject'],
		[503, 'unavailable', 'error'],
	]
	for (const [status, error, expected] of answers) {
		const fetchImpl = fakeFetch([{ status, body: { error } }])
		assert.equal((await openCosign({ base: '/api', token: 'tok', fetchImpl }, '7RQ-K2M')).status, expected, error)
	}
	const throwing = async () => { throw new Error('offline') }
	assert.equal((await openCosign({ base: '/api', token: 'tok', fetchImpl: throwing }, '7RQ-K2M')).status, 'error')
	assert.equal((await answerCosign({ base: '/api', token: 'tok', fetchImpl: throwing }, { code: 'x', action: 'refuse' })).status, 'error')
})

test('co-signing posts the confirmation, refusing posts only the code', async () => {
	const fetchImpl = fakeFetch([
		{ status: 200, body: { state: 'signed', reference: 'AANVRAAG-1' } },
		{ status: 200, body: { state: 'refused', reference: 'AANVRAAG-1' } },
		{ status: 400, body: { error: 'not_confirmed' } },
	])
	const conn = { base: '/api', portal: 'gemeente-x', token: 'tok', fetchImpl }

	assert.deepEqual(await answerCosign(conn, { code: '7RQ-K2M', action: 'sign', confirmed: true }), { status: 'signed', reference: 'AANVRAAG-1' })
	assert.deepEqual(await answerCosign(conn, { code: '7RQ-K2M', action: 'refuse' }), { status: 'refused', reference: 'AANVRAAG-1' })
	assert.equal((await answerCosign(conn, { code: '7RQ-K2M', action: 'sign' })).status, 'notConfirmed')

	assert.equal(fetchImpl.calls[0].url, '/api/cosign/sign')
	assert.equal(fetchImpl.calls[0].init.method, 'POST')
	assert.deepEqual(JSON.parse(fetchImpl.calls[0].init.body), { code: '7RQ-K2M', confirmed: true, portal: 'gemeente-x' })
	assert.equal(fetchImpl.calls[1].url, '/api/cosign/refuse')
	assert.deepEqual(JSON.parse(fetchImpl.calls[1].init.body), { code: '7RQ-K2M', portal: 'gemeente-x' })
	assert.deepEqual(JSON.parse(fetchImpl.calls[2].init.body), { code: '7RQ-K2M', confirmed: false, portal: 'gemeente-x' })
})

test('the review leaves out what is not a plain answer', () => {
	assert.deepEqual(cosignView(undefined), { reference: '', deadline: '', lines: [] })
	assert.deepEqual(cosignView({ reference: 'R', answers: null, deadline: 'later' }), { reference: 'R', deadline: '', lines: [] })
})

const { renderSfc } = await import('./support/render-sfc.mjs')

test('the start asks for the code and offers DigiD, with a label on the field', async () => {
	const html = await renderSfc('src/site/components/CosignPage.vue', { t })
	assert.match(html, /<h1[^>]*>\s*Co-sign a request\s*<\/h1>/)
	assert.match(html, /<label[^>]*for="pq-cosign-code"[^>]*>Your code<\/label>/)
	assert.match(html, /id="pq-cosign-code"/)
	assert.match(html, /Log in with DigiD and check/)
})

test('the review shows the request read-only, the deadline, the check and both choices', async () => {
	const html = await renderSfc('src/site/components/CosignPage.vue', {
		t,
		initialState: { step: 'review', code: '7RQ-K2M', request: { reference: 'AANVRAAG-1', deadline: '22 oktober 2026', lines: [{ label: 'onderwerp', value: 'Parkeren' }] } },
	})
	assert.match(html, /Check and co-sign/)
	assert.match(html, /Request AANVRAAG-1/)
	assert.match(html, /<dt>onderwerp<\/dt>\s*<dd>Parkeren<\/dd>/)
	assert.match(html, /You cannot change the request\./)
	assert.match(html, /Do this before 22 oktober 2026\./)
	assert.match(html, /<label for="pq-cosign-confirm">I have read the request and co-sign it\.<\/label>/)
	assert.match(html, /data-testid="cosign-sign"/)
	assert.match(html, /data-testid="cosign-refuse"/)
	assert.doesNotMatch(html, /<input[^>]*id="pq-cosign-code"/, 'nothing on the review can be changed')
})

test('the page says what happened after signing, refusing or a problem', async () => {
	const signed = await renderSfc('src/site/components/CosignPage.vue', { t, initialState: { step: 'done', outcome: 'signed' } })
	assert.match(signed, /Thank you\. You co-signed the request\./)
	const refused = await renderSfc('src/site/components/CosignPage.vue', { t, initialState: { step: 'done', outcome: 'refused' } })
	assert.match(refused, /You refused to co-sign\. The applicant is told\./)
	const problem = await renderSfc('src/site/components/CosignPage.vue', { t, initialState: { step: 'code', problem: 'You cannot co-sign your own request.' } })
	assert.match(problem, /role="alert"[^>]*>\s*You cannot co-sign your own request\./)
	assert.match(problem, /aria-invalid="true"/)
})

test('the confirmation and the status page say the request waits for its co-signer', () => {
	const waiting = { reference: 'AANVRAAG-1', state: 'awaiting-cosign', cosign: { state: 'awaiting', name: 'Henk de Vries', deadline: '2026-10-22T10:00:00+00:00' } }
	assert.equal(cosignWaiting(waiting), 'Wacht op mede-ondertekening door Henk de Vries. Dit kan tot 22 oktober 2026.')
	assert.equal(cosignWaiting({ state: 'awaiting-cosign', cosign: {} }), 'Wacht op mede-ondertekening.')
	assert.equal(cosignWaiting({ state: 'registered' }), '')
	assert.equal(cosignWaiting(null), '')

	assert.deepEqual(statusView(waiting), {
		tone: 'info',
		sentence: 'Uw aanvraag AANVRAAG-1 is ontvangen. Wacht op mede-ondertekening door Henk de Vries. Dit kan tot 22 oktober 2026.',
	})
	assert.deepEqual(statusView({ reference: 'AANVRAAG-1', state: 'not-cosigned' }), {
		tone: 'error',
		sentence: 'Uw aanvraag AANVRAAG-1 is niet mede-ondertekend en daarom niet verstuurd.',
	})

	const block = readFileSync(join(ROOT, 'src', 'site', 'components', 'IntakeFormBlock.vue'), 'utf8')
	assert.match(block, /v-if="waiting"[\s\S]{0,80}data-testid="intake-form-cosign-waiting"/)
	assert.match(block, /this\.waiting = outcome\.waiting \|\| ''/)
})

test('the shell renders the page for the route without reading a CMS page, and loads it on demand', () => {
	const app = readFileSync(join(ROOT, 'src', 'site', 'App.vue'), 'utf8')
	assert.match(app, /const CosignPage = defineAsyncComponent\(\s*\(\) => import\('\.\/components\/CosignPage\.vue'\),?\s*\)/)
	assert.match(app, /<CosignPage\s+v-else-if="cosignRoute"/)
	const pageless = app.slice(app.indexOf('openPagelessRoute(route) {'))
	assert.ok(pageless.indexOf('if (isCosignRoute(route))') > 0, 'the route opens without a CMS page')
})

test('every string the page shows exists in English and Dutch', () => {
	const page = readFileSync(join(ROOT, 'src', 'site', 'components', 'CosignPage.vue'), 'utf8')
	const keys = [...page.matchAll(/\bt\(\s*'([^']+)'/g)].map((m) => m[1])
	assert.ok(keys.length >= 15)
	const en = bundle('en')
	const nl = bundle('nl')
	for (const key of keys) {
		assert.ok(en[key], `en: ${key}`)
		assert.ok(nl[key], `nl: ${key}`)
		assert.doesNotMatch(nl[key], /—/)
	}
	assert.equal(nl['Co-sign a request'], 'Aanvraag mede-ondertekenen')
	assert.equal(nl['I have read the request and co-sign it.'], 'Ik heb de aanvraag gelezen en onderteken mee.')
	assert.equal(nl['Log in with DigiD and check'], 'Inloggen met DigiD en controleren')
})

test('Mijn zaken says whom a held request waits for and until when, or that it was not co-signed', () => {
	const nl = bundle('nl')
	const tNl = (key, vars = {}) => t(nl[key] || key, vars)
	const waiting = { reference: 'AANVRAAG-1', state: 'awaiting-cosign', cosign: { state: 'awaiting', name: 'Henk de Vries', deadline: '2026-10-22T10:00:00+00:00' } }

	assert.deepEqual(heldLine(waiting, tNl, 'nl'), {
		reference: 'AANVRAAG-1',
		tone: 'info',
		title: 'Aanvraag AANVRAAG-1',
		sentence: 'Wacht op mede-ondertekening door Henk de Vries. Dit kan tot 22 oktober 2026.',
	})
	assert.equal(heldLine(waiting, t, 'en').sentence, 'Waiting for co-signature by Henk de Vries. This is possible until 22 October 2026.')
	assert.equal(heldLine({ ...waiting, cosign: {} }, tNl, 'nl').sentence, 'Wacht op mede-ondertekening.')
	assert.deepEqual(heldLine({ reference: 'AANVRAAG-2', state: 'not-cosigned', cosign: { state: 'expired' } }, tNl, 'nl'), {
		reference: 'AANVRAAG-2',
		tone: 'error',
		title: 'Aanvraag AANVRAAG-2',
		sentence: 'Niet mede-ondertekend en daarom niet verstuurd.',
	})
	assert.equal(heldLine({ reference: 'AANVRAAG-3', state: 'queued' }, tNl, 'nl'), null)
	assert.equal(heldLine(null, tNl, 'nl'), null)
})

test('the held requests are read with the bearer and the portal, and a failed read lists nothing', async () => {
	const calls = []
	const answers = [
		{ ok: true, json: async () => ({ requests: [{ reference: 'AANVRAAG-1', state: 'awaiting-cosign' }] }) },
		{ ok: false, json: async () => ({}) },
		{ ok: true, json: async () => ({ requests: 'not a list' }) },
	]
	const previous = globalThis.fetch
	globalThis.fetch = async (url, init) => {
		calls.push({ url, init })
		return answers.shift()
	}
	try {
		const api = createPortalApi({ apiBase: '/apps/portaliq/portal/api', organisationSlug: 'gemeente-x' }, { getToken: () => 'tok', setToken: () => {} })
		assert.deepEqual(await api.listHeldRequests(), [{ reference: 'AANVRAAG-1', state: 'awaiting-cosign' }])
		assert.deepEqual(await api.listHeldRequests(), [])
		assert.deepEqual(await api.listHeldRequests(), [])
	} finally {
		globalThis.fetch = previous
	}
	assert.equal(calls[0].url, '/apps/portaliq/portal/api/cosign/held')
	assert.equal(calls[0].init.headers.Authorization, 'Bearer tok')
	assert.equal(calls[0].init.headers['X-Portaliq-Portal'], 'gemeente-x')
})

test('Mijn zaken shows the held requests above the case list, in both languages', () => {
	const page = readFileSync(join(ROOT, 'src', 'site', 'pages', 'e', 'MyCasesPage.vue'), 'utf8')
	assert.match(page, /<HeldRequests\s+v-if="session"\s+:api="api"/)
	assert.ok(page.indexOf('<HeldRequests') < page.indexOf('data-testid="my-cases-list"'))

	const block = readFileSync(join(ROOT, 'src', 'site', 'components', 'forms', 'HeldRequests.vue'), 'utf8')
	assert.match(block, /this\.requests = await this\.api\.listHeldRequests\(\)/)
	const lib = readFileSync(join(ROOT, 'src', 'site', 'lib', 'cosign.js'), 'utf8')
	const shown = lib.slice(lib.indexOf('export function heldLine'))
	const keys = [...`${block}\n${shown}`.matchAll(/\bt\(\s*'([^']+)'/g)].map((m) => m[1])
	assert.ok(keys.length >= 6)
	const en = bundle('en')
	const nl = bundle('nl')
	for (const key of keys) {
		assert.ok(en[key], `en: ${key}`)
		assert.ok(nl[key], `nl: ${key}`)
	}
	assert.equal(nl['Waiting for co-signature by {name}.'], 'Wacht op mede-ondertekening door {name}.')
})
