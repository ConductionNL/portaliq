#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// create-names-its-action.spec.mjs: a create sends the id of the action whose
// form was filled in, so the server writes through that action's whitelist
// and defaults. Two create actions on one schema (a request and a complaint
// both write `ticket`) used to fall to whichever was declared first.
//
// Usage:
//   node --test tests/create-names-its-action.spec.mjs

import assert from 'node:assert/strict'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

/**
 * Import the portal API adapter as an ES module. It imports nothing, so a
 * copy with an .mjs extension is all node needs. The site-parity work moves
 * it from src/portal/lib to src/shared; whichever exists is the one tested.
 *
 * @return {Promise<object>} The module.
 */
async function loadPortalApi() {
	const dir = join(tmpdir(), `portaliq-create-names-its-action-${process.pid}`)
	mkdirSync(dir, { recursive: true })
	const out = join(dir, 'portalApi.mjs')
	const shared = join(ROOT, 'src', 'shared', 'portalApi.js')
	const source = existsSync(shared) ? shared : join(ROOT, 'src', 'portal', 'lib', 'portalApi.js')
	writeFileSync(out, readFileSync(source, 'utf8'))
	return import(pathToFileURL(out).href)
}

const { createPortalApi } = await loadPortalApi()

/**
 * Stub the browser: a stored bearer and a recording fetch.
 *
 * @return {Array<object>} The recorded calls.
 */
function stubBrowser() {
	const calls = []
	globalThis.window = {
		localStorage: { getItem: () => 'token-1', setItem() {}, removeItem() {} },
		location: { origin: 'http://localhost' },
	}
	globalThis.fetch = async (url, init = {}) => {
		calls.push({ url: String(url), init })
		return { ok: true, status: 200, json: async () => ({ object: { id: 'new' } }) }
	}
	return calls
}

test('a create names the action whose form was filled in', async () => {
	const calls = stubBrowser()
	const api = createPortalApi({ apiBase: '/apps/portaliq/portal/api' })

	const result = await api.createObject(
		{ id: 'createOwnComplaint', register: 'pipelinq', schema: 'ticket' },
		{ title: 'Te laat' },
	)

	assert.equal(result.ok, true)
	assert.equal(calls.length, 1)
	assert.equal(calls[0].init.method, 'POST')
	assert.equal(calls[0].url, '/apps/portaliq/portal/api/collections/pipelinq/ticket?actionId=createOwnComplaint')
	assert.deepEqual(JSON.parse(calls[0].init.body), { title: 'Te laat' })
})

test('the action id is encoded, and an action without an id sends none', async () => {
	const calls = stubBrowser()
	const api = createPortalApi({ apiBase: '/apps/portaliq/portal/api' })

	await api.createObject({ id: 'a b&c', register: 'r', schema: 's' }, {})
	await api.createObject({ register: 'r', schema: 's' }, {})

	assert.equal(calls[0].url, '/apps/portaliq/portal/api/collections/r/s?actionId=a%20b%26c')
	assert.equal(calls[1].url, '/apps/portaliq/portal/api/collections/r/s')
})
