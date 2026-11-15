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
 * Load the worker into a sandbox with a cache that really keeps what it is
 * given, and a fetch the test chooses. The old sandbox stubbed fetch as
 * always OK and the cache as always empty, so it could never see the worker
 * reject a navigation when its fetch failed (the net::ERR_FAILED that every
 * returning visitor got on a custom_apps install).
 *
 * @param {object} [options] The sandbox.
 * @param {Function} [options.fetch] The worker's fetch; OK by default.
 * @param {Array<string>} [options.cached] URLs already in the cache.
 * @param {boolean} [options.onLine] What the browser reports; online by default.
 * @param {string} [options.workerUrl] The worker's own address; `/apps/portaliq/portal/sw.js` by default.
 * @return {Promise<{handler: Function, store: Map, fetches: Array<string>, self: object}>}
 */
async function loadWorker({
	fetch,
	cached = [],
	onLine = true,
	workerUrl = 'http://localhost/apps/portaliq/portal/sw.js',
} = {}) {
	const listeners = {}
	const self = {
		location: { href: workerUrl },
		navigator: { onLine },
		addEventListener: (type, fn) => {
			listeners[type] = fn
		},
		skipWaiting: () => {},
		clients: { claim: () => {} },
	}
	const store = new Map(cached.map((url) => [url, { cachedCopyOf: url }]))
	const cache = {
		match: async (request) => store.get(keyOf(request)),
		put: async (request, response) => {
			store.set(keyOf(request), response)
		},
		keys: async () => [...store.keys()].map((url) => ({ url })),
	}
	const caches = {
		open: async () => cache,
		keys: async () => [],
		delete: async () => true,
	}
	const fetches = []
	const workerFetch = async (request) => {
		fetches.push(request.url)
		return fetch
			? fetch(request)
			: {
					ok: true,
					type: 'basic',
					redirected: false,
					clone: () => ({ freshCopyOf: request.url }),
				}
	}
	vm.runInNewContext(SOURCE, { self, caches, fetch: workerFetch, URL, Promise })
	assert.equal(typeof listeners.fetch, 'function', 'the worker listens for fetch')
	// Let the start-up read of the cache finish.
	await new Promise((resolve) => setImmediate(resolve))
	return { handler: listeners.fetch, store, fetches, self }
}

/**
 * The cache key of a request or a string address, as the real Cache API
 * takes both.
 *
 * @param {Request|string} request The request or address.
 * @return {string} The address.
 */
function keyOf(request) {
	return typeof request === 'string' ? request : request.url
}

/**
 * Hand one request to the worker.
 *
 * @param {Function} handler The fetch listener.
 * @param {string} path The request path.
 * @param {string} method The request method.
 * @param {string} mode The request mode: 'navigate' for a page load.
 * @return {{responded: Promise|null, background: Promise|null}} What it did.
 */
function dispatch(handler, path, method = 'GET', mode = 'no-cors') {
	const out = { responded: null, background: null }
	handler({
		request: { url: `http://localhost${path}`, method, mode },
		respondWith: (promise) => {
			out.responded = promise
		},
		waitUntil: (promise) => {
			out.background = promise
		},
	})
	return out
}

/**
 * Whether the worker takes a request on at all: answers it from its cache,
 * or stores a copy of it in the background.
 *
 * @param {string} path The request path.
 * @param {string} method The request method.
 * @param {string} [workerUrl] The worker's own address.
 * @return {Promise<boolean>} True when the worker touched it.
 */
async function workerAnswers(path, method = 'GET', workerUrl = undefined) {
	const { handler } = await loadWorker({ workerUrl })
	const out = dispatch(handler, path, method)
	return out.responded !== null || out.background !== null
}

