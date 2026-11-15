#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// runtime-config.spec.mjs: where the site's runtime configuration comes
// from, in order (the standalone shell's block, Nextcloud's initial state, a
// plain global, nothing), and what a malformed source falls through to,
// pinned before runtimeConfig was split into one step per source.
//
// Usage:
//   node --test tests/runtime-config.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { runtimeConfig } from '../src/site/lib/contentApi.js'

/**
 * A browser with a shell block, an initial-state element and a global, each
 * optional.
 *
 * @param {object} parts `block` (the block's text), `state` (the element's value) and `global`.
 * @return {void}
 */
function browser({ block, state, global } = {}) {
	globalThis.document = {
		getElementById: (id) => (id === 'portaliq-site-config' && block !== undefined ? { textContent: block } : null),
		querySelector: (selector) => (selector === '#initial-state-portaliq-portalConfig' && state !== undefined ? { value: state } : null),
	}
	globalThis.window = global === undefined ? {} : { PORTALIQ_SITE_CONFIG: global }
}
const encoded = (value) => Buffer.from(JSON.stringify(value)).toString('base64')

test('the shell block comes first, then the initial state, then a global', () => {
	browser({ block: '{"apiBase":"/a"}', state: encoded({ apiBase: '/b' }), global: { apiBase: '/c' } })
	assert.deepEqual(runtimeConfig(), { apiBase: '/a' })
	browser({ state: encoded({ apiBase: '/b' }), global: { apiBase: '/c' } })
	assert.deepEqual(runtimeConfig(), { apiBase: '/b' })
	browser({ global: { apiBase: '/c' } })
	assert.deepEqual(runtimeConfig(), { apiBase: '/c' })
	browser()
	assert.deepEqual(runtimeConfig(), {})
})

test('a malformed, empty or non-object source falls through', () => {
	const errors = console.error
	console.error = () => {}
	try {
		browser({ block: '{broken', state: encoded({ apiBase: '/b' }) })
		assert.deepEqual(runtimeConfig(), { apiBase: '/b' })
		browser({ block: '', state: encoded({ apiBase: '/b' }) })
		assert.deepEqual(runtimeConfig(), { apiBase: '/b' })
		browser({ block: '"text"', state: encoded('text'), global: { apiBase: '/c' } })
		assert.deepEqual(runtimeConfig(), { apiBase: '/c' })
		browser({ block: 'null', state: 'not base64 json', global: { apiBase: '/c' } })
		assert.deepEqual(runtimeConfig(), { apiBase: '/c' })
		browser({ block: '[1]' })
		assert.deepEqual(runtimeConfig(), [1])
		browser({ state: encoded(null), global: null })
		assert.deepEqual(runtimeConfig(), {})
	} finally {
		console.error = errors
	}
})

test('outside a browser document only the global is asked', () => {
	delete globalThis.document
	globalThis.window = { PORTALIQ_SITE_CONFIG: { apiBase: '/c' } }
	assert.deepEqual(runtimeConfig(), { apiBase: '/c' })
})
