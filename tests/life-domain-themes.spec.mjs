#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// life-domain-themes.spec.mjs: a theme page gathers what contributions tagged
// with it, shows the products a resident holds with their validity, offers the
// actions whose rule holds, and writes an update through the contribution
// (life-domain-theme-pages REQ-LDT-001 to REQ-LDT-004).
//
// Usage:
//   node --test tests/life-domain-themes.spec.mjs
//
// @spec openspec/changes/life-domain-theme-pages/specs/portal-themes/spec.md

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { buildNav, shellSections } from '../src/shared/portalNav.js'
import { residentMenuGroups } from '../src/site/lib/residentMenu.js'
import {
	countLine,
	gather,
	offeredActions,
	productState,
	productView,
	rowActions,
	sortedProducts,
	themesToList,
	whenHolds,
} from '../src/site/pages/e/themes.js'
import { sitePageLoader } from '../src/site/pages/registry.js'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const t = (key, vars = {}) => key.replace(/\{(\w+)\}/g, (_, name) => String(vars[name] ?? ''))
const TODAY = '2026-10-08'
const PERMITS = {
	id: 'permits', register: 'dossiq', schema: 'permit', kind: 'products', theme: 'parkeren',
	titleField: 'name', validFromField: 'start', validUntilField: 'end', metaFields: ['plate', 'street'],
	countLabel: { singular: 'vergunning', plural: 'vergunningen' },
	fieldConfigs: { plate: { label: 'Kenteken' } },
}
const CONTRIBUTIONS = [
	{
		app: 'dossiq',
		collections: [
			PERMITS,
			{ id: 'parkingTasks', register: 'dossiq', schema: 'task', kind: 'tasks', theme: 'parkeren', dueField: 'due' },
			{ id: 'taxes', register: 'dossiq', schema: 'tax', theme: 'belasting' },
			{ id: 'plain', register: 'dossiq', schema: 'plain' },
		],
		actions: [
			{ id: 'plate', type: 'update', register: 'dossiq', schema: 'permit', theme: 'parkeren', fields: ['plate'], label: 'Kenteken wijzigen' },
			{ id: 'visitor', type: 'create', register: 'dossiq', schema: 'permit', theme: 'parkeren', label: 'Bezoekersuren kopen', when: { field: 'type', op: 'eq', value: 'bezoekers' } },
			{ id: 'unrelated', type: 'create', register: 'dossiq', schema: 'x' },
		],
	},
	{ app: 'wegiq', collections: [], actions: [{ id: 'report', type: 'create', register: 'wegiq', schema: 'r', theme: 'parkeren', label: 'Melding doen' }] },
]
const ROWS = [
	{ id: 'p1', name: 'Bewonersvergunning binnenstad', plate: 'GZ-482-K', street: 'Lindelaan 12', start: '2026-01-01', end: '2026-12-31', type: 'bewoners' },
	{ id: 'p2', name: 'Oude vergunning', plate: 'GZ-001-A', start: '2025-01-01', end: '2026-09-30', type: 'bewoners' },
	{ id: 'p3', name: 'Volgende vergunning', plate: 'GZ-777-B', start: '2026-11-01', end: '2027-10-31', type: 'bewoners' },
]

test('a theme gathers the collections and actions of every contribution tagged with it', () => {
	const parkeren = gather('parkeren', CONTRIBUTIONS)
	assert.deepEqual(parkeren.products.map((p) => [p.app, p.collection.id]), [['dossiq', 'permits']])
	assert.deepEqual(parkeren.tasks.map((p) => p.collection.id), ['parkingTasks'])
	assert.deepEqual(parkeren.actions.map((a) => [a.app, a.action.id]), [['dossiq', 'plate'], ['dossiq', 'visitor'], ['wegiq', 'report']], 'two apps on one page')
	assert.deepEqual(gather('inkomen', CONTRIBUTIONS), { tasks: [], products: [], actions: [] })
	assert.deepEqual(gather('parkeren', null), { tasks: [], products: [], actions: [] })
})

test('a product is valid, expired or not yet in effect by its dates', () => {
	assert.equal(productState(ROWS[0], PERMITS, TODAY), 'valid')
	assert.equal(productState(ROWS[1], PERMITS, TODAY), 'expired')
	assert.equal(productState(ROWS[2], PERMITS, TODAY), 'upcoming')
	assert.equal(productState({ name: 'x' }, PERMITS, TODAY), 'valid', 'no dates: valid')
	assert.equal(productState({ end: '2026-10-08' }, PERMITS, TODAY), 'valid', 'valid on its last day')
	assert.equal(productState({ end: '2026-10-08' }, { validUntilField: 'end' }, TODAY), 'valid')
	assert.equal(productState({ end: '2026-10-07' }, { validUntilField: 'end' }, '2026-10-08'), 'expired')
})

