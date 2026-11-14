#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// resident-menu-badges-names-and-marks.spec.mjs: which count each resident
// menu item carries (unread messages, open tasks, a page's counted rows), how
// items that share a name are told apart, and which item the menu marks for
// a route, the cases section for a cases page included
// (src/site/lib/residentMenu.js); pinned before these were split into named
// steps.
//
// Usage:
//   node --test tests/resident-menu-badges-names-and-marks.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { buildNav, routeForNav } from '../src/shared/portalNav.js'
import { menuCurrentRoute, residentMenuGroups } from '../src/site/lib/residentMenu.js'

const t = (key, vars = {}) => key.replace(/\{(\w+)\}/g, (_, name) => String(vars[name]))

const DOSSIQ = {
	app: 'dossiq',
	label: 'Dossiq',
	collections: [{ id: 'zaken', register: 'r', schema: 's', kind: 'cases' }],
	pages: [
		{ id: 'zaken', label: 'Berichten', blocks: [{ type: 'collection', collection: 'zaken' }] },
		{ id: 'zaak', label: 'Zaak', menu: false, record: { collection: 'zaken' }, blocks: [] },
	],
}
const LEARNIQ = {
	app: 'learniq',
	label: 'Learniq',
	collections: [{ id: 'taken', register: 'r', schema: 't' }, { id: 'kinderen', register: 'r', schema: 'k' }],
	pages: [
		{ id: 'taken', label: 'berichten', badge: { collection: 'taken', label: '{count} te doen' }, blocks: [{ type: 'collection', collection: 'taken' }] },
		{ id: 'meer', label: 'Meer', badge: { collection: 'taken' }, blocks: [] },
		{ id: 'kind', label: 'Berichten', records: { collection: 'kinderen' }, perRecord: 'kinderen', blocks: [] },
	],
}

/**
 * The menu's items with their names and counts.
 *
 * @param {object} menu The menu's state over `{t, hrefFor}`.
 * @param {object} enabled The sections switched on.
 * @return {Array<Array>} `[key, name, badge, badgeLabel]` per item.
 */
function itemsOf(menu, enabled = { tasks: true }) {
	const nav = buildNav([DOSSIQ, LEARNIQ], t, enabled)
	return residentMenuGroups(nav, { t, hrefFor: (route) => route, ...menu })
		.flatMap((group) => group.items)
		.map((item) => [item.key, item.name, item.badge, item.badgeLabel])
}

test('the inbox counts unread messages, tasks the open ones, a page its counted rows', () => {
	const items = itemsOf({ unread: 3, openTasks: 2, recordRows: { 'learniq:taken': [{ id: 1 }, { id: 2 }] } })
	const by = Object.fromEntries(items.map((item) => [item[0], item.slice(1)]))
	assert.deepEqual(by.__inbox__, ['Inbox', '3', '3 unread'])
	assert.deepEqual(by.__tasks__.slice(1), ['2', '2 open'])
	assert.deepEqual(by['learniq:taken'], ['berichten (Learniq)', '2', '2 te doen'])
	assert.deepEqual(by['learniq:meer'], ['Meer', '2', '2 open'], 'a badge without a label says open')
	assert.deepEqual(by['dossiq:zaken'], ['Berichten (Dossiq)', undefined, undefined])
})

test('no count without unread messages, open tasks or known rows', () => {
	for (const menu of [{}, { unread: 0, openTasks: 0 }, { unread: '0', openTasks: -1 }, { unread: 'x', openTasks: null, recordRows: {} }, { recordRows: { 'learniq:taken': [] } }]) {
		const counted = itemsOf(menu).filter((item) => item[2] !== undefined)
		assert.deepEqual(counted, [], JSON.stringify(menu))
	}
	assert.deepEqual(itemsOf({ unread: '2' }).find((item) => item[0] === '__inbox__').slice(2), ['2', '2 unread'])
})

