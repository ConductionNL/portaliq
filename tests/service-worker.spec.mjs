#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// service-worker.spec.mjs — the site's service worker and its registration
// (site-reaches-portal-parity REQ-SRP-045).
//
// Usage:
//   node --test tests/service-worker.spec.mjs
//
// Two halves:
//   - src/shared/serviceWorker.js, run in a sandbox with a fake `self`: it
//     caches the site's shell and NEVER the API (the one rule that must never
//     regress, design.md D-1 of parent-pwa-installability).
//   - src/site/lib/pwa.js: registration is fail-silent, so a browser that
//     refuses service workers still boots the site.

import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import vm from 'node:vm'
import {
	registerSiteServiceWorker,
	serviceWorkerAddress,
} from '../src/site/lib/pwa.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const SOURCE = readFileSync(join(ROOT, 'src', 'shared', 'serviceWorker.js'), 'utf8')
const AUTH_BASE = '/index.php/apps/portaliq/portal/api'

/**
 * Load the worker into a sandbox and return its fetch handler.
 *
 * @return {Function} The fetch listener.
 */
function workerFetchHandler() {
	const listeners = {}
	const self = {
		addEventListener: (type, fn) => {
			listeners[type] = fn
		},
		skipWaiting: () => {},
		clients: { claim: () => {} },
	}
	const caches = {
		open: async () => ({ match: async () => undefined, put: async () => {} }),
		keys: async () => [],
		delete: async () => true,
	}
	const fetch = async () => ({ ok: true, clone: () => ({}) })
	vm.runInNewContext(SOURCE, { self, caches, fetch, URL, Promise })
	assert.equal(typeof listeners.fetch, 'function', 'the worker listens for fetch')
	return listeners.fetch
}

/**
 * Whether the worker answers a request itself (from or into its cache).
 *
 * @param {string} path   The request path.
 * @param {string} method The request method.
 * @return {boolean} True when the worker called respondWith.
 */
function workerAnswers(path, method = 'GET') {
	const handler = workerFetchHandler()
	let answered = false
	handler({
		request: { url: `http://localhost${path}`, method },
		respondWith: () => {
			answered = true
		},
	})
	return answered
}

test('the site shell is cached: its bundle and its page', () => {
	assert.equal(
		workerAnswers('/index.php/apps/portaliq/js/portaliq-site.js?v=123'),
		true,
	)
	assert.equal(workerAnswers('/index.php/apps/portaliq/site'), true)
	assert.equal(
		workerAnswers(
			'/index.php/apps/portaliq/site?portal=wilgenboom&route=/nieuws',
		),
		true,
	)
})

test('the retired portal address is not cached: it redirects to the site', () => {
	assert.equal(workerAnswers('/index.php/apps/portaliq/portal'), false)
	assert.equal(
		workerAnswers('/index.php/apps/portaliq/portal?portal=wilgenboom'),
		false,
	)
})

test('the cache name moved on, so the old shell cache is deleted', () => {
	assert.match(
		readFileSync(join(ROOT, 'src', 'shared', 'serviceWorker.js'), 'utf8'),
		/const CACHE_VERSION = 'portaliq-shell-v3'/,
	)
})

// 🔴 THE ONE RULE. A cached authenticated answer could be served to the next
// person who opens the same address on that device.
test('the API is never answered by the worker, signed in or not', () => {
	assert.equal(workerAnswers('/index.php/apps/portaliq/portal/api/session'), false)
	assert.equal(
		workerAnswers(
			'/index.php/apps/portaliq/portal/api/site/js/portaliq-site.js',
		),
		false,
	)
	assert.equal(workerAnswers('/index.php/apps/portaliq/api/content/site'), false)
	assert.equal(workerAnswers('/index.php/apps/portaliq/api/content/pages'), false)
})

test('only GET requests are cached', () => {
	assert.equal(workerAnswers('/index.php/apps/portaliq/site', 'POST'), false)
})

