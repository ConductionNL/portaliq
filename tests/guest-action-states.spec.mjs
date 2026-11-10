#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// guest-action-states.spec.mjs: the guest page's reading of a signed-link
// fragment and of the preview answer, pinned branch by branch before both were
// split into named steps.
//
// Usage:
//   node --test tests/guest-action-states.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	actOutcome,
	guestPreview,
	guestStrings,
	previewState,
	takeGuestLink,
} from '../src/site/lib/guestAction.js'

const strings = guestStrings('en')

test('a fragment that is no guest link is left alone; a malformed one is cleared', () => {
	const replaced = []
	const history = { replaceState: (state, title, url) => replaced.push([state, title, url]) }
	assert.equal(takeGuestLink(null, history), null)
	assert.equal(takeGuestLink({}, history), null)
	assert.deepEqual(replaced, [])
	for (const hash of ['#guest/a/b', '#guest/a/b/c/d', '#guest/A!/withdraw/t', '#guest/app/act!on/t', '#guest/app/act/t t']) {
		assert.equal(takeGuestLink({ hash }, history), null, hash)
	}
	assert.deepEqual(replaced[0], [null, '', ''])
	assert.equal(replaced.length, 5)
	assert.deepEqual(
		takeGuestLink({ hash: '#guest/app/act/tok', pathname: '/p', search: '?q' }, history),
		{ app: 'app', action: 'act', token: 'tok' },
	)
	assert.deepEqual(replaced[5], [null, '', '/p?q'])
})

test('the preview state takes the declaration only from a good answer', () => {
	const action = {
		label: '  ',
		confirmText: 'Ja',
		fields: ['reason', 3, null, 'iban'],
		fieldConfigs: { reason: { label: 'Reden' } },
	}
	assert.deepEqual(previewState({ ok: true, status: 200, body: { action } }, strings), {
		usable: true,
		summary: '',
		reason: '',
		label: strings.continue,
		confirmText: 'Ja',
		fields: ['reason', 'iban'],
		fieldConfigs: { reason: { label: 'Reden' } },
	})
	const bare = previewState({ ok: true, status: 200, body: { action: { fields: 'x', fieldConfigs: 'y' }, preview: { summary: 3, available: false } } }, strings)
	assert.deepEqual(bare, {
		usable: false,
		summary: '',
		reason: strings.unusable,
		label: strings.continue,
		confirmText: strings.confirm,
		fields: [],
		fieldConfigs: {},
	})
	const notOk = previewState({ ok: false, status: 404, body: { action } }, strings)
	assert.deepEqual([notOk.label, notOk.confirmText, notOk.fields, notOk.usable], [strings.continue, strings.confirm, [], true])
	assert.equal(previewState({ ok: true, status: 200, body: { preview: { available: 'no' } } }, strings).usable, true)
})

test('without an answer, or with a failed one, the link cannot be used', () => {
	assert.deepEqual(previewState(null, strings), {
		usable: false,
		summary: '',
		reason: strings.unusable,
		label: strings.continue,
		confirmText: strings.confirm,
		fields: [],
		fieldConfigs: {},
	})
	const both = previewState({ ok: false, status: 400, body: { preview: { message: ' ' }, message: 'Bovenop' } }, strings)
	assert.equal(both.reason, 'Bovenop')
	const first = previewState({ ok: false, status: 400, body: { preview: { message: 'Binnen' }, message: 'Bovenop' } }, strings)
	assert.equal(first.reason, 'Binnen')
	assert.equal(previewState({ ok: false, status: 0 }, strings).reason, strings.unusable)
})

test('an answer without readable JSON is an empty body, and the status goes along', async () => {
	const link = { app: 'app', action: 'act', token: 't' }
	const broken = await guestPreview({ authBase: '/b', link, portal: '' }, async () => ({ ok: false, status: 502, json: async () => { throw new Error('html') } }))
	assert.deepEqual(broken, { ok: false, status: 502, body: {} })
	const empty = await guestPreview({ authBase: '/b', link, portal: '' }, async () => ({ ok: true, status: 204, json: async () => null }))
	assert.deepEqual(empty, { ok: true, status: 204, body: {} })
	const truthy = await guestPreview({ authBase: '/b', link, portal: '' }, async () => ({ ok: 1, status: 200, json: async () => ({ a: 1 }) }))
	assert.deepEqual(truthy, { ok: false, status: 200, body: { a: 1 } })
})

test('the act outcome without an answer or a declaration', () => {
	assert.deepEqual(actOutcome(null, null, strings), { kind: 'refused', message: strings.unusable })
	assert.deepEqual(actOutcome({ ok: true }, null, strings), { kind: 'done', message: strings.done })
	assert.deepEqual(actOutcome({ ok: true, body: { redirectUrl: ' https://x' } }, {}, strings), { kind: 'done', message: strings.done })
})