test('names used by more than one app carry the app, whatever their case; per-record rows never count', () => {
	const names = itemsOf({ recordRows: { 'learniq:kinderen': [{ id: 'k1', givenName: 'Vera' }] } }).map((item) => item[1])
	assert.ok(names.includes('Berichten (Dossiq)'))
	assert.ok(names.includes('berichten (Learniq)'))
	const single = buildNav([DOSSIQ], t, {})
	const plain = residentMenuGroups(single, { t, hrefFor: (r) => r }).flatMap((group) => group.items)
	assert.ok(plain.some((item) => item.name === 'Berichten'), 'one app keeps the plain name')
	assert.ok(plain.every((item) => !('source' in item) && !('perRecord' in item)), 'the bookkeeping is taken off')
})

const NAV = buildNav([DOSSIQ, LEARNIQ], t, { cases: true })
const CASES = routeForNav(NAV.find((entry) => entry.special === 'cases'))
const ZAAK = routeForNav(NAV.find((entry) => entry.key === 'dossiq:zaak'))
const TAKEN = routeForNav(NAV.find((entry) => entry.key === 'learniq:taken'))
const CONTRIBUTIONS = { contributions: [DOSSIQ, LEARNIQ] }

test('the marked item is the route, else the deepest item above it', () => {
	const groups = [{ items: [{ link: '/mijn' }, { link: '/mijn/a' }, { link: '/mijn/a/b' }, { link: '' }, null] }, { items: 'x' }, null]
	assert.equal(menuCurrentRoute('/mijn/a', { groups }), '/mijn/a')
	assert.equal(menuCurrentRoute('/mijn/a/b/c', { groups }), '/mijn/a/b')
	assert.equal(menuCurrentRoute('/mijn/x', { groups }), '/mijn/x', 'the own area itself never marks a deeper page')
	assert.equal(menuCurrentRoute('', { groups }), '')
	assert.equal(menuCurrentRoute(null, { groups }), '')
	assert.equal(menuCurrentRoute(7, { groups }), 7)
	assert.equal(menuCurrentRoute('/elders', { groups: null }), '/elders')
})

test('a cases page or one of its records marks the cases section, when the menu holds it', () => {
	const groups = [{ items: [{ link: CASES }, { link: TAKEN }] }]
	const menu = { groups, nav: NAV, contributions: CONTRIBUTIONS }
	assert.equal(menuCurrentRoute(ZAAK, menu), CASES)
	assert.equal(menuCurrentRoute(`${ZAAK}/2026-1`, menu), CASES)
	assert.equal(menuCurrentRoute(`${TAKEN}/1`, menu), TAKEN, 'an item above it wins')
	assert.equal(menuCurrentRoute(ZAAK, { ...menu, groups: [{ items: [{ link: TAKEN }] }] }), ZAAK, 'no cases item, no mark')
	assert.equal(menuCurrentRoute(ZAAK, { ...menu, contributions: null }), ZAAK, 'no contributions, no cases page')
	assert.equal(menuCurrentRoute(ZAAK, { ...menu, contributions: { contributions: [{ ...DOSSIQ, collections: [{ id: 'zaken', kind: 'list' }] }] } }), ZAAK)
	assert.equal(menuCurrentRoute(ZAAK, { ...menu, contributions: { contributions: [{ ...DOSSIQ, app: 'other' }] } }), ZAAK)
	assert.equal(menuCurrentRoute(ZAAK, { ...menu, nav: buildNav([DOSSIQ], t, {}) }), ZAAK, 'no cases section in the navigation')
	assert.equal(menuCurrentRoute(ZAAK, { ...menu, nav: 'x' }), ZAAK)
	assert.equal(menuCurrentRoute('/mijn/onbekend', menu), '/mijn/onbekend')
	const listCases = { ...menu, contributions: { contributions: [{ ...DOSSIQ, pages: [] }] } }
	assert.equal(menuCurrentRoute(routeForNav(NAV.find((entry) => entry.key === 'dossiq:zaken')), listCases), CASES, 'a page whose block lists the cases')
	assert.equal(menuCurrentRoute(ZAAK, { ...menu, contributions: { contributions: [{ ...DOSSIQ, collections: 'x' }, null] } }), ZAAK)
})