test('the site shell is cached: its bundle and its page', async () => {
	const worker = 'http://localhost/index.php/apps/portaliq/portal/sw.js'
	assert.equal(
		await workerAnswers(
			'/index.php/apps/portaliq/js/portaliq-site.js?v=123',
			'GET',
			worker,
		),
		true,
	)
	assert.equal(
		await workerAnswers('/index.php/apps/portaliq/site', 'GET', worker),
		true,
	)
	assert.equal(
		await workerAnswers(
			'/index.php/apps/portaliq/site?portal=wilgenboom&route=/nieuws',
			'GET',
			worker,
		),
		true,
	)
	assert.equal(await workerAnswers('/apps/portaliq/site'), true)
})

// The authenticated dashboard answers every path under the app's scope
// (dashboard#catchAll), so a path that only ends in "/site" is its HTML, not
// the site's page, and must never be kept for an offline load.
test('only the exact site page is cached, never another path that ends in /site', async () => {
	assert.equal(await workerAnswers('/apps/portaliq/beheer/site'), false)
	assert.equal(
		await workerAnswers('/apps/portaliq/portals/wilgenboom/site'),
		false,
	)
	assert.equal(
		await workerAnswers('/index.php/apps/portaliq/site'),
		false,
		'another route root',
	)
	assert.equal(await workerAnswers('/apps/portaliq/website'), false)
	assert.equal(await workerAnswers('/apps/portaliq/site/'), false)

	const { handler, store } = await loadWorker({ onLine: false })
	const out = dispatch(handler, '/apps/portaliq/beheer/site', 'GET', 'navigate')
	assert.equal(out.responded, null)
	assert.equal(out.background, null)
	assert.equal(store.size, 0)
})

test('a worker without an address of its own caches no page, only the bundle', async () => {
	const listeners = {}
	const self = {
		navigator: { onLine: true },
		addEventListener: (type, fn) => {
			listeners[type] = fn
		},
		skipWaiting: () => {},
		clients: { claim: () => {} },
	}
	const cache = {
		keys: async () => [],
		match: async () => undefined,
		put: async () => {},
	}
	const caches = {
		open: async () => cache,
		keys: async () => [],
		delete: async () => true,
	}
	vm.runInNewContext(SOURCE, {
		self,
		caches,
		fetch: async () => ({ ok: false }),
		URL,
		Promise,
	})
	const page = dispatch(listeners.fetch, '/apps/portaliq/site', 'GET', 'navigate')
	assert.equal(page.background, null)
	const bundle = dispatch(listeners.fetch, '/apps/portaliq/js/portaliq-site.js')
	assert.ok(bundle.background)
})

test('the retired portal address is not cached: it redirects to the site', async () => {
	assert.equal(await workerAnswers('/index.php/apps/portaliq/portal'), false)
	assert.equal(
		await workerAnswers('/index.php/apps/portaliq/portal?portal=wilgenboom'),
		false,
	)
})

test('the cache name moved on, so the old shell cache is deleted', () => {
	assert.match(
		readFileSync(join(ROOT, 'src', 'shared', 'serviceWorker.js'), 'utf8'),
		/const CACHE_VERSION = 'portaliq-shell-v6'/,
	)
})

// 🔴 THE ONE RULE. A cached authenticated answer could be served to the next
// person who opens the same address on that device.
test('the API is never answered by the worker, signed in or not', async () => {
	assert.equal(
		await workerAnswers('/index.php/apps/portaliq/portal/api/session'),
		false,
	)
	assert.equal(
		await workerAnswers(
			'/index.php/apps/portaliq/portal/api/site/js/portaliq-site.js',
		),
		false,
	)
	assert.equal(
		await workerAnswers('/index.php/apps/portaliq/api/content/site'),
		false,
	)
	assert.equal(
		await workerAnswers('/index.php/apps/portaliq/api/content/pages'),
		false,
	)
})

