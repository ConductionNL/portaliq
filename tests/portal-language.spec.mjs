#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-language.spec.mjs: the site asks the portal API in the site's own
// language, so a contributing app answers a Dutch site in Dutch even when the
// visitor's browser is set to English (contribution-record-page; learniq's
// parent sections read "My children" on the Wilgenboom site, 2026-10-02).
//
// Usage:
//   node --test tests/portal-language.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createPortalApi } from '../src/shared/portalApi.js'

/**
 * Stub the browser: a stored bearer and a recording fetch.
 *
 * @return {Array<object>} The recorded calls.
 */
function stubBrowser() {
	const calls = []
	globalThis.window = {
		localStorage: { getItem: () => 'token-1', setItem() {}, removeItem() {} },
	}
	globalThis.fetch = async (url, init = {}) => {
		calls.push({ url: String(url), headers: init.headers || {} })
		return {
			ok: true,
			status: 200,
			json: async () => ({ objects: [], contributions: [] }),
		}
	}
	return calls
}

test('the contributions and a collection are asked for in the site language', async () => {
	const calls = stubBrowser()
	const api = createPortalApi({
		apiBase: '/api',
		organisationSlug: 'wilgenboom',
		language: 'nl',
	})

	await api.getContributions()
	await api.fetchCollection({
		id: 'parentChildren',
		register: 'learniq',
		schema: 'learner-profile',
	})

	assert.equal(calls.length, 2)
	for (const call of calls) {
		assert.equal(call.headers['Accept-Language'], 'nl')
	}
})

test('without a site language the browser decides', async () => {
	const calls = stubBrowser()
	const api = createPortalApi({ apiBase: '/api', organisationSlug: 'wilgenboom' })

	await api.getContributions()

	assert.equal('Accept-Language' in calls[0].headers, false)
})
