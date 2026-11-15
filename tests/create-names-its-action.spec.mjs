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
import { mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

/**
 * Import the portal API adapter as an ES module. It imports nothing, so a
 * copy with an .mjs extension is all node needs.
 *
 * @return {Promise<object>} The module.
 */
async function loadPortalApi() {
	const dir = join(tmpdir(), `portaliq-create-names-its-action-${process.pid}`)
	mkdirSync(dir, { recursive: true })
	const out = join(dir, 'portalApi.mjs')
	const source = join(ROOT, 'src', 'shared', 'portalApi.js')
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
		return {
			ok: true,
			status: 200,
			json: async () => ({ object: { id: 'new' } }),
		}
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
	assert.equal(
		calls[0].url,
		'/apps/portaliq/portal/api/collections/pipelinq/ticket?actionId=createOwnComplaint',
	)
	assert.deepEqual(JSON.parse(calls[0].init.body), { title: 'Te laat' })
})

test('the action id is encoded, and an action without an id sends none', async () => {
	const calls = stubBrowser()
	const api = createPortalApi({ apiBase: '/apps/portaliq/portal/api' })

	await api.createObject({ id: 'a b&c', register: 'r', schema: 's' }, {})
	await api.createObject({ register: 'r', schema: 's' }, {})

	assert.equal(
		calls[0].url,
		'/apps/portaliq/portal/api/collections/r/s?actionId=a%20b%26c',
	)
	assert.equal(calls[1].url, '/apps/portaliq/portal/api/collections/r/s')
})

// The Vue site (/apps/portaliq/site). Its signed-in forms write through the
// same shared adapter as the portal (src/shared/portalApi.js, tested above).
// Its one create of its own is the landing-page form (FormBlock.vue): every
// active form is its own anonymous create action on `landingPageSubmission`
// (`submit-{formId}`), so the form names its action too.

/**
 * Stub the site's browser globals: a site config block, empty storage and a
 * recording fetch.
 *
 * @return {Array<object>} The recorded calls.
 */
function stubSite() {
	const calls = []
	const store = {
		getItem: () => null,
		setItem() {},
		removeItem() {},
	}
	globalThis.document = {
		referrer: '',
		getElementById: (id) =>
			id === 'portaliq-site-config'
				? {
						textContent: JSON.stringify({
							apiBase: '/apps/portaliq/api/content/site',
						}),
					}
				: null,
	}
	globalThis.window = {
		localStorage: store,
		sessionStorage: store,
		location: { origin: 'http://localhost', search: '', hash: '' },
	}
	globalThis.fetch = async (url, init = {}) => {
		calls.push({ url: String(url), init })
		return {
			ok: true,
			status: 200,
			json: async () => ({ object: { id: 'new' } }),
		}
	}
	return calls
}

// Vue reads `document` when it loads, so the block is loaded before the
// browser stub replaces it.
const { loadSfc } = await import('./support/render-sfc.mjs')
const formBlock = await loadSfc('src/site/components/FormBlock.vue')

/**
 * Submit FormBlock's form, with a stand-in `this`.
 *
 * @param {string} formId The bound form's id.
 * @return {Promise<object>} The stand-in, after the submit.
 */
async function submitFormBlock(formId) {
	const vm = {
		formId,
		portal: 'gemeente',
		fields: [],
		values: { email: 'a@example.nl' },
		errors: {},
		submitting: false,
		status: null,
	}
	await formBlock.methods.submit.call(vm)
	return vm
}

test('site: a landing-page form names its own create action', async () => {
	const calls = stubSite()

	const vm = await submitFormBlock('form-b')

	assert.equal(vm.status, 'success')
	assert.equal(calls.length, 1)
	assert.equal(calls[0].init.method, 'POST')
	assert.equal(
		calls[0].url,
		'http://localhost/apps/portaliq/portal/api/collections/portaliq/landingPageSubmission?actionId=submit-form-b',
	)
	assert.equal(JSON.parse(calls[0].init.body).email, 'a@example.nl')
})

test('site: a form without an id sends no action id', async () => {
	const calls = stubSite()

	await submitFormBlock('')

	assert.equal(
		calls[0].url,
		'http://localhost/apps/portaliq/portal/api/collections/portaliq/landingPageSubmission',
	)
})

test('site: no create in src/shared or src/site bypasses the action id', () => {
	// Every POST to `/collections/` in the site and the shared code must be
	// one of the two named-action creates above; a new one has to name its
	// action as well, and then be listed here.
	const allowed = new Set([
		'src/shared/portalApi.js',
		'src/site/lib/formSubmission.js',
	])
	const files = [
		...listFiles(join(ROOT, 'src', 'shared')),
		...listFiles(join(ROOT, 'src', 'site')),
	]
	const offenders = files
		.filter((file) => /\.(js|vue)$/.test(file))
		.filter((file) => {
			const source = readFileSync(file, 'utf8')
			return (
				/\/collections\//.test(source)
				&& /method:\s*'POST'|send\(\s*'POST'/.test(source)
			)
		})
		.map((file) => file.slice(ROOT.length + 1))
		.filter((file) => !allowed.has(file))
	assert.deepEqual(offenders, [])
})

/**
 * Every file below a directory.
 *
 * @param {string} dir The directory.
 * @return {Array<string>} The absolute paths.
 */
function listFiles(dir) {
	return readdirSync(dir, { withFileTypes: true }).flatMap((entry) =>
		entry.isDirectory()
			? listFiles(join(dir, entry.name))
			: [join(dir, entry.name)],
	)
}
