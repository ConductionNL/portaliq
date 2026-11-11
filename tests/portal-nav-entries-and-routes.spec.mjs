#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-nav-entries-and-routes.spec.mjs: the navigation entries of the
// contributed pages and of the collections the login is too low for, and the
// route of each entry (src/shared/portalNav.js); pinned on every branch
// before these were split into named steps.
//
// Usage:
//   node --test tests/portal-nav-entries-and-routes.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { buildNav, routeForNav, stepUpEntries } from '../src/shared/portalNav.js'

const t = (key) => key

test('every contribution\'s pages become entries tagged with their contribution', () => {
	const a = { app: 'dossiq', pages: [{ id: 'zaken', label: 'Mijn zaken', icon: 'Folder' }, { id: 'kaal' }] }
	const b = { app: 'learniq' }
	const nav = buildNav([a, b, { app: 'x', pages: [] }], t, {})
	assert.deepEqual(nav.slice(0, 2), [
		{ key: 'dossiq:zaken', label: 'Mijn zaken', icon: 'Folder', page: a.pages[0], contribution: a },
		{ key: 'dossiq:kaal', label: 'kaal', icon: undefined, page: a.pages[1], contribution: a },
	])
	assert.equal(nav[2].special, 'inbox')
	assert.deepEqual(buildNav(null, t, {}), [])
	assert.deepEqual(buildNav([{ app: 'a', pages: [{ id: 'p', label: '' }] }], t, {})[0].label, 'p')
})

test('a collection the login is too low for gets one entry at its route, unless a page holds it', () => {
	const nav = [{ key: 'dossiq:zaken' }]
	const stepUp = [
		{ app: 'dossiq', collection: 'zaken', label: 'Zaken', minTrust: 'high' },
		{ app: 'dossiq', collection: 'stukken', label: 'Stukken', minTrust: 'high' },
		{ app: 'dossiq', collection: 'stukken', label: 'Dubbel' },
		{ app: '', collection: 'x' },
		{ app: 'a' },
		null,
	]
	const entries = stepUpEntries(stepUp, nav)
	assert.deepEqual(entries.map((e) => [e.key, e.label]), [['dossiq:stukken', 'Stukken']])
	assert.deepEqual(entries[0], { key: 'dossiq:stukken', label: 'Stukken', icon: 'Lock', stepUp: { app: 'dossiq', collection: 'stukken', minTrust: 'high' } })
	assert.deepEqual(stepUpEntries(stepUp).map((e) => e.key), ['dossiq:zaken', 'dossiq:stukken'])
	assert.deepEqual(stepUpEntries(stepUp, null).map((e) => e.key), ['dossiq:zaken', 'dossiq:stukken'])
	assert.deepEqual(stepUpEntries('x', nav), [])
	assert.deepEqual(stepUpEntries(null), [])
})

test('an entry\'s route: a section under the own area, else the app and the page', () => {
	assert.equal(routeForNav({ special: 'inbox' }), '/mijn/inbox')
	assert.equal(routeForNav({ special: 'thema/wonen', page: { id: 'p' } }), '/mijn/thema/wonen')
	assert.equal(routeForNav({ contribution: { app: 'dossiq' }, page: { id: 'zaken' } }), '/mijn/dossiq/zaken')
	assert.equal(routeForNav({ contribution: { app: 'a b' }, page: { id: 'c/d' } }), '/mijn/a%20b/c%2Fd')
	assert.equal(routeForNav({ stepUp: { app: 'dossiq', collection: 'stukken' } }), '/mijn/dossiq/stukken')
	assert.equal(routeForNav({ contribution: { app: '' }, page: { id: '' }, stepUp: { app: 's', collection: 'c' } }), '/mijn/s/c')
	assert.equal(routeForNav({}), '/mijn//')
	assert.equal(routeForNav({ special: '' }), '/mijn//')
})
