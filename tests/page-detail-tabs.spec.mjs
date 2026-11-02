#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// page-detail-tabs.spec.mjs: the Page detail's tab strip follows the
// portaliq/PtPaginaVerkeer and PtPaginaZoekmachines boards: Overzicht,
// Zoekmachines, Verkeer, Historie.
//
// Zoekmachines is a settings-form over the page's own search fields, so every
// key it writes must be a property of the page schema and every limit the
// schema sets must hold in the form before OpenRegister refuses the save.
// Verkeer holds the incoming and the outgoing flow side by side.
//
// Usage:
//   node --test tests/page-detail-tabs.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const PAGE = JSON.parse(read('lib/Settings/portaliq_register.json')).components.schemas.page.properties
const NL = JSON.parse(read('l10n/nl.json')).translations
const DETAIL = MANIFEST.pages.find((page) => page.id === 'PageDetail').config
const widget = (id) => DETAIL.widgets.find((w) => w.id === id)
const TABS = widget('page-tabs').content.tabs
const tab = (label) => TABS.find((entry) => entry.label === label)
const widgetsOf = (entry) => entry.widgetIds || [entry.widgetId]

test('the tabs follow the board order', () => {
	assert.deepEqual(TABS.map((entry) => entry.label), ['Overzicht', 'Zoekmachines', 'Verkeer', 'Historie'])
	for (const entry of TABS) {
		assert.ok(NL[entry.label], `tab "${entry.label}" is not in the Dutch catalogue`)
		for (const id of widgetsOf(entry)) {
			assert.ok(widget(id), `tab "${entry.label}" names an undeclared widget ${id}`)
		}
	}
})

test('Verkeer shows the incoming and the outgoing flow together', () => {
	const traffic = tab('Verkeer')
	const flows = ['page-traffic-incoming', 'page-traffic-outgoing']
	assert.deepEqual(widgetsOf(traffic).slice(-2), flows)
	assert.deepEqual(traffic.layout.filter((cell) => flows.includes(cell.widgetId)).map((cell) => cell.gridWidth), [6, 6])
	assert.equal(widget('page-traffic-incoming').content.direction, 'incoming')
	assert.equal(widget('page-traffic-outgoing').content.direction, 'outgoing')
})

test('Zoekmachines is a form over the page schema\'s search fields', () => {
	const form = widget(tab('Zoekmachines').widgetId)
	assert.equal(form.type, 'settings-form')
	const keys = form.content.fields.map((field) => field.key)
	assert.deepEqual(keys, ['seoTitle', 'seoDescription', 'seoImage', 'seoNoindex'])
	for (const field of form.content.fields) {
		assert.ok(PAGE[field.key], `the page schema has no "${field.key}"`)
		assert.ok(NL[field.label], `label "${field.label}" is not in the Dutch catalogue`)
		if (field.hint) {
			assert.ok(NL[field.hint], `hint "${field.hint}" is not in the Dutch catalogue`)
		}
		if (field.patternMessage) {
			assert.ok(NL[field.patternMessage], `"${field.patternMessage}" is not in the Dutch catalogue`)
		}
	}
	assert.ok(NL[form.content.saveLabel] && NL[form.content.savedText])
	assert.equal(form.content.fields.find((f) => f.key === 'seoNoindex').type, 'switch')
	assert.equal(form.content.fields.find((f) => f.key === 'seoDescription').type, 'textarea')
})

test('the form holds the schema\'s length limits', () => {
	const form = widget(tab('Zoekmachines').widgetId)
	for (const key of ['seoTitle', 'seoDescription']) {
		const field = form.content.fields.find((f) => f.key === key)
		const limit = PAGE[key].maxLength
		const re = new RegExp(field.pattern)
		assert.ok(re.test('a'.repeat(limit)), `${key} refuses ${limit} characters`)
		assert.ok(re.test('regel een\nregel twee'), `${key} refuses a line break`)
		assert.equal(re.test('a'.repeat(limit + 1)), false, `${key} accepts ${limit + 1} characters`)
	}
})

test('the share image is picked from this portal\'s media, or given as an address', () => {
	const field = widget(tab('Zoekmachines').widgetId).content.fields.find((f) => f.key === 'seoImage')
	assert.equal(field.type, 'reference')
	assert.equal(field.schema, 'media')
	assert.deepEqual(field.filter, { kind: 'image', status: 'published', portal: '@object.portal' })
	assert.equal(field.valueTemplate, 'media:{id}')
	const free = new RegExp(field.freePattern)
	for (const ok of ['https://www.example.nl/deel.jpg', 'http://example.nl/a.png']) assert.ok(free.test(ok), ok)
	for (const bad of ['www.example.nl/a.jpg', 'javascript:alert(1)', '']) assert.equal(free.test(bad), false, bad)
})
