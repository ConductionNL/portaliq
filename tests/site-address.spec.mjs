#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-address.spec.mjs: how a site page reads in the address bar
// (site-at-the-root-of-a-portal-host). Short addresses on a host the server
// declares as serving the site at its root, long ones anywhere else, and the
// shell wired to the helper rather than to a `?route=` of its own.
//
// Usage:
//   node --test tests/site-address.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	addressAfterMove,
	addressingOf,
	addressOfRoute,
	routeOfLocation,
} from '../src/site/lib/siteAddress.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

/** A host whose proxy rewrites short addresses to the site route. */
const AT_ROOT = {
	addressing: {
		root: true,
		siteRoute: '/index.php/apps/portaliq/site',
		landingRoute: '',
	},
}
/** The same, for the host of the signed-in area. */
const AT_ROOT_OWN_AREA = {
	addressing: {
		root: true,
		siteRoute: '/index.php/apps/portaliq/site',
		landingRoute: '/mijn',
	},
}
/** A host without such a rule: what every instance has served until now. */
const LONG = {
	addressing: {
		root: false,
		siteRoute: '/index.php/apps/portaliq/site',
		landingRoute: '',
	},
}

/**
 * The browser location of an address.
 *
 * @param {string} href The address.
 * @return {{pathname: string, search: string}} The location.
 */
function at(href) {
	const url = new URL(href)
	return { pathname: url.pathname, search: url.search }
}

test('a short address names its page by its path', () => {
	assert.equal(routeOfLocation(at('https://portal.example/'), AT_ROOT), '/')
	assert.equal(
		routeOfLocation(at('https://portal.example/afval'), AT_ROOT),
		'/afval',
	)
	assert.equal(
		routeOfLocation(at('https://portal.example/afval/grofvuil/'), AT_ROOT),
		'/afval/grofvuil',
	)
	assert.equal(
		routeOfLocation(at('https://portal.example/een%20pagina'), AT_ROOT),
		'/een pagina',
	)
})

test('`?route=` still names the page, on a short address too', () => {
	// Blocks that build their own `?route=` link keep working at the root.
	assert.equal(
		routeOfLocation(at('https://portal.example/afval?route=/contact'), AT_ROOT),
		'/contact',
	)
	assert.equal(
		routeOfLocation(at('https://portal.example/?route=contact'), AT_ROOT),
		'/contact',
	)
})

test('the bare address of a host opens its landing page', () => {
	assert.equal(
		routeOfLocation(at('https://mijn.example/'), AT_ROOT_OWN_AREA),
		'/mijn',
	)
	// A page that is named wins over the landing page.
	assert.equal(
		routeOfLocation(at('https://mijn.example/mijn/zaken'), AT_ROOT_OWN_AREA),
		'/mijn/zaken',
	)
	assert.equal(
		routeOfLocation(
			at('https://mijn.example/?route=/contact'),
			AT_ROOT_OWN_AREA,
		),
		'/contact',
	)
})

test('without the declaration a path names nothing, as before', () => {
	// The mutation this guards: reading the path on every host would turn
	// `/index.php/apps/portaliq/site` itself into a page name.
	assert.equal(
		routeOfLocation(
			at('https://cloud.example/index.php/apps/portaliq/site'),
			LONG,
		),
		'/',
	)
	assert.equal(
		routeOfLocation(
			at(
				'https://cloud.example/index.php/apps/portaliq/site?portal=a&route=/afval',
			),
			LONG,
		),
		'/afval',
	)
	assert.equal(
		routeOfLocation(at('https://elsewhere.example/some/page'), LONG),
		'/',
	)
	assert.equal(routeOfLocation(at('https://elsewhere.example/some/page'), {}), '/')
})

test('the long address with the page in its path names that page', () => {
	assert.equal(
		routeOfLocation(
			at('https://cloud.example/index.php/apps/portaliq/site/afval'),
			LONG,
		),
		'/afval',
	)
	assert.equal(
		routeOfLocation(
			at('https://cloud.example/apps/portaliq/site/afval/grofvuil'),
			LONG,
		),
		'/afval/grofvuil',
	)
	assert.equal(
		routeOfLocation(
			at('https://cloud.example/nextcloud/index.php/apps/portaliq/site/'),
			LONG,
		),
		'/',
	)
})