test('the worker is served next to the auth edge and controls the whole app', () => {
	assert.deepEqual(serviceWorkerAddress(AUTH_BASE), {
		url: '/index.php/apps/portaliq/portal/sw.js',
		scope: '/index.php/apps/portaliq/',
	})
	assert.deepEqual(serviceWorkerAddress('/apps/portaliq/portal/api/'), {
		url: '/apps/portaliq/portal/sw.js',
		scope: '/apps/portaliq/',
	})
	assert.equal(serviceWorkerAddress(''), null)
	assert.equal(serviceWorkerAddress('/api/content'), null)
})

test('the site registers the worker with the app as its scope', async () => {
	const calls = []
	const registration = { scope: 'x' }
	const nav = {
		serviceWorker: {
			register: async (url, options) => {
				calls.push([url, options])
				return registration
			},
		},
	}

	assert.equal(await registerSiteServiceWorker(AUTH_BASE, { nav }), registration)
	assert.deepEqual(calls, [
		[
			'/index.php/apps/portaliq/portal/sw.js',
			{ scope: '/index.php/apps/portaliq/' },
		],
	])
})

// Scenario "Registration fails" (REQ-SRP-045): the site renders normally.
test('a browser that refuses service workers does not stop the site', async () => {
	assert.equal(await registerSiteServiceWorker(AUTH_BASE, { nav: {} }), null)
	assert.equal(await registerSiteServiceWorker(AUTH_BASE, { nav: null }), null)

	const refusing = {
		serviceWorker: {
			register: async () => {
				throw new Error('SecurityError')
			},
		},
	}
	assert.equal(await registerSiteServiceWorker(AUTH_BASE, { nav: refusing }), null)

	const throwing = {
		get serviceWorker() {
			throw new Error('denied')
		},
	}
	assert.equal(await registerSiteServiceWorker(AUTH_BASE, { nav: throwing }), null)
})

test('without an auth edge nothing is registered', async () => {
	let called = false
	const nav = {
		serviceWorker: {
			register: async () => {
				called = true
			},
		},
	}
	assert.equal(await registerSiteServiceWorker('', { nav }), null)
	assert.equal(called, false)
})

// THE RELEASE PACKAGE HAS NO src/. The shared release workflow excludes it,
// so an installed app could only serve the worker if the build also puts it
// where the package looks: js/, under a name the admin build's clean keeps.
const BUILT_NAME = 'portaliq-site-sw.js'

test('the site build copies the worker into js/, and the admin build keeps it', () => {
	const siteConfig = readFileSync(join(ROOT, 'webpack.site.js'), 'utf8')
	assert.match(
		siteConfig,
		/join\(\s*__dirname,\s*'src',\s*'shared',\s*'serviceWorker\.js',?\s*\)/,
	)
	assert.match(siteConfig, new RegExp(`'${BUILT_NAME.replace(/\./g, '\\.')}'`))

	const adminConfig = readFileSync(join(ROOT, 'webpack.config.js'), 'utf8')
	const keep = /keep: (\/.+\/),/.exec(adminConfig)
	assert.ok(keep, 'webpack.config.js declares output.clean.keep')
	// eslint-disable-next-line no-eval -- a regex literal read from the config
	assert.ok(eval(keep[1]).test(BUILT_NAME), `clean.keep keeps ${BUILT_NAME}`)
})

test('the controller serves the built copy when src/ is absent', () => {
	const controller = readFileSync(
		join(ROOT, 'lib', 'Controller', 'PortalManifestController.php'),
		'utf8',
	)
	assert.match(controller, /'\/src\/shared\/serviceWorker\.js'/)
	assert.match(controller, new RegExp(`'/js/${BUILT_NAME.replace(/\./g, '\\.')}'`))
})

test(
	'a site build carries the worker unchanged',
	{ skip: !existsSync(join(ROOT, 'js', BUILT_NAME)) && 'no site build in js/' },
	() => {
		assert.equal(readFileSync(join(ROOT, 'js', BUILT_NAME), 'utf8'), SOURCE)
	},
)
