#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// page-layout-entry.spec.mjs — the layout designer is reachable from the UI.
//
// Usage:
//   node --test tests/page-layout-entry.spec.mjs
//
// WHY THIS TEST EXISTS. `PageLayout` shipped unreachable from the ADMIN app:
// the manifest carried the route and the component, and no button, tab or row
// action in the admin UI pointed at it, so an administrator working from the
// Pages list or a page's detail page had to type `/pages/<id>/layout` into the
// address bar (WOO-565). The one existing entry point was the PUBLIC site's
// floating editing control (`CmsEditorController::designerUrl` →
// `SiteEditButton`), which only helps a visitor already on the rendered site. Schema validation
// cannot catch that — an orphan page is structurally valid — so the invariant
// worth pinning is the JOIN: at least one action must target `PageLayout`,
// from the page detail AND from the pages list.
//
// The `open-page` dispatch is asserted too, because it is the half of this
// fix that is easy to "simplify" away. The library pushes `{ name: target }`
// with no params (src/utils/actionsDispatcher.js) and CnIndexPage pushes
// `{ name: target, params: { id: row.id } }`; the detail-page case works only
// because vue-router fills a named target's missing REQUIRED params from the
// current route. That inheritance holds only while both routes name the param
// identically, which is what `shares its :id param name with PageLayout`
// below is guarding.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'
import { pageSiteUrl } from '../src/lib/pageSiteUrl.js'

const REPO_ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const manifest = JSON.parse(
	readFileSync(join(REPO_ROOT, 'src', 'manifest.json'), 'utf8'),
)

/**
 * Find one page by its manifest id.
 *
 * @param {string} id The page id.
 * @return {object} The page entry.
 */
function page(id) {
	const found = manifest.pages.find((entry) => entry.id === id)
	assert.ok(found, `manifest declares no page "${id}"`)
	return found
}

/**
 * The `:param` names a route declares, in order.
 *
 * @param {string} route The manifest route pattern.
 * @return {Array<string>} The param names.
 */
function routeParams(route) {
	return [...route.matchAll(/:([A-Za-z][A-Za-z0-9_]*)/g)].map((m) => m[1])
}

describe('the layout designer', () => {
	it('is a page with an :id route and a component', () => {
		const layout = page('PageLayout')
		assert.equal(layout.route, '/pages/:id/layout')
		assert.equal(layout.type, 'custom')
		assert.equal(layout.component, 'PageLayoutDesigner')
	})

	it('is reached from the page detail by a header action', () => {
		const detail = page('PageDetail')
		const actions = detail.config?.headerActions ?? []
		const toLayout = actions.filter((a) => a.target === 'PageLayout')
		assert.equal(
			toLayout.length,
			1,
			'PageDetail must offer exactly one action opening PageLayout',
		)
		const [action] = toLayout
		assert.equal(action.type, 'open-page')
		assert.ok(action.id, 'the action needs an id')
		assert.ok(action.label, 'an action without a label renders as an empty row')
		// No `params`: see the module note. Restating `{ id: "{id}" }` here
		// would be the brace grammar of the ROW-action dispatch, which the
		// detail-page dispatcher does not run.
		assert.equal(action.params, undefined)
	})

	it('is reached from the pages list by a row action', () => {
		const index = page('Pages')
		const actions = index.config?.actions ?? []
		const toLayout = actions.filter((a) => a.target === 'PageLayout')
		assert.equal(
			toLayout.length,
			1,
			'the Pages index must offer exactly one row action opening PageLayout',
		)
		const [action] = toLayout
		assert.equal(action.type, 'open-page')
		assert.ok(action.id, 'the action needs an id')
		assert.ok(action.label, 'an action without a label renders as an empty row')
	})

	it('shares its :id param name with PageLayout, so the detail action resolves', () => {
		// vue-router copies a named target's missing required params off the
		// CURRENT route. Rename either param and the header action stops
		// resolving — silently, to the pages list.
		assert.deepEqual(routeParams(page('PageDetail').route), ['id'])
		assert.deepEqual(routeParams(page('PageLayout').route), ['id'])
	})
})

describe('the actions that open it', () => {
	it('name icons the app has registered', () => {
		// CnIcon renders NOTHING for an unregistered name — not a fallback
		// glyph — so an unregistered icon is an invisible action.
		const registered = new Set(
			[
				...readFileSync(join(REPO_ROOT, 'src', 'icons.js'), 'utf8').matchAll(
					/^import (\w+) from 'vue-material-design-icons\//gm,
				),
			].map((m) => m[1]),
		)
		const icons = [
			...(page('PageDetail').config?.headerActions ?? []),
			...(page('Pages').config?.actions ?? []),
		]
			.filter((a) => a.target === 'PageLayout')
			.map((a) => a.icon)

		assert.ok(icons.length > 0, 'expected the PageLayout actions to be found')
		for (const icon of icons) {
			assert.ok(icon, 'a PageLayout action declares no icon')
			assert.ok(
				registered.has(icon),
				`icon "${icon}" is not registered in src/icons.js`,
			)
		}
	})
})

describe('pageSiteUrl', () => {
	// The designer's way back to the site. `?route=` alone is not an address:
	// `PortalResolver::resolve()` consults an explicit slug FIRST and only
	// host-matches when none is named, so on any rig without a delegated
	// domain a portal-less link lands on the site's not-found page
	// (WOO-565, finding B21).
	const gen = (path) => `/index.php${path}`

	it('carries the page route and the portal slug', () => {
		assert.equal(
			pageSiteUrl({ route: '/over-ons', portal: 'open-tilburg' }, gen),
			'/index.php/apps/portaliq/site?route=%2Fover-ons&portal=open-tilburg',
		)
	})

	it('omits the portal when the page names none', () => {
		assert.equal(
			pageSiteUrl({ route: '/over-ons' }, gen),
			'/index.php/apps/portaliq/site?route=%2Fover-ons',
		)
		assert.equal(
			pageSiteUrl({ route: '/over-ons', portal: '   ' }, gen),
			'/index.php/apps/portaliq/site?route=%2Fover-ons',
		)
	})

	it('defaults a missing route to the portal front door', () => {
		assert.equal(
			pageSiteUrl({ portal: 'open-tilburg' }, gen),
			'/index.php/apps/portaliq/site?route=%2F&portal=open-tilburg',
		)
		assert.equal(
			pageSiteUrl(undefined, gen),
			'/index.php/apps/portaliq/site?route=%2F',
		)
	})

	it('encodes a slug or route that would otherwise split the query', () => {
		// A slug carrying `&` would silently address a different portal than
		// the page belongs to — or none at all.
		assert.equal(
			pageSiteUrl({ route: '/a b', portal: 'x&portal=other' }, gen),
			'/index.php/apps/portaliq/site?route=%2Fa%20b&portal=x%26portal%3Dother',
		)
	})
})