test('a page opened on the long address stays on long addresses', () => {
	// The host declares short addresses, but this page did not come through
	// the proxy rule: a short link from here would be a guess.
	const href = 'https://portal.example/index.php/apps/portaliq/site?route=/afval'
	assert.equal(addressingOf(AT_ROOT, new URL(href).pathname).root, false)
	assert.equal(routeOfLocation(at(href), AT_ROOT), '/afval')
	assert.equal(
		addressOfRoute('/contact', href, AT_ROOT).toString(),
		'https://portal.example/index.php/apps/portaliq/site?route=%2Fcontact',
	)
})

test('links are short addresses on a host that serves the site at its root', () => {
	const href = 'https://portal.example/afval?_search=x#deel'
	assert.equal(
		addressOfRoute('/contact', href, AT_ROOT).toString(),
		'https://portal.example/contact',
	)
	assert.equal(
		addressOfRoute('/', href, AT_ROOT).toString(),
		'https://portal.example/',
	)
	assert.equal(
		addressOfRoute('/afval/grof vuil', href, AT_ROOT).toString(),
		'https://portal.example/afval/grof%20vuil',
	)
	// The chosen language stays; nothing else does.
	assert.equal(
		addressOfRoute(
			'/contact',
			'https://portal.example/afval?lang=en&x=1',
			AT_ROOT,
		).toString(),
		'https://portal.example/contact?lang=en',
	)
})

test('links are long addresses anywhere else, exactly as before', () => {
	const href =
		'https://cloud.example/index.php/apps/portaliq/site?portal=demo&lang=en&route=/afval&x=1#deel'
	assert.equal(
		addressOfRoute('/contact', href, LONG).toString(),
		'https://cloud.example/index.php/apps/portaliq/site?portal=demo&lang=en&route=%2Fcontact',
	)
	assert.equal(
		addressOfRoute('/', href, LONG).toString(),
		'https://cloud.example/index.php/apps/portaliq/site?portal=demo&lang=en',
	)
	// From `/site/afval` a link starts at the site route again.
	assert.equal(
		addressOfRoute(
			'/contact',
			'https://cloud.example/index.php/apps/portaliq/site/afval',
			LONG,
		).toString(),
		'https://cloud.example/index.php/apps/portaliq/site?route=%2Fcontact',
	)
	assert.equal(
		addressOfRoute(
			'/',
			'https://cloud.example/index.php/apps/portaliq/site/afval',
			LONG,
		).toString(),
		'https://cloud.example/index.php/apps/portaliq/site',
	)
})

test('a move in place keeps the rest of the address', () => {
	assert.equal(
		addressAfterMove(
			'/contact',
			'https://portal.example/afval?lang=en&route=/oud',
			AT_ROOT,
		).toString(),
		'https://portal.example/contact?lang=en',
	)
	assert.equal(
		addressAfterMove(
			'/contact',
			'https://cloud.example/index.php/apps/portaliq/site?portal=demo&route=/afval',
			LONG,
		).toString(),
		'https://cloud.example/index.php/apps/portaliq/site?portal=demo&route=%2Fcontact',
	)
	// Home is written out on a long address, as the shell always did.
	assert.equal(
		addressAfterMove(
			'/',
			'https://cloud.example/index.php/apps/portaliq/site?route=/afval',
			LONG,
		).toString(),
		'https://cloud.example/index.php/apps/portaliq/site?route=%2F',
	)
})

test('a written address reads back as the route it was written for', () => {
	for (const config of [AT_ROOT, LONG]) {
		const start =
			config === AT_ROOT
				? 'https://portal.example/'
				: 'https://cloud.example/index.php/apps/portaliq/site?portal=demo'
		for (const route of [
			'/afval',
			'/afval/grof vuil',
			'/mijn/zaken/2026-001',
			'/a&b=c',
		]) {
			assert.equal(
				routeOfLocation(
					at(addressOfRoute(route, start, config).toString()),
					config,
				),
				route,
			)
			assert.equal(
				routeOfLocation(
					at(addressAfterMove(route, start, config).toString()),
					config,
				),
				route,
			)
		}
	}
})

test('the shell reads and writes its address through the helper', () => {
	// The wiring, from the caller: a helper nobody calls changes nothing.
	const shell = readFileSync(join(ROOT, 'src', 'site', 'App.vue'), 'utf8')
	assert.match(shell, /routeOfLocation\(window\.location, runtimeConfig\(\)\)/)
	assert.equal(
		(shell.match(/addressAfterMove\(/g) || []).length,
		2,
		'go() and replaceRoute()',
	)
	assert.equal(
		(shell.match(/addressOfRoute\(/g) || []).length,
		2,
		'hrefForRoute() and goSearch()',
	)
	assert.doesNotMatch(
		shell,
		/searchParams\.set\('route'/,
		'the shell builds no `?route=` of its own',
	)
})
