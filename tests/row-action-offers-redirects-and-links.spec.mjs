#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// row-action-offers-redirects-and-links.spec.mjs: on which rows a row action
// is offered, where the browser may go after a forward, which message a
// forward's answer reads as, and which link an answer may hand the resident
// (src/shared/rowAction.js); pinned with allowed and refused outcomes before
// these were split into named steps.
//
// Usage:
//   node --test tests/row-action-offers-redirects-and-links.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { answerLink, offersRowAction, outcomeKey, redirectTarget } from '../src/shared/rowAction.js'

test('a row action is offered on every row without conditions, else only where they hold', () => {
	const row = { status: 'open', kind: 'a' }
	assert.equal(offersRowAction(null, row), true)
	assert.equal(offersRowAction({}, row), true)
	assert.equal(offersRowAction({ rowWhen: { field: 'status', in: ['open', 'new'] } }, row), true)
	assert.equal(offersRowAction({ rowWhen: { field: 'status', in: ['closed'] } }, row), false)
	assert.equal(offersRowAction({ availableWhen: { field: 'kind', equals: 'a' } }, row), true)
	assert.equal(offersRowAction({ availableWhen: { field: 'kind', equals: 'b' } }, row), false)
	assert.equal(offersRowAction({ availableWhen: { field: 'kind', equals: 'a' }, rowWhen: { field: 'status', in: ['closed'] } }, row), false)
	assert.equal(offersRowAction({ availableWhen: { field: 'kind', equals: 'b' }, rowWhen: { field: 'status', in: ['open'] } }, row), false)
})

test('a malformed condition, or no row, offers nothing', () => {
	for (const rowWhen of [{ field: 3, in: ['open'] }, { field: 'status', in: 'open' }, { field: 'status' }, { in: ['open'] }]) {
		assert.equal(offersRowAction({ rowWhen }, { status: 'open' }), false, JSON.stringify(rowWhen))
	}
	assert.equal(offersRowAction({ rowWhen: { field: 'status', in: ['open'] } }, null), false)
	assert.equal(offersRowAction({ availableWhen: { field: 7 } }, { status: 'open' }), false)
	assert.equal(offersRowAction({ availableWhen: { field: 'kind', equals: 'a' } }, null), false)
	assert.equal(offersRowAction({ rowWhen: '' }, { status: 'open' }), true, 'an empty rowWhen is none')
})

test('after a forward the browser goes only to an absolute https address from an ok answer', () => {
	assert.equal(redirectTarget({ ok: true, body: { redirectUrl: 'https://pay.nl/x?a=1' } }), 'https://pay.nl/x?a=1')
	assert.equal(redirectTarget({ ok: true, body: { checkoutUrl: 'https://pay.nl/c' } }), 'https://pay.nl/c')
	assert.equal(redirectTarget({ ok: true, body: { redirectUrl: null, checkoutUrl: 'https://pay.nl/c' } }), 'https://pay.nl/c')
	assert.equal(redirectTarget({ ok: true, body: { redirectUrl: 'HTTPS://Pay.NL' } }), 'https://pay.nl/')
	for (const body of [{ redirectUrl: 'http://pay.nl' }, { redirectUrl: 'javascript:alert(1)' }, { redirectUrl: '/pad' }, { redirectUrl: 'https://' }, { redirectUrl: '' }, { redirectUrl: 5 }, { redirectUrl: '', checkoutUrl: 'https://x.nl' }, {}]) {
		assert.equal(redirectTarget({ ok: true, body }), null, JSON.stringify(body))
	}
	assert.equal(redirectTarget({ ok: false, body: { redirectUrl: 'https://pay.nl' } }), null)
	assert.equal(redirectTarget({ ok: true }), null)
	assert.equal(redirectTarget(null), null)
})

test('a forward\'s answer reads as done, as a refused next page, as gone, or as unavailable', () => {
	assert.equal(outcomeKey({ ok: true, body: {} }), 'Done.')
	assert.equal(outcomeKey({ ok: true, body: { redirectUrl: 'https://pay.nl' } }), 'Done.')
	assert.equal(outcomeKey({ ok: true, body: { redirectUrl: 'http://pay.nl' } }), 'The next page could not be opened.')
	assert.equal(outcomeKey({ ok: true, body: { checkoutUrl: null } }), 'The next page could not be opened.')
	assert.equal(outcomeKey({ ok: true }), 'Done.')
	for (const status of [403, 404, 409]) {
		assert.equal(outcomeKey({ ok: false, status }), 'This can no longer be done for this item.')
	}
	for (const status of [400, 500, 0, '404', undefined]) {
		assert.equal(outcomeKey({ ok: false, status }), 'This is not available right now. Try again later.')
	}
	assert.equal(outcomeKey(null), 'This is not available right now. Try again later.')
})

test('an answer\'s link is handed over only when it leads somewhere safe', () => {
	const ok = (link) => ({ ok: true, body: { link } })
	const origin = 'http://localhost:8080'
	assert.equal(answerLink(ok(' https://s.nl/deel/1 '), origin), 'https://s.nl/deel/1')
	assert.equal(answerLink(ok('/index.php/apps/portaliq/site?route=/d'), origin), '/index.php/apps/portaliq/site?route=/d')
	assert.equal(answerLink(ok('http://localhost:8080/apps/portaliq/site'), origin), 'http://localhost:8080/apps/portaliq/site')
	for (const link of ['http://elders.nl/x', '//elders.nl/x', 'javascript:alert(1)', 'https://a b', 'http://', '', 'ftp://s.nl']) {
		assert.equal(answerLink(ok(link), origin), '', link)
	}
	assert.equal(answerLink(ok('http://localhost:8080/x'), ''), '', 'no origin, no http link')
	assert.equal(answerLink({ ok: false, body: { link: 'https://s.nl' } }, origin), '')
	assert.equal(answerLink({ ok: true, body: { link: 7 } }, origin), '')
	assert.equal(answerLink(null, origin), '')
	globalThis.location = { origin: 'http://localhost:8080' }
	assert.equal(answerLink(ok('http://localhost:8080/y')), 'http://localhost:8080/y', 'the page\'s own origin by default')
	delete globalThis.location
	assert.equal(answerLink(ok('http://localhost:8080/y')), '')
})
