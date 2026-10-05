#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-home-page.spec.mjs: the "Home page" report on a portal's own page
// (portaliq-cms).
//
// Half of this file is about the report's LOGIC, and half about its WIRING,
// because a checker that nothing invokes passes its own tests and does
// nothing. The wiring half asserts, from the caller's side:
//
//   - the widget is PLACED on the Portal page's layout, not merely declared
//     in its widget list, and placed above the portal's own fields;
//   - its type resolves in the component registry;
//   - the address it reads is the address appinfo/routes.php declares;
//   - every sentence it shows is in the Dutch catalogue.
//
// A widget registered but never placed, or placed but reading an address no
// route answers, is invisible in exactly the way this report exists to
// prevent. Both are visible here, and in no browser.
//
// Usage:
//   node --test tests/portal-home-page.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	HOME_PAGE_STATES,
	homePageState,
	homePageTarget,
	homePageUrl,
	isConfigurationError,
	noteType,
	ROOT_ROUTE,
} from '../src/lib/portalHomePage.js'

/**
 * One repository file as text.
 *
 * @param {string} name The path relative to the repository root.
 * @return {string} The file's contents.
 */
function read(name) {
	return readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
}

const MANIFEST = JSON.parse(read('src/manifest.json'))
const REGISTRY = read('src/registry.js')
const WIDGET = read('src/widgets/PortalHomePage.vue')
const ROUTES = read('appinfo/routes.php')
const DUTCH = JSON.parse(read('l10n/nl.json')).translations

/** The Portal detail page of the admin manifest. */
const PORTAL_DETAIL = MANIFEST.pages.find((page) => page.id === 'PortalDetail')

test('the report reads the portal root, and the route is the one thing it never guesses', () => {
	assert.equal(ROOT_ROUTE, '/')
	assert.equal(
		homePageUrl('wilgenboom', (path) => path),
		'/apps/portaliq/api/portals/wilgenboom/home-page',
	)
	// A slug is compared verbatim when a portal is resolved, so it is sent
	// exactly as stored, escaped rather than trimmed.
	assert.equal(
		homePageUrl('a/b wilgen', (path) => path),
		'/apps/portaliq/api/portals/a%2Fb%20wilgen/home-page',
	)
})

test('an answer this app does not recognise is the worst state, never a pass', () => {
	assert.deepEqual(HOME_PAGE_STATES, ['missing', 'draft', 'published'])
	assert.equal(homePageState({ state: 'published' }), 'published')
	assert.equal(homePageState({ state: 'draft' }), 'draft')
	assert.equal(homePageState({ state: 'missing' }), 'missing')
	assert.equal(homePageState(null), 'missing')
	assert.equal(homePageState({}), 'missing')
	assert.equal(homePageState({ state: 'ok' }), 'missing')
})

test('an absent home page is an error, a draft a warning, a published one confirmed', () => {
	assert.equal(noteType('missing'), 'error')
	assert.equal(noteType('draft'), 'warning')
	assert.equal(noteType('published'), 'success')
	assert.equal(isConfigurationError('missing'), true)
	assert.equal(isConfigurationError('draft'), true)
	assert.equal(isConfigurationError('published'), false)
})

test('a draft at the root is opened, an absent one sends the admin to the pages', () => {
	assert.deepEqual(homePageTarget({ state: 'draft', pageId: 'page-1' }), {
		name: 'PageDetail',
		params: { id: 'page-1' },
	})
	assert.deepEqual(homePageTarget({ state: 'missing', pageId: null }), {
		name: 'Pages',
		params: {},
	})
	assert.equal(homePageTarget({ state: 'published', pageId: 'page-1' }), null)

	// Both names are manifest page ids, because the router uses a page id as
	// its route name. A name no page carries pushes nowhere.
	const ids = new Set(MANIFEST.pages.map((page) => page.id))
	assert.ok(ids.has('PageDetail'))
	assert.ok(ids.has('Pages'))
})

test('the report is PLACED on the portal page, above the portal fields', () => {
	const widget = PORTAL_DETAIL.config.widgets.find(
		(entry) => entry.type === 'PortalHomePage',
	)
	assert.ok(widget, 'the Portal page must declare the home-page report')
	assert.equal(widget.id, 'portal-home-page')
	assert.equal(widget.title, 'Home page')

	const placed = PORTAL_DETAIL.config.layout.filter(
		(entry) => entry.widgetId === widget.id,
	)
	assert.equal(
		placed.length,
		1,
		'a declared widget with no layout entry never renders: this is the defect this assertion exists for',
	)

	const fields = PORTAL_DETAIL.config.layout.find(
		(entry) => entry.widgetId === 'portal-data',
	)
	assert.ok(
		placed[0].gridY < fields.gridY,
		'a configuration error nobody scrolls to is not reported',
	)
	assert.equal(placed[0].gridX, 0)
	assert.equal(placed[0].gridWidth, 12)
})

