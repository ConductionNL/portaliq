// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// retire-the-vendored-woo-bundle, tasks 3 and 4: every route the vendored
// WOO bundle answers has a place once the bundle is gone. The route set is
// read out of the bundle's own build output, so a route the record forgets
// fails here instead of 404ing in silence.
//
//   - a public page lands on a page of the authored site (open-publicaties);
//   - sign-in, registration, reminder and authorisation go to the portal's
//     own auth edge, and the authored site reproduces none of them as a page;
//   - "Mijn omgeving" is the authenticated portal at /mijn;
//   - every form and administration route that is not carried over says why.
//
// Usage:
//   node --test tests/woo-bundle-routes.spec.mjs
//
// @spec openspec/changes/retire-the-vendored-woo-bundle/specs/portaliq-cms/spec.md#requirement-the-bundled-auth-and-forms-screens-must-not-be-reimplemented-as-content

import assert from 'node:assert/strict'
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { test } from 'node:test'

const root = new URL('../', import.meta.url)
const read = (path) => readFileSync(new URL(path, root), 'utf8')

const record = JSON.parse(read('openspec/changes/retire-the-vendored-woo-bundle/bundle-routes.json'))
const site = JSON.parse(read(`lib/Settings/sites/${record.site}.json`))
const pageRoutes = new Set(site.pages.map((page) => page.route))
const KINDS = new Set(['page', 'auth', 'own-area', 'action', 'out-of-scope'])
const ROUTE_PREFIXES = /^"(\/(?:forms|zoeken|publicatie|onderwerpen|gemma|login|register|reminder|authorization|mijn-omgeving|mijn-account|logout|beheer)[a-zA-Z0-9/:_-]*)"$/

/**
 * The route strings the bundle's JavaScript names, or null when the bundle is gone.
 *
 * @return {Set<string>|null} The routes.
 */
function bundleRoutes() {
	const dir = new URL('build/woo/static/js/', root)
	if (!existsSync(dir)) {
		return null
	}
	const routes = new Set(['/', '*'])
	for (const file of readdirSync(dir).filter((name) => name.endsWith('.js'))) {
		const text = readFileSync(new URL(file, dir), 'utf8')
		for (const literal of text.match(/"\/[a-zA-Z0-9/:_-]*"/g) || []) {
			const match = literal.match(ROUTE_PREFIXES)
			if (match) {
				routes.add(match[1])
			}
		}
	}
	return routes
}

test('every route the bundle answers is in the record, and the record names no other', () => {
	const recorded = record.routes.map((route) => route.path)
	assert.equal(new Set(recorded).size, recorded.length, 'a route is recorded twice')
	const served = bundleRoutes()
	if (served === null) {
		// After task 6 the record is what is left of the bundle: it must still hold the public surface.
		assert.ok(recorded.includes('/zoeken') && recorded.includes('/publicatie/:id'))
		return
	}
	assert.deepEqual([...served].filter((path) => !recorded.includes(path)).sort(), [], 'served by the bundle but not recorded')
	assert.deepEqual(recorded.filter((path) => !served.has(path)).sort(), [], 'recorded but not served by the bundle')
	assert.ok(served.size >= 40, `the bundle names ${served.size} routes; the extraction found too few to trust`)
})

test('each route has a known kind, and a page route lands on a page of the authored site', () => {
	for (const route of record.routes) {
		assert.ok(KINDS.has(route.kind), `${route.path}: unknown kind ${route.kind}`)
		if (route.kind === 'page' && route.to !== '*') {
			assert.ok(pageRoutes.has(route.to), `${route.path} -> ${route.to} is not a page of ${record.site}`)
		}
	}
	for (const surface of ['/', '/zoeken', '/publicatie', '/onderwerpen']) {
		assert.ok(record.routes.some((route) => route.kind === 'page' && route.to === surface), `nothing lands on ${surface}`)
	}
})

test('sign-in enters the portal auth edge, and no bundled auth screen is a page', () => {
	const auth = record.routes.filter((route) => route.kind === 'auth').map((route) => route.path)
	assert.deepEqual(auth.sort(), ['/authorization', '/login', '/logout', '/register', '/reminder'])
	for (const path of auth) {
		assert.ok(!pageRoutes.has(path), `${path} is reproduced as a page`)
	}
	const modes = site.portal.authentication.modes
	assert.ok(modes.includes('digid') && modes.includes('eherkenning'), 'the portal offers DigiD and eHerkenning')
})

test('"Mijn omgeving" is the authenticated portal, reachable from the menu', () => {
	for (const route of record.routes.filter((r) => r.kind === 'own-area')) {
		assert.equal(route.to, '/mijn', route.path)
	}
	const links = site.menus.flatMap((menu) => menu.items.map((item) => item.link))
	assert.ok(links.includes('/mijn'), 'the menu leads to the own area')
})

test('every form and administration route not carried over says why', () => {
	const forms = record.routes.filter((route) => route.path === '/forms' || route.path.startsWith('/forms/'))
	assert.ok(forms.length >= 10, 'the form routes are enumerated')
	for (const route of record.routes.filter((r) => r.kind === 'out-of-scope')) {
		assert.ok(typeof route.reason === 'string' && route.reason.length >= 20, `${route.path} has no reason`)
	}
	for (const route of forms) {
		assert.ok(route.kind === 'out-of-scope' || route.kind === 'action', `${route.path} is dropped in silence`)
	}
})
