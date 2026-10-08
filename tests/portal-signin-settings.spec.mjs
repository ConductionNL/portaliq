#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-signin-settings.spec.mjs: the Sign-in widget on a portal's page
// (signin-integriq-broker-login T11). It sends the routes and the broker
// settings, the secret only when one was typed, tells a refused broker route
// apart from a failed save, and is placed on the portal page.
//
// Usage:
//   node --test tests/portal-signin-settings.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	createPortalSigninSettings,
	saveBody,
} from '../src/lib/portalSigninSettings.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

/**
 * The settings api over a transport answering status and data.
 *
 * @param {number} status The status.
 * @param {object} data The body.
 * @param {Array} calls Records each call.
 * @return {object}
 */
function api(status, data, calls = []) {
	const answer = async (method, url, body) => {
		calls.push({ method, url, body })
		if (status >= 400) {
			const error = new Error('HTTP ' + status)
			error.response = { status, data }
			throw error
		}
		return { data }
	}
	return createPortalSigninSettings({
		get: (url) => answer('GET', url),
		put: (url, body) => answer('PUT', url, body),
		url: (path, params) =>
			'/apps/portaliq' + path.replace('{slug}', params.slug),
	})
}

test('a save sends the routes and the broker, and the secret only when typed', () => {
	const providers = [
		{ provider: 'digid', route: 'broker' },
		{ provider: 'eherkenning', route: 'oidc' },
	]
	const broker = {
		startUrl: ' https://i.example/start ',
		exchangeUrl: 'https://i.example/x',
		consumerId: 'pq',
	}

	assert.deepEqual(saveBody(providers, broker, ''), {
		routes: { digid: 'broker', eherkenning: 'oidc' },
		broker: {
			startUrl: 'https://i.example/start',
			exchangeUrl: 'https://i.example/x',
			consumerId: 'pq',
		},
	})
	assert.equal(saveBody(providers, broker, ' s3cret ').secret, 's3cret')
})

test('a broker route the server refuses is told apart from a failed save', async () => {
	const calls = []
	assert.deepEqual(
		await api(422, { error: 'broker_incomplete' }, calls).save('venray', {}),
		{ outcome: 'incomplete' },
	)
	assert.equal(calls[0].url, '/apps/portaliq/api/portals/venray/signin')
	assert.equal(calls[0].method, 'PUT')
	assert.deepEqual(await api(502, { error: 'save_failed' }).save('venray', {}), {
		outcome: 'failed',
	})
	assert.equal(
		(await api(200, { providers: [] }).save('venray', {})).outcome,
		'saved',
	)
	assert.equal((await api(404, {}).load('venray')).state, 'error')
})

test('the Sign-in widget sits on the portal page and warns that accounts do not carry over', () => {
	const manifest = JSON.parse(
		readFileSync(join(ROOT, 'src', 'manifest.json'), 'utf8'),
	)
	const page = manifest.pages.find((candidate) => candidate.id === 'PortalDetail')
	const widget = page.config.widgets.find(
		(candidate) => candidate.type === 'PortalSignin',
	)
	assert.ok(widget, 'PortalDetail declares a PortalSignin widget')
	assert.ok(
		page.config.layout.some((cell) => cell.widgetId === widget.id),
		'and places it in the layout',
	)
	assert.match(
		readFileSync(join(ROOT, 'src', 'registry.js'), 'utf8'),
		/PortalSignin: \{/,
	)
	assert.match(
		readFileSync(join(ROOT, 'src', 'widgets', 'PortalSignin.vue'), 'utf8'),
		/Accounts do not carry over between routes/,
	)
})
