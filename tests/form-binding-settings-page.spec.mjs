#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// form-binding-settings-page.spec.mjs: one request form's settings page
// (portaliq/PtFormulierInstellingen) at /form-bindings/:id: tabs Algemeen,
// Bevestiging, Verklaringen, Mede-ondertekenen and Historie. Every field the
// forms write is a path of the portalFormBinding schema, and every choice is
// one of that schema's enum values, so nothing the page saves is refused or
// dropped by OpenRegister.
//
// Usage:
//   node --test tests/form-binding-settings-page.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const BINDING = JSON.parse(read('lib/Settings/portaliq_register.json')).components.schemas.portalFormBinding
const NL = JSON.parse(read('l10n/nl.json')).translations
const PAGE = MANIFEST.pages.find((page) => page.id === 'FormBindingDetail')
const widget = (id) => PAGE.config.widgets.find((w) => w.id === id)

/**
 * The schema fragment at a dot-path, or undefined.
 *
 * @param {string} path The dot-path.
 * @return {object|undefined}
 */
function propertyAt(path) {
	let node = BINDING
	for (const key of path.split('.')) {
		node = node?.properties?.[key]
	}
	return node
}

/**
 * Every settings-form widget the page shows, through tabs and sections.
 *
 * @return {Array<object>}
 */
function forms() {
	return PAGE.config.widgets.filter((w) => w.type === 'settings-form')
}

test('the page opens a binding from the Request forms list', () => {
	assert.ok(PAGE, 'no FormBindingDetail page')
	assert.equal(PAGE.route, '/form-bindings/:id')
	assert.equal(PAGE.type, 'detail')
	assert.equal(PAGE.config.register, 'portaliq')
	assert.equal(PAGE.config.schema, 'portalFormBinding')
	assert.deepEqual(PAGE.config.breadcrumb, { label: 'Request forms', route: 'FormBindings', currentField: 'route' })
})

test('the tabs follow the board, as far as the binding holds the settings', () => {
	const tabs = widget('binding-tabs').content.tabs
	assert.deepEqual(tabs.map((tab) => tab.label), ['Algemeen', 'Bevestiging', 'Verklaringen', 'Mede-ondertekenen', 'Historie'])
	for (const tab of tabs) {
		assert.ok(widget(tab.widgetId), `tab ${tab.label} names an undeclared widget`)
		assert.ok(NL[tab.label], `"${tab.label}" is not in the Dutch catalogue`)
	}
	const sections = widget('binding-general').content.sections.map((s) => s.title)
	assert.deepEqual(sections, ['Form', 'Sign in', 'Status'])
})

test('every field is a path of the binding schema, every choice one of its values', () => {
	assert.ok(forms().length >= 5)
	for (const form of forms()) {
		assert.ok(NL[form.content.saveLabel] && NL[form.content.savedText], form.id)
		for (const field of form.content.fields) {
			const prop = propertyAt(field.key)
			assert.ok(prop, `${form.id}: the binding has no "${field.key}"`)
			assert.ok(NL[field.label], `"${field.label}" is not in the Dutch catalogue`)
			if (field.hint) assert.ok(NL[field.hint], `"${field.hint}" is not in the Dutch catalogue`)
			if (field.type === 'select') {
				assert.deepEqual(field.options.map((o) => o.value).sort(), [...prop.enum].sort(), field.key)
				for (const option of field.options) assert.ok(NL[option.label], `"${option.label}" is not in the Dutch catalogue`)
			}
			if (field.type === 'switch') assert.equal(prop.type, 'boolean', field.key)
			if (field.type === 'number') assert.equal(prop.type, 'integer', field.key)
		}
	}
})
