// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
//
// site-contribution.spec.mjs — the contributed page route and where a declared
// action posts (portal-theme-blocks-and-contributed-pages REQ-PTB-011,
// REQ-PTB-012).
//
// Usage: node --test tests/site-contribution.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	actionUrl,
	contributionRoute,
	entryRoute,
	isAnonymouslySubmittable,
	parseContributionRoute,
	resolveContributionPage,
	submitAction,
} from '../src/site/lib/contributionApi.js'

const BASE = '/index.php/apps/portaliq/portal/api'

// The shape the public contributions contract serves for a portal.
const PUBLIC_INTAKE = {
	id: 'publicIntake',
	type: 'create',
	label: 'Melding indienen',
	fields: ['title', 'status'],
	schema: 'exampleDocument',
	register: 'portaliq',
	anonymous: true,
}

const CONTRIBUTION = {
	app: 'portaliq',
	label: 'Meldingen',
	collections: [{ id: 'reports', label: 'Mijn meldingen' }],
	actions: [PUBLIC_INTAKE],
	pages: [
		{
			id: 'meldingen',
			label: 'Een melding doen',
			blocks: [
				{ type: 'richText', markdown: 'Meld het hier.' },
				{ type: 'action', action: 'publicIntake' },
			],
		},
	],
}

test('an anonymous create is submittable without a session', () => {
	assert.equal(isAnonymouslySubmittable(PUBLIC_INTAKE), true)
})

test('a create without the flag is not submittable without a session', () => {
	assert.equal(isAnonymouslySubmittable({ ...PUBLIC_INTAKE, anonymous: false }), false)
})

test('the flag on another type raises no form', () => {
	assert.equal(isAnonymouslySubmittable({ id: 'forward', type: 'endpoint', anonymous: true }), false)
	assert.equal(isAnonymouslySubmittable(null), false)
})

test('a create posts to its collection, not to the forward route', () => {
	assert.equal(
		actionUrl({ apiBase: BASE, appId: 'portaliq', action: PUBLIC_INTAKE }),
		`${BASE}/collections/portaliq/exampleDocument?actionId=publicIntake`,
	)
	assert.equal(
		actionUrl({ apiBase: `${BASE}/`, appId: 'portaliq', action: PUBLIC_INTAKE }),
		`${BASE}/collections/portaliq/exampleDocument?actionId=publicIntake`,
	)
})

test('a create without an id sends no action id', () => {
	assert.equal(
		actionUrl({ apiBase: BASE, appId: 'portaliq', action: { ...PUBLIC_INTAKE, id: '' } }),
		`${BASE}/collections/portaliq/exampleDocument`,
	)
})

test('every other type posts to the endpoint-forward route, ids encoded', () => {
	assert.equal(
		actionUrl({ apiBase: BASE, appId: 'openklant', action: { id: 'exampleForward', type: 'endpoint' } }),
		`${BASE}/actions/openklant/exampleForward`,
	)
	assert.equal(
		actionUrl({ apiBase: BASE, appId: 'a b', action: { id: '../../admin', type: 'endpoint' } }),
		`${BASE}/actions/a%20b/..%2F..%2Fadmin`,
	)
})

test('an anonymous submission sends one request with no Authorization header', async () => {
	const calls = []
	const original = globalThis.fetch
	globalThis.fetch = async (url, init) => {
		calls.push({ url, init })
		return { ok: true, json: async () => ({ id: 'x' }) }
	}
	try {
		const body = await submitAction({
			apiBase: BASE,
			appId: 'portaliq',
			action: PUBLIC_INTAKE,
			payload: { title: 'Lamp kapot' },
			token: '',
		})
		assert.deepEqual(body, { id: 'x' })
	} finally {
		globalThis.fetch = original
	}

	assert.equal(calls.length, 1)
	assert.equal(calls[0].url, `${BASE}/collections/portaliq/exampleDocument?actionId=publicIntake`)
	assert.equal(calls[0].init.method, 'POST')
	assert.equal('Authorization' in calls[0].init.headers, false)
	assert.deepEqual(JSON.parse(calls[0].init.body), { title: 'Lamp kapot' })
})

test('a signed-in submission carries the bearer, and a refusal throws its status', async () => {
	const original = globalThis.fetch
	let headers = null
	globalThis.fetch = async (url, init) => {
		headers = init.headers
		return { ok: false, status: 403, json: async () => ({}) }
	}
	try {
		await assert.rejects(
			submitAction({ apiBase: BASE, appId: 'portaliq', action: PUBLIC_INTAKE, payload: {}, token: 'abc' }),
			(error) => error.status === 403,
		)
	} finally {
		globalThis.fetch = original
	}
	assert.equal(headers.Authorization, 'Bearer abc')
})

test('a route round-trips through its parser; a CMS route is not contributed', () => {
	assert.deepEqual(parseContributionRoute(contributionRoute('portaliq', 'meldingen')), {
		appId: 'portaliq',
		pageId: 'meldingen',
	})
	assert.equal(parseContributionRoute('/begrippen'), null)
	assert.equal(parseContributionRoute('/diensten/portaliq'), null)
})

test('a contributed page resolves from the contributions already loaded', () => {
	const found = resolveContributionPage([CONTRIBUTION], '/diensten/portaliq/meldingen')
	assert.equal(found.page.id, 'meldingen')
	assert.equal(found.contribution, CONTRIBUTION)
})

test('an unknown app or page resolves to nothing, so the site shows not found', () => {
	assert.equal(resolveContributionPage([CONTRIBUTION], '/diensten/portaliq/onbekend'), null)
	assert.equal(resolveContributionPage([CONTRIBUTION], '/diensten/onbekend/meldingen'), null)
	assert.equal(resolveContributionPage(null, '/diensten/portaliq/meldingen'), null)
	assert.equal(resolveContributionPage([CONTRIBUTION], '/begrippen'), null)
})

test('an index entry links to the page that invokes its action', () => {
	assert.equal(entryRoute(CONTRIBUTION, PUBLIC_INTAKE), '/diensten/portaliq/meldingen')
	assert.equal(entryRoute(CONTRIBUTION, { id: 'meldingen' }), '/diensten/portaliq/meldingen')
})

test('an entry without a page stays text', () => {
	assert.equal(entryRoute(CONTRIBUTION, { id: 'reports', label: 'Mijn meldingen' }), null)
	assert.equal(entryRoute(CONTRIBUTION, { label: 'no id' }), null)
	assert.equal(entryRoute({ app: 'x' }, { id: 'publicIntake' }), null)
})