test('a product row reads as the board draws it', () => {
	const view = productView(ROWS[0], PERMITS, TODAY, (d) => `<${d}>`, t)
	assert.deepEqual(view, {
		id: 'p1',
		title: 'Bewonersvergunning binnenstad',
		state: 'valid',
		tag: 'Valid',
		meta: 'Kenteken GZ-482-K · Lindelaan 12 · in effect since <2026-01-01>',
		validUntil: 'Valid until <2026-12-31>',
	})
	const expired = productView(ROWS[1], PERMITS, TODAY, (d) => d, t)
	assert.equal(expired.tag, 'Expired')
	const upcoming = productView(ROWS[2], PERMITS, TODAY, (d) => d, t)
	assert.equal(upcoming.tag, 'Starts on 2026-11-01')
	assert.equal(upcoming.meta.includes('in effect since'), false, 'not in effect yet')
	assert.equal(productView({ name: 'x' }, PERMITS, TODAY, (d) => d, t).validUntil, '', 'no last day: left out')
})

test('the valid products come first, expired ones last, and the count line uses the collection\'s words', () => {
	assert.deepEqual(sortedProducts([ROWS[1], ROWS[2], ROWS[0]], PERMITS, TODAY).map((r) => r.id), ['p1', 'p3', 'p2'])
	assert.equal(countLine(1, PERMITS, t), '1 vergunning')
	assert.equal(countLine(2, PERMITS, t), '2 vergunningen')
	assert.equal(countLine(2, {}, t), '2 in total')
	assert.deepEqual(sortedProducts(null, PERMITS, TODAY), [])
})

test('what you can arrange follows rules on the products you hold', () => {
	const gathered = gather('parkeren', CONTRIBUTIONS)
	const held = [{ app: 'dossiq', collection: PERMITS, rows: [ROWS[0]] }]
	const offered = offeredActions(gathered.actions, held).map((a) => a.action.id)
	assert.deepEqual(offered, ['plate', 'report'], 'visitor hours need a visitor permit')
	const withVisitor = [{ app: 'dossiq', collection: PERMITS, rows: [ROWS[0], { id: 'v', type: 'bezoekers' }] }]
	assert.deepEqual(offeredActions(gathered.actions, withVisitor).map((a) => a.action.id), ['plate', 'visitor', 'report'])
	assert.deepEqual(offeredActions(gathered.actions, []).map((a) => a.action.id), ['plate', 'report'], 'a condition with no product does not hold')
	assert.equal(whenHolds(undefined, {}), true)
	assert.equal(whenHolds({ field: 'type', op: 'neq', value: 'a' }, { type: 'b' }), true)
	assert.equal(whenHolds({ field: 'type', op: 'in', value: ['a', 'b'] }, { type: 'b' }), true)
	assert.equal(whenHolds({ field: 'type', op: 'in', value: ['a'] }, { type: 'b' }), false)
	assert.equal(whenHolds({ field: 'type', op: 'like', value: 'a' }, { type: 'a' }), false, 'an operator nobody knows does not hold')
})

test('a product row offers the update actions of its own collection whose condition holds', () => {
	const gathered = gather('parkeren', CONTRIBUTIONS)
	const product = { app: 'dossiq', collection: PERMITS }
	assert.deepEqual(rowActions(ROWS[0], product, gathered.actions).map((a) => a.id), ['plate'])
	assert.deepEqual(rowActions(ROWS[0], { app: 'wegiq', collection: PERMITS }, gathered.actions), [], 'another app\'s actions are not offered')
})

test('the menu lists a theme only when the server announced it, in a group of its own', () => {
	const themes = [{ slug: 'parkeren', title: 'Parkeren', intro: 'Uw vergunningen.', productsLabel: 'parkeervergunningen' }]
	const session = { subjectRef: 'a' }
	const off = shellSections({ session, contributions: { contributions: [] }, threads: [], news: [] })
	assert.deepEqual(off.themes, [])
	assert.equal(buildNav([], t, off).some((e) => e.theme), false, 'an empty theme stays out of the menu')

	const on = shellSections({ session, contributions: { contributions: [], themes }, threads: [], news: [] })
	const nav = buildNav([], t, on)
	const entry = nav.find((e) => e.theme)
	assert.equal(entry.label, 'Parkeren')
	assert.equal(entry.special, 'thema/parkeren', 'the page lives at /mijn/thema/parkeren')

	const groups = residentMenuGroups(nav, t, 0, (route) => route)
	const group = groups.find((g) => g.key === 'themes')
	assert.equal(group.title, 'Themes')
	assert.deepEqual(group.items.map((i) => [i.name, i.link]), [['Parkeren', '/mijn/thema/parkeren']])
	assert.equal(residentMenuGroups(buildNav([], t, off), t, 0, (r) => r).some((g) => g.key === 'themes'), false)
	assert.deepEqual(themesToList(themes), [{ slug: 'parkeren', title: 'Parkeren' }])
	assert.deepEqual(themesToList(null), [])
	assert.equal(typeof sitePageLoader(entry), 'function', 'the theme entry opens the theme page')
})

