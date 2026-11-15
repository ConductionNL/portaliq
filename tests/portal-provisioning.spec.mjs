#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-provisioning.spec.mjs: `New portal` on the Portals page provisions a
// draft portal through the admin route, shows the DNS record, and words a
// taken host or slug (operate-portals-per-organisation T07).
//
// Usage:
//   node --test tests/portal-provisioning.spec.mjs
//
// @spec openspec/changes/operate-portals-per-organisation/specs/portal-tenancy/spec.md

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	createPortalProvisioning,
	createPortalProvisioningHandlers,
	hostOf,
	missingForProvision,
	provisionBody,
	slugFromTitle,
} from '../src/lib/portalProvisioning.js'

const translate = (text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, name) => (name in vars ? vars[name] : match))
const generateUrl = (path) => `/index.php${path}`
const FIELDS = { organisation: ' org-b ', slug: 'Zeist', title: ' Gemeente Zeist ', host: 'https://Mijn.Zeist.example:443/home' }

/**
 * A provisioning over a recording POST that answers or rejects as given.
 *
 * @param {object|Error} answer The response data, or the rejection.
 * @return {{calls: Array, provisioning: object}}
 */
function recorded(answer) {
	const calls = []
	const post = async (url, body) => {
		calls.push({ url, body })
		if (answer instanceof Error) {
			throw answer
		}
		return { data: answer }
	}
	return { calls, provisioning: createPortalProvisioning({ post, generateUrl, translate }) }
}

/**
 * A rejection shaped like axios's.
 *
 * @param {number} status The HTTP status.
 * @param {string} error The error code.
 * @return {Error}
 */
function refused(status, error) {
	return Object.assign(new Error('refused'), { response: { status, data: { error } } })
}

test('the fields are cleaned the way the server checks them', () => {
	assert.equal(hostOf('https://Mijn.Zeist.example:8443/a?b'), 'mijn.zeist.example')
	assert.equal(hostOf(' portaal.nl '), 'portaal.nl')
	assert.deepEqual(provisionBody(FIELDS), { organisation: 'org-b', slug: 'zeist', title: 'Gemeente Zeist', host: 'mijn.zeist.example' })
	assert.deepEqual(provisionBody(null), { organisation: '', slug: '', title: '', host: '' })
	assert.equal(slugFromTitle('Gemeente Één & Zeist!'), 'gemeente-een-zeist')
})

test('what the dialog still lacks is said in field order', () => {
	assert.equal(missingForProvision(provisionBody({})), 'Give the organisation the portal belongs to.')
	assert.equal(missingForProvision(provisionBody({ organisation: 'o' })), 'Give the portal a title.')
	assert.equal(missingForProvision(provisionBody({ organisation: 'o', title: 'T', slug: 'a b' })), 'Give a slug of lower case letters, digits and hyphens, for example zeist.')
	assert.equal(missingForProvision(provisionBody({ organisation: 'o', title: 'T', slug: 'ab' })), 'Give the web address the portal answers on, for example mijn.zeist.nl.')
	assert.equal(missingForProvision(provisionBody(FIELDS)), '')
})

test('a complete dialog posts once and answers the DNS record', async () => {
	const { calls, provisioning } = recorded({ status: 'created', slug: 'zeist', dns: { name: '_portaliq.mijn.zeist.example', value: 'token' } })

	const outcome = await provisioning.provision(FIELDS)

	assert.deepEqual(calls, [{ url: '/index.php/apps/portaliq/api/portals/provision', body: { organisation: 'org-b', slug: 'zeist', title: 'Gemeente Zeist', host: 'mijn.zeist.example' } }])
	assert.deepEqual(outcome, {
		ok: true,
		message: 'The draft portal Gemeente Zeist is created. It is not published.',
		dns: { name: '_portaliq.mijn.zeist.example', value: 'token' },
	})
})