// A BROKEN WORKER MUST NEVER TAKE THE SITE DOWN. Under a blocked policy
// every fetch of the worker rejects. It must then leave the page to the
// browser, never hand it a rejected response.
test('when every fetch of the worker fails, it leaves the page to the browser', async () => {
	const { handler, store } = await loadWorker({
		fetch: async () => {
			throw new TypeError('Failed to fetch')
		},
	})
	const out = dispatch(handler, '/apps/portaliq/site?portal=wilgenboom')
	assert.equal(out.responded, null, 'no respondWith, so the browser loads /site')
	assert.ok(out.background, 'it only tries to keep a copy in the background')
	await assert.doesNotReject(out.background, 'the background copy never rejects')
	assert.equal(store.size, 0, 'nothing is cached')
	assert.equal(
		dispatch(handler, '/apps/portaliq/site?portal=wilgenboom').responded,
		null,
		'and the next visit is left to the browser too',
	)
})

// After a DigiD sign-in the callback sends the resident to
// /site?portal=…#token=<signed token>. With the worker active that load showed
// net::ERR_FAILED when the worker answered it itself. An online page load is
// the browser's, always, even when the worker holds a copy of the page.
test('an online page load is left to the browser, even when the worker holds the page', async () => {
	const url = 'http://localhost/apps/portaliq/site?portal=wilgenboom'
	const { handler, fetches } = await loadWorker({
		cached: [url],
		fetch: async () => {
			throw new TypeError('Failed to fetch')
		},
	})
	const out = dispatch(
		handler,
		'/apps/portaliq/site?portal=wilgenboom#token=eyJ.signed.token',
		'GET',
		'navigate',
	)
	assert.equal(out.responded, null, 'no respondWith: the browser loads the page')
	assert.ok(out.background, 'the worker only refreshes its copy')
	await assert.doesNotReject(out.background)
	assert.equal(fetches.length, 1)
})

test('offline, a page the worker holds is answered from its cache', async () => {
	const url = 'http://localhost/apps/portaliq/site?portal=wilgenboom'
	const { handler } = await loadWorker({
		cached: [url],
		onLine: false,
		fetch: async () => {
			throw new TypeError('Failed to fetch')
		},
	})
	const out = dispatch(
		handler,
		'/apps/portaliq/site?portal=wilgenboom#token=eyJ.signed.token',
		'GET',
		'navigate',
	)
	assert.ok(out.responded, 'answered by the worker')
	assert.deepEqual(await out.responded, { cachedCopyOf: url })
})

test('offline, a page the worker does not hold is left to the browser', async () => {
	const { handler } = await loadWorker({ onLine: false })
	const out = dispatch(
		handler,
		'/apps/portaliq/site?portal=wilgenboom',
		'GET',
		'navigate',
	)
	assert.equal(out.responded, null)
})

// 🔴 The sign-in token rides in the fragment. A fragment is never sent to the
// server, so it never belongs in a cache key, and this one is a credential.
test('the sign-in token in the address is never written to the cache', async () => {
	const { handler, store } = await loadWorker()
	const out = dispatch(
		handler,
		'/apps/portaliq/site?portal=wilgenboom#token=eyJ.signed.token',
		'GET',
		'navigate',
	)
	await out.background
	assert.deepEqual(
		[...store.keys()],
		['http://localhost/apps/portaliq/site?portal=wilgenboom'],
	)
	for (const key of store.keys()) {
		assert.ok(!key.includes('token'), key)
	}
})

test('a copy kept without its fragment answers the next offline load', async () => {
	const { handler, self } = await loadWorker()
	await dispatch(
		handler,
		'/apps/portaliq/site?portal=wilgenboom#token=eyJ.signed.token',
		'GET',
		'navigate',
	).background
	self.navigator.onLine = false
	const out = dispatch(
		handler,
		'/apps/portaliq/site?portal=wilgenboom',
		'GET',
		'navigate',
	)
	assert.ok(out.responded, 'the plain address finds the copy')
})

