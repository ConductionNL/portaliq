#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// guest-action-page-calls.spec.mjs: the requests the guest page sends for the
// preview and the act, run through the page itself, so they stay the same
// when the library's calls change shape.
//
// Usage:
//   node --test tests/guest-action-page-calls.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { instance } from './support/page-instance.mjs'
import { loadSfc } from './support/render-sfc.mjs'

const GuestActionPage = await loadSfc('src/site/pages/GuestActionPage.vue')

test('the page posts the preview and the act to the guest routes with the token and the portal', async () => {
	const calls = []
	globalThis.document = { documentElement: { lang: 'en' }, getElementById: () => null, querySelector: () => null }
	globalThis.window = {
		location: { hash: '#guest/shillinq/withdraw/T1', pathname: '/site', search: '', assign: () => {} },
		history: { replaceState() {} },
	}
	globalThis.fetch = async (url, init) => {
		calls.push([url, JSON.parse(init.body)])
		return { ok: true, status: 200, json: async () => ({ action: { label: 'Go' } }) }
	}
	const page = instance(GuestActionPage, { authBase: '/api', portal: 'knip' })
	await GuestActionPage.mounted.call(page)
	page.values = { reason: 'x' }
	await page.act()
	assert.deepEqual(calls, [
		['/api/guest/shillinq/withdraw/preview', { token: 'T1', portal: 'knip' }],
		['/api/guest/shillinq/withdraw', { reason: 'x', token: 'T1', portal: 'knip' }],
	])
	assert.equal(page.outcome.kind, 'done')
})
