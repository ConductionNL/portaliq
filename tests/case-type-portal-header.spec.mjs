/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * operate-show-per-case-type: the resident portal names the portal it is
 * served as on every read, so the server applies that portal's hidden case
 * types and not another portal's of the same organisation.
 */

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
			json: async () => ({ objects: [], object: {} }),
		}
	}
	return calls
}

const CASES = { id: 'cases', register: 'dossiq', schema: 'case' }

test('a list and a case page name the serving portal', async () => {
	const calls = stubBrowser()
	const api = createPortalApi({
		apiBase: '/api',
		organisationSlug: 'mijn-alkmaar',
	})

	await api.fetchCollection(CASES)
	await api.fetchObject(CASES, 'case-1')

	assert.equal(calls.length, 2)
	for (const call of calls) {
		assert.equal(call.headers['X-Portaliq-Portal'], 'mijn-alkmaar')
		assert.equal(call.headers.Authorization, 'Bearer token-1')
	}
})

test('a portal that resolved to none sends no portal header', async () => {
	const calls = stubBrowser()
	const api = createPortalApi({ apiBase: '/api', organisationSlug: '' })

	await api.fetchCollection(CASES)

	assert.equal('X-Portaliq-Portal' in calls[0].headers, false)
})

// The Vue port on the site (site-reaches-portal-parity T21, REQ-SRP-042): the
// citizen case block reads through the same adapter, so it names the portal too.

test('site: the citizen case block reads the case through the adapter, which names the serving portal', async () => {
	const calls = stubBrowser()
	const api = createPortalApi({
		apiBase: '/api',
		organisationSlug: 'mijn-alkmaar',
	})
	await api.fetchCitizenCase(CASES, 'case-1')
	assert.equal(calls[0].url, '/api/citizen/cases/dossiq/case/case-1')
	assert.equal(calls[0].headers['X-Portaliq-Portal'], 'mijn-alkmaar')

	const { loadSfc } = await import('./support/render-sfc.mjs')
	const screen = await loadSfc('src/site/components/e/CitizenCase.vue')
	await screen.methods.load.call({
		caseId: 'case-2',
		mandateId: '',
		collection: CASES,
		api,
	})
	assert.equal(calls[1].url, '/api/citizen/cases/dossiq/case/case-2')
	assert.equal(
		calls[1].headers['X-Portaliq-Portal'],
		'mijn-alkmaar',
		'the block reads through the adapter',
	)
})