test('the page lists its blocks and says so when a theme has nothing for the resident', async () => {
	const page = await loadSfc('src/site/pages/e/ThemePage.vue')
	const calls = []
	const api = {
		fetchCollection: async (collection) => {
			calls.push(collection.id)
			return collection.id === 'permits' ? ROWS : []
		},
	}
	const theme = { slug: 'parkeren', title: 'Parkeren', intro: 'Uw vergunningen.', productsLabel: 'parkeervergunningen' }
	const vm = {
		entry: { theme },
		contributions: { contributions: CONTRIBUTIONS },
		api,
		today: TODAY,
		rows: {},
		loading: true,
	}
	vm.theme = page.computed.theme.call(vm)
	vm.gathered = page.computed.gathered.call(vm)
	vm.keyOf = page.methods.keyOf
	await page.methods.load.call(vm)
	assert.deepEqual(calls.sort(), ['parkingTasks', 'permits'])
	assert.equal(vm.loading, false)
	vm.products = page.computed.products.call(vm)
	assert.equal(vm.products.length, 1)
	vm.locale = 'nl'
	vm.dateOf = page.methods.dateOf
	vm.tasks = page.computed.tasks.call(vm)
	assert.deepEqual(vm.tasks, [], 'tasks with no rows list nothing')
	vm.offered = page.computed.offered.call(vm)
	assert.deepEqual(vm.offered.map((a) => a.action.id), ['plate', 'report'])
	assert.equal(page.computed.isEmpty.call({ tasks: [], offered: [], products: [] }), true)
	assert.equal(page.computed.isEmpty.call(vm), false)

	vm.t = t
	vm.day = TODAY
	vm.showAll = false
	const shown = page.methods.shownProducts.call(vm, vm.products[0])
	assert.deepEqual(shown.map((s) => s.view.id), ['p1', 'p3', 'p2'])
	assert.deepEqual(shown[0].actions.map((a) => a.id), ['plate'])
	vm.showAll = true
	assert.equal(page.methods.hasMore.call(vm, vm.products[0]), true, 'an expired product makes the full list worth a link')
	assert.equal(page.methods.countLine.call(vm, vm.products[0]), '3 vergunningen')

	const html = await renderSfc('src/site/pages/e/ThemePage.vue', { entry: { theme }, contributions: { contributions: [] }, api, t })
	assert.match(html, /Uw vergunningen\./)
})

test('an update writes through the contribution and the products are read again', async () => {
	const form = await loadSfc('src/site/pages/e/ProductUpdateForm.vue')
	const sent = []
	const emitted = []
	const action = CONTRIBUTIONS[0].actions[0]
	const vm = {
		action,
		row: ROWS[0],
		fields: ['plate'],
		values: { plate: 'GZ-519-T' },
		problem: '',
		busy: false,
		api: { updateObject: async (...args) => { sent.push(args); return { ok: true, object: { ...ROWS[0], plate: 'GZ-519-T' } } } },
		$emit: (...a) => emitted.push(a),
	}
	await form.methods.save.call(vm)
	assert.deepEqual(sent, [[action, 'p1', { plate: 'GZ-519-T' }]])
	assert.equal(emitted[0][0], 'saved')
	vm.api.updateObject = async () => ({ ok: false })
	await form.methods.save.call(vm)
	assert.equal(vm.problem, 'That did not work. Try again later.')
	assert.equal(form.methods.labelOf.call({ action: { fieldConfigs: { plate: { label: 'Kenteken' } } } }, 'plate'), 'Kenteken')

	const page = await loadSfc('src/site/pages/e/ThemePage.vue')
	const product = { app: 'dossiq', collection: PERMITS }
	const pageVm = { editing: { id: 'p1' }, notice: '', rows: { 'dossiq:permits': [ROWS[0]] }, keyOf: page.methods.keyOf, api: { fetchCollection: async () => [{ ...ROWS[0], plate: 'GZ-519-T' }] } }
	await page.methods.saved.call(pageVm, product)
	assert.equal(pageVm.editing, null)
	assert.equal(pageVm.rows['dossiq:permits'][0].plate, 'GZ-519-T', 'the row shows the new value')
	assert.equal(pageVm.notice, 'Your change is saved.')
})
