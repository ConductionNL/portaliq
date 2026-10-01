#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// guest-action-page.spec.mjs: the guest page for signed links
// (identity-guest-page-for-signed-links T03, T04). The site reads
// `#guest/<app>/<action>/<token>` once and clears it, asks the guest routes
// for the preview and the act, and turns each answer into what the page
// shows: the app's summary, its reason and no button, a message, or the
// https checkout it sends a payer to.
//
// Usage:
//   node --test tests/guest-action-page.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const lib = await import(pathToFileURL(join(ROOT, 'src/site/lib/guestAction.js')).href)

/**
 * A browser location and history double.
 *
 * @param {string} hash The fragment.
 * @return {object} `{location, history, replaced}`.
 */
function browser(hash) {
	const replaced = []
	return {
		location: { hash, pathname: '/apps/portaliq/site', search: '?portal=knip' },
		history: { replaceState: (state, title, url) => replaced.push(url) },
		replaced,
	}
}

/**
 * A fetch double answering once with a status and body, recording the call.
 *
 * @param {number} status The status.
 * @param {object} body The JSON body.
 * @param {Array} calls Receives `[url, init]`.
 * @return {Function} The fetch double.
 */
function answering(status, body, calls) {
	return async (url, init) => {
		calls.push([url, init])
		return { ok: status >= 200 && status < 300, status, json: async () => body }
	}
}

test('the fragment is read once and removed from the address bar (REQ-GST-002)', () => {
	const b = browser('#guest/shillinq/withdraw/abc.DEF-123')
	assert.deepEqual(lib.takeGuestLink(b.location, b.history), { app: 'shillinq', action: 'withdraw', token: 'abc.DEF-123' })
	assert.deepEqual(b.replaced, ['/apps/portaliq/site?portal=knip'])

	for (const hash of ['', '#signin=failed', '#guest/shillinq/withdraw/', '#guest/../withdraw/t', '#guest/shillinq/with draw/t']) {
		const other = browser(hash)
		assert.equal(lib.takeGuestLink(other.location, other.history), null, hash)
		assert.deepEqual(other.replaced, hash.startsWith('#guest/') ? ['/apps/portaliq/site?portal=knip'] : [])
	}
})

test('preview and act post the token to the guest routes', async () => {
	const calls = []
	const link = { app: 'shillinq', action: 'withdraw', token: 'T1' }
	await lib.guestPreview('/apps/portaliq/portal/api', link, 'knip', answering(200, {}, calls))
	await lib.guestAct('/apps/portaliq/portal/api', link, { reason: 'x' }, 'knip', answering(200, {}, calls))

	assert.equal(calls[0][0], '/apps/portaliq/portal/api/guest/shillinq/withdraw/preview')
	assert.deepEqual(JSON.parse(calls[0][1].body), { token: 'T1', portal: 'knip' })
	assert.equal(calls[1][0], '/apps/portaliq/portal/api/guest/shillinq/withdraw')
	assert.deepEqual(JSON.parse(calls[1][1].body), { reason: 'x', token: 'T1', portal: 'knip' })
	assert.equal(calls[1][1].method, 'POST')
	assert.equal(calls[1][1].headers.Authorization, undefined)

	const failed = await lib.guestAct('/x', link, {}, '', async () => {
		throw new Error('offline')
	})
	assert.deepEqual(failed, { ok: false, status: 0, body: {} })
})

test('a preview shows the summary, or the reason and no button (REQ-GST-004)', () => {
	const strings = lib.guestStrings('en')
	const open = lib.previewState({ ok: true, status: 200, body: { preview: { available: true, summary: 'Knippen en kleuren, 14 oktober' }, action: { label: 'Withdraw from contract here', fields: [] } } }, strings)
	assert.deepEqual(open, { usable: true, summary: 'Knippen en kleuren, 14 oktober', reason: '', label: 'Withdraw from contract here', confirmText: strings.confirm, fields: [], fieldConfigs: {} })

	const exempt = lib.previewState({ ok: true, status: 200, body: { preview: { available: false, reason: 'Workshops are exempt from withdrawal.', summary: 'Workshop bloemschikken 12 oktober' }, action: { label: 'Withdraw' } } }, strings)
	assert.equal(exempt.usable, false)
	assert.equal(exempt.reason, 'Workshops are exempt from withdrawal.')

	const none = lib.previewState({ ok: false, status: 404, body: { error: 'not_found' } }, strings)
	assert.equal(none.usable, true)
	assert.equal(none.label, strings.continue)

	const refused = lib.previewState({ ok: false, status: 403, body: { message: 'This link has expired.' } }, strings)
	assert.deepEqual([refused.usable, refused.reason], [false, 'This link has expired.'])
	const silent = lib.previewState({ ok: false, status: 500, body: {} }, strings)
	assert.deepEqual([silent.usable, silent.reason], [false, 'This link cannot be used.'])
})

test('the answer of the act: https checkout, message, or refusal (REQ-GST-004)', () => {
	const strings = lib.guestStrings('nl')
	assert.deepEqual(lib.actOutcome({ ok: true, status: 200, body: { redirectUrl: 'https://pay.example/c/1' } }, {}, strings), { kind: 'redirect', url: 'https://pay.example/c/1', message: strings.redirecting })
	assert.deepEqual(lib.actOutcome({ ok: true, status: 200, body: { redirectUrl: 'http://pay.example/c/1', message: 'Gelukt' } }, {}, strings), { kind: 'done', message: 'Gelukt' })
	assert.deepEqual(lib.actOutcome({ ok: true, status: 200, body: {} }, { successText: 'Uw herroeping is ontvangen.' }, strings), { kind: 'done', message: 'Uw herroeping is ontvangen.' })
	assert.deepEqual(lib.actOutcome({ ok: false, status: 404, body: { error: 'not_found' } }, {}, strings), { kind: 'refused', message: strings.unusable })
	assert.deepEqual(lib.actOutcome({ ok: false, status: 422, body: { message: 'De termijn is verstreken.' } }, {}, strings), { kind: 'refused', message: 'De termijn is verstreken.' })
})

test('English and Dutch carry the same strings, without em-dashes', () => {
	const en = lib.guestStrings('en')
	const nl = lib.guestStrings('nl')
	assert.deepEqual(Object.keys(en).sort(), Object.keys(nl).sort())
	assert.equal(en.unusable, 'This link cannot be used.')
	assert.equal(en.redirecting, 'Taking you to the payment page')
	assert.deepEqual(lib.guestStrings('fr'), nl)
	for (const value of [...Object.values(en), ...Object.values(nl)]) {
		assert.equal(value.includes('—'), false, value)
	}
})

test('the site mounts the guest page on demand, before any other page', () => {
	const app = readFileSync(join(ROOT, 'src/site/App.vue'), 'utf8')
	assert.match(app, /import\('\.\/pages\/GuestActionPage\.vue'\)/)
	assert.match(app, /<GuestActionPage\s[^>]*v-if="guestLink"/)
	assert.match(app, /v-else-if="loading"/)
	assert.doesNotMatch(app, /from '\.\/lib\/guestAction\.js'/, 'the library stays out of the entry bundle')

	const page = readFileSync(join(ROOT, 'src/site/pages/GuestActionPage.vue'), 'utf8')
	assert.match(page, /takeGuestLink\(window\.location, window\.history\)/)
	assert.match(page, /window\.location\.assign\(/)
	assert.match(page, /role="alert"|aria-live="polite"/)
})