test('no two widgets on the portal page claim the same row', () => {
	// The report was inserted between the KPI row and the portal's fields, so
	// every row below it moved. An overlap is what a missed shift looks like.
	const taken = new Map()
	for (const entry of PORTAL_DETAIL.config.layout) {
		for (let row = entry.gridY; row < entry.gridY + entry.gridHeight; row++) {
			for (let col = entry.gridX; col < entry.gridX + entry.gridWidth; col++) {
				const cell = `${row}:${col}`
				assert.equal(
					taken.has(cell),
					false,
					`${entry.widgetId} overlaps ${taken.get(cell)} at row ${row}`,
				)
				taken.set(cell, entry.widgetId)
			}
		}
	}
})

test('the widget type resolves in the component registry', () => {
	assert.match(REGISTRY, /import PortalHomePage from '\.\/widgets\/PortalHomePage\.vue'/)
	const entry = REGISTRY.slice(REGISTRY.indexOf('\tPortalHomePage: {'))
	assert.ok(entry.startsWith('\tPortalHomePage: {'), 'the registry must carry a PortalHomePage entry')
	const block = entry.slice(0, entry.indexOf('\n\t},'))
	assert.match(block, /kind: 'widget'/)
	assert.match(block, /component: PortalHomePage/)
	assert.match(block, /allowedSlots: \['body'\]/)
	assert.match(block, /_note:/)
	// hydra gate 29 wants the reason a custom widget exists at all.
	assert.match(REGISTRY, /@custom-widget-ratchet exclude[^\n]*\n\tPortalHomePage: \{/)
})

test('the address the widget reads is the address the app routes', () => {
	assert.match(WIDGET, /from '\.\.\/lib\/portalHomePage\.js'/)
	assert.match(WIDGET, /homePageUrl\(this\.slug, generateUrl\)/)

	const declared = ROUTES.match(
		/'name' => 'portalHomePage#index', 'url' => '([^']+)', 'verb' => 'GET'/,
	)
	assert.ok(declared, 'appinfo/routes.php must declare portalHomePage#index as a GET')
	assert.equal(
		homePageUrl('wilgenboom', (path) => path),
		`/apps/portaliq${declared[1].replace('{slug}', 'wilgenboom')}`,
	)
})

test('the widget shows a state, a consequence, a meaning and a remedy', () => {
	// The three note types come from the lib rather than from literals in the
	// template, so a state can never render with no severity at all.
	assert.match(WIDGET, /:type="type"/)
	assert.match(WIDGET, /data-testid="portal-home-page"/)
	assert.match(WIDGET, /`portal-home-page-\$\{state\}`/)
	assert.match(WIDGET, /data-testid="portal-home-page-open"/)
	for (const part of ['headline', 'consequence', 'meaning', 'remedy']) {
		assert.ok(WIDGET.includes(`{{ ${part} }}`) || WIDGET.includes(`"${part}"`), part)
	}
})

test('every sentence the report shows is in the Dutch catalogue', () => {
	const sentences = [
		'Home page',
		'{portal} has a published home page.',
		'{portal} has a home page, but it is still a draft.',
		'{portal} has no home page.',
		'A draft is not served, so the portal address says the page does not exist.',
		'Anyone who opens the portal address is told the page does not exist.',
		'A home page is a page of this portal with route / and status published.',
		'Publish {page} to put it live.',
		'Add a page with route /, then publish it.',
		'Open this page',
		'Open the pages of this portal',
		'The home page of this portal could not be checked.',
	]
	for (const sentence of sentences) {
		if (sentence !== 'Home page') {
			assert.ok(WIDGET.includes(sentence), `the widget must say: ${sentence}`)
		}

		assert.ok(DUTCH[sentence], `nl.json must translate: ${sentence}`)
		assert.ok(
			DUTCH[sentence].includes('—') === false,
			`no em-dash in the Dutch of: ${sentence}`,
		)
	}
})

test('the report says what to do, and the manifest title is its only English', () => {
	// The whole point is that an administrator can act. Each of the two errors
	// names a remedy, and neither of them is "it cannot be fixed".
	assert.match(WIDGET, /Add a page with route \/, then publish it\./)
	assert.match(WIDGET, /Publish \{page\} to put it live\./)
	// Nothing here saves, refuses or navigates away on its own: a portal is
	// configured before its pages exist, so the report must not block.
	assert.equal(/axios\.(put|post|delete)/.test(WIDGET), false)
})