test('a response that followed a redirect, or is not plain same-origin, is never cached', async () => {
	for (const response of [
		{ ok: true, type: 'basic', redirected: true, clone: () => ({}) },
		{ ok: false, type: 'opaqueredirect', redirected: false, clone: () => ({}) },
		{ ok: true, type: 'opaque', redirected: false, clone: () => ({}) },
		{ ok: true, type: 'cors', redirected: false, clone: () => ({}) },
	]) {
		const { handler, store } = await loadWorker({ fetch: async () => response })
		await dispatch(
			handler,
			'/apps/portaliq/site?portal=wilgenboom',
			'GET',
			'navigate',
		).background
		assert.equal(store.size, 0, JSON.stringify(response))
	}
})

test('the cached bundle is answered from the cache, even when the network fails', async () => {
	const url = 'http://localhost/apps/portaliq/js/portaliq-site.js?v=9'
	const { handler } = await loadWorker({
		cached: [url],
		fetch: async () => {
			throw new TypeError('Failed to fetch')
		},
	})
	const out = dispatch(handler, '/apps/portaliq/js/portaliq-site.js?v=9')
	assert.ok(out.responded, 'answered by the worker')
	assert.deepEqual(await out.responded, { cachedCopyOf: url })
})

test('a first visit is fetched by the browser, the worker keeps a copy for the next one', async () => {
	const path = '/apps/portaliq/js/portaliq-site.js?v=9'
	const { handler, store, fetches } = await loadWorker()
	const first = dispatch(handler, path)
	assert.equal(first.responded, null)
	await first.background
	assert.equal(fetches.length, 1)
	assert.ok(store.has(`http://localhost${path}`))
	const second = dispatch(handler, path)
	assert.ok(second.responded, 'the next visit comes from the cache')
	assert.deepEqual(await second.responded, {
		freshCopyOf: `http://localhost${path}`,
	})
})

test('a copy the browser evicted is forgotten, so the next load skips the worker', async () => {
	const url = 'http://localhost/apps/portaliq/js/portaliq-site.js?v=9'
	const { handler, store } = await loadWorker({ cached: [url] })
	store.delete(url)
	const out = dispatch(handler, '/apps/portaliq/js/portaliq-site.js?v=9')
	assert.ok(out.responded)
	assert.equal((await out.responded).ok, true, 'fetched from the network')
	assert.equal(
		dispatch(handler, '/apps/portaliq/js/portaliq-site.js?v=9').responded,
		null,
		'the next load goes to the browser',
	)
})

test('only GET requests are cached', async () => {
	assert.equal(await workerAnswers('/index.php/apps/portaliq/site', 'POST'), false)
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

test('the scope the site registers is the scope the server allows, however the app is reached', () => {
	// PortalManifestController::serviceWorkerScope() answers
	// Service-Worker-Allowed with the worker's own request path minus
	// `portal/sw.js`. The browser refuses a registration whose scope is not
	// under that header, which is what happened for an app in custom_apps/
	// while the header named the app's file path (/custom_apps/portaliq/).
	const allowedFor = (workerUrl) =>
		workerUrl.endsWith('/portal/sw.js')
			? workerUrl.slice(0, -'portal/sw.js'.length)
			: null
	for (const base of [
		'/apps/portaliq/portal/api',
		'/index.php/apps/portaliq/portal/api',
		'/nextcloud/index.php/apps/portaliq/portal/api',
		'/nextcloud/apps/portaliq/portal/api/',
	]) {
		const address = serviceWorkerAddress(base)
		assert.equal(address.scope, allowedFor(address.url), base)
		assert.ok(!address.scope.includes('custom_apps'), base)
	}
	const controller = readFileSync(
		join(ROOT, 'lib', 'Controller', 'PortalManifestController.php'),
		'utf8',
	)
	assert.match(
		controller,
		/'Service-Worker-Allowed' => \$this->serviceWorkerScope\(\)/,
	)
	assert.match(controller, /\$suffix = 'portal\/sw\.js'/)
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
