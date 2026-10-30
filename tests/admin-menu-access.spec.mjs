#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// admin-menu-access.spec.mjs: the app menu shows a user only the pages their
// role may use (admin-menu-follows-roles). The predicates are read from the
// real manifest, and the menu filter is CnAppNav's own, so a teacher's menu
// here is the menu the app renders.
//
// Usage:
//   node --test tests/admin-menu-access.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { passesContextPredicates } from '../node_modules/@conduction/nextcloud-vue/src/utils/visibleIfContext.js'
import {
	entryAllowed,
	normaliseAccess,
	routeAllowed,
	withAccess,
} from '../src/lib/adminAccess.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const MANIFEST = JSON.parse(readFileSync(join(ROOT, 'src', 'manifest.json'), 'utf8'))

/**
 * The menu labels CnAppNav renders for a set of flags, using its own
 * predicate evaluator on the manifest this app hands it. `appInstalled` is
 * CnAppNav's other check; integriq counts as installed here.
 *
 * @param {object} raw The flags as the server hands them.
 * @return {Array<string>} The visible menu entry ids.
 */
function visibleMenu(raw) {
	const manifest = withAccess(MANIFEST, normaliseAccess(raw))
	// A caption is a section divider, not a page: only entries that lead somewhere count.
	return manifest.menu
		.filter(
			(item) =>
				item.type !== 'caption'
				&& (!item.visibleIf
				|| passesContextPredicates(item.visibleIf, manifest.runtime)),
		)
		.map((item) => item.id)
}

const NONE = {
	admin: false,
	pages: false,
	accounts: false,
	accessRequests: false,
	portals: false,
	portalSlugs: [],
}

test('a teacher sees the dashboard, News and the documentation, and no administration', () => {
	assert.deepEqual(visibleMenu(NONE), ['Dashboard', 'NewsMenu', 'Documentation'])
})

test('a missing answer gives no administration either', () => {
	assert.deepEqual(visibleMenu(null), ['Dashboard', 'NewsMenu', 'Documentation'])
	assert.deepEqual(normaliseAccess({ admin: 'yes', pages: 1 }), NONE)
})

test('each flag opens its own pages', () => {
	assert.deepEqual(visibleMenu({ ...NONE, pages: true, portals: true }), [
		'Dashboard',
		'Portals',
		'MediaMenu',
		'NoticesMenu',
		'NewsMenu',
		'Documentation',
	])
	assert.deepEqual(visibleMenu({ ...NONE, accounts: true }), [
		'Dashboard',
		'NewsMenu',
		'PortalAccounts',
		'InvitationsMenu',
		'Documentation',
	])
	assert.deepEqual(visibleMenu({ ...NONE, accessRequests: true }), [
		'Dashboard',
		'NewsMenu',
		'AccessRequestsMenu',
		'Documentation',
	])
})

test('an administrator sees every entry', () => {
	const all = {
		admin: true,
		pages: true,
		accounts: true,
		accessRequests: true,
		portals: true,
		portalSlugs: null,
	}
	assert.equal(
		visibleMenu(all).length,
		MANIFEST.menu.filter((item) => item.type !== 'caption').length,
	)
})

test('a page hidden from the menu does not open by its address', () => {
	assert.equal(routeAllowed(MANIFEST, 'Themes', NONE), false)
	assert.equal(routeAllowed(MANIFEST, 'PortalAccounts', NONE), false)
	assert.equal(routeAllowed(MANIFEST, 'News', NONE), true)
	assert.equal(routeAllowed(MANIFEST, 'Dashboard', NONE), true)
	assert.equal(routeAllowed(MANIFEST, 'Themes', { ...NONE, admin: true }), true)
	// A page no menu entry leads to is the server's to refuse.
	assert.equal(routeAllowed(MANIFEST, 'NoSuchPage', NONE), true)
})

test('only access predicates are read by the guard', () => {
	assert.equal(
		entryAllowed({ visibleIf: { appInstalled: 'integriq' } }, NONE),
		true,
	)
	assert.equal(
		entryAllowed(
			{ visibleIf: { appInstalled: 'integriq', 'access.admin': true } },
			NONE,
		),
		false,
	)
	assert.equal(entryAllowed({}, NONE), true)
})

// operate-portals-per-organisation T12: a member of a portal's admin group
// opens the Portals page, and the page lists only the portals they administer.
test('an admin group member sees Portals, limited to their own portals', () => {
	const access = normaliseAccess({ portals: true, portalSlugs: ['zeist'] })
	assert.deepEqual(visibleMenu(access), ['Dashboard', 'Portals', 'NewsMenu', 'Documentation'])
	const page = withAccess(MANIFEST, access).pages.find((p) => p.id === 'Portals')
	assert.deepEqual(page.config.filter, { slug: ['zeist'] })
	assert.equal(routeAllowed(MANIFEST, 'Portals', access), true)
	assert.equal(routeAllowed(MANIFEST, 'Media', access), false)
})

test('an editor or administrator sees every portal', () => {
	const access = normaliseAccess({ pages: true, portals: true, portalSlugs: null })
	assert.equal(access.portalSlugs, null)
	const page = withAccess(MANIFEST, access).pages.find((p) => p.id === 'Portals')
	assert.equal(page.config.filter, undefined)
})

test('a list of portals without the flag, or a malformed one, shows none', () => {
	assert.deepEqual(normaliseAccess({ portalSlugs: null }).portalSlugs, [])
	assert.deepEqual(normaliseAccess({ portals: true, portalSlugs: 'zeist' }).portalSlugs, [])
	assert.deepEqual(normaliseAccess({ portals: true, portalSlugs: ['zeist', '', 3] }).portalSlugs, ['zeist'])
})
