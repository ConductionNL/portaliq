#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-details.spec.mjs: the "Gegevens van de portal" card is manifest config
// over nextcloud-vue's settings-form and chips-field widgets
// (portal-domains-and-editors REQ-PDE-004). The values below are the portal
// schema's, not the widgets': a wrong enum value is refused by OpenRegister.
//
// Usage:
//   node --test tests/portal-details.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const REGISTER = JSON.parse(read('lib/Settings/portaliq_register.json'))
const PORTAL = REGISTER.components.schemas.portal.properties
const DETAIL = MANIFEST.pages.find((page) => page.id === 'PortalDetail').config

const widget = (id) => DETAIL.widgets.find((w) => w.id === id)
const form = widget('portal-settings-details')
const field = (key) => form.content.fields.find((f) => f.key === key)

test('the details card is a settings-form over fields the portal schema has', () => {
	assert.equal(form.type, 'settings-form')
	for (const f of form.content.fields) {
		assert.ok(PORTAL[f.key], `the portal schema has no "${f.key}"`)
	}
	assert.equal(field('title').required, true)
})

test('the select options are the schema enums, not a placeholder', () => {
	assert.deepEqual(field('kind').options.map((o) => o.value).sort(), [...PORTAL.kind.enum].sort())
	assert.deepEqual(field('headerVariant').options.map((o) => o.value).sort(), [...PORTAL.headerVariant.enum].sort())
})

test('the address accepts lowercase words joined by dashes and nothing else', () => {
	const re = new RegExp(field('slug').pattern)
	for (const ok of ['zuiddrecht', 'open-tilburg', 'a1-b2']) assert.ok(re.test(ok), ok)
	for (const bad of ['Bad Slug', 'UPPER', '-lead', 'trail-', 'a--b', '']) assert.equal(re.test(bad), false, bad)
	assert.ok(field('slug').patternMessage)
})

test('languages and embedding origins are chips over the schema arrays', () => {
	const locales = widget('portal-settings-locales')
	assert.equal(locales.type, 'chips-field')
	assert.equal(PORTAL[locales.content.field].type, 'array')
	assert.deepEqual(locales.content.options.map((o) => o.value), ['nl', 'en', 'de', 'fr', 'fy'])
	const frame = widget('portal-settings-frame')
	assert.equal(frame.type, 'chips-field')
	assert.equal(PORTAL[frame.content.field].type, 'array')
	const origin = new RegExp(frame.content.pattern)
	for (const ok of ['https://www.example.nl', 'http://localhost:8080']) assert.ok(origin.test(ok), ok)
	for (const bad of ['www.example.nl', 'https://', 'ftp://x.nl', 'https://x.nl/path']) assert.equal(origin.test(bad), false, bad)
	assert.ok(frame.content.patternMessage)
})

test('every card is a section of the Instellingen widget, and the old component is gone', () => {
	const sections = widget('portal-settings').content.sections
	for (const id of ['portal-settings-details', 'portal-settings-locales', 'portal-settings-frame']) {
		assert.ok(sections.some((s) => s.widgetId === id), `${id} is not a section`)
	}
	assert.equal(read('src/registry.js').includes('PortalDetailsForm'), false)
})