test('an incomplete dialog posts nothing', async () => {
	const { calls, provisioning } = recorded({})

	const outcome = await provisioning.provision({ ...FIELDS, host: '' })

	assert.equal(calls.length, 0)
	assert.equal(outcome.ok, false)
})

test('a taken host, a taken slug, a refused caller and a failure each read their own sentence', async () => {
	const cases = [
		[refused(409, 'host_taken'), 'This web address is already used by another portal.'],
		[refused(409, 'slug_taken'), 'This portal slug is already used by another portal.'],
		[refused(400, 'invalid'), 'Give the organisation, a title, a slug of lower case letters, digits and hyphens, and a web address without https:// or a port.'],
		[refused(503, 'unavailable'), 'OpenRegister is not available, so nothing was written.'],
		[refused(403, ''), 'Only an instance administrator can add a portal.'],
		[refused(500, 'failed'), 'The portal could not be written. Nothing else was created.'],
		[new Error('offline'), 'The portal could not be written. Nothing else was created.'],
	]
	for (const [error, message] of cases) {
		const { provisioning } = recorded(error)
		assert.deepEqual(await provisioning.provision(FIELDS), { ok: false, message })
	}
})

test('the handler reloads after a created portal and does nothing on cancel', async () => {
	const told = []
	let reloads = 0
	const provisioning = { provision: async () => ({ ok: true, message: 'made' }) }
	let answer = 'The draft portal Zeist is created. It is not published.'
	const handlers = createPortalProvisioningHandlers({
		provisioning,
		openDialog: async (submit) => {
			assert.deepEqual(await submit({}), { ok: true, message: 'made' })
			return answer
		},
		notify: (message) => told.push(message),
		reload: () => { reloads++ },
	})

	assert.equal(await handlers.newPortal(), true)
	assert.deepEqual(told, ['The draft portal Zeist is created. It is not published.'])
	assert.equal(reloads, 1)

	answer = null
	assert.equal(await handlers.newPortal(), false)
	assert.equal(reloads, 1)
})

test('the Portals page opens the dialog instead of the generic form', () => {
	const manifest = JSON.parse(readFileSync(new URL('../src/manifest.json', import.meta.url), 'utf8'))
	const config = manifest.pages.find((page) => page.id === 'Portals').config
	assert.equal(config.showAdd, false)
	assert.deepEqual(config.headerActions, [{ id: 'new-portal', label: 'New portal', icon: 'Plus', handler: 'newPortal' }])
	assert.ok(config.headerButtons.some((button) => button.action === 'new-portal' && button.variant === 'primary'))
	assert.ok(!config.headerButtons.some((button) => button.action === 'add'))
	const wiring = readFileSync(new URL('../src/customComponents.js', import.meta.url), 'utf8')
	assert.match(wiring, /\.\.\.portalProvisioningHandlers,/)
})

test('every sentence the dialog and its module show has en and nl', () => {
	const en = JSON.parse(readFileSync(new URL('../l10n/en.json', import.meta.url), 'utf8')).translations
	const nl = JSON.parse(readFileSync(new URL('../l10n/nl.json', import.meta.url), 'utf8')).translations
	const sources = ['../src/lib/portalProvisioning.js', '../src/dialogs/NewPortalDialog.vue']
		.map((path) => readFileSync(new URL(path, import.meta.url), 'utf8'))
		.join('\n')
	const keys = new Set()
	for (const match of sources.matchAll(/t\(\s*'portaliq',\s*'([^']+)'/g)) {
		keys.add(match[1])
	}
	// The refusal sentences: every quoted sentence in the module's tables.
	for (const match of sources.matchAll(/^\s+[a-z_]+:\s*\n?\s*'([^']+ [^']+)',?$/gm)) {
		keys.add(match[1])
	}
	for (const match of sources.matchAll(/return '([A-Z][^']+)'/g)) {
		keys.add(match[1])
	}
	assert.ok(keys.size > 20)
	for (const key of keys) {
		assert.ok(key in en, `en: ${key}`)
		assert.ok(key in nl && nl[key] !== '', `nl: ${key}`)
	}
})
