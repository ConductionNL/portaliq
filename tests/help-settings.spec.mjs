#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// help-settings.spec.mjs: where an administrator edits the help details
// (help-texts-and-form-help T04). The portal's Instellingen tab carries a
// "Help on forms" section for `portal.help` and a "Help per part of Mijn
// omgeving" section for `portal.sectionHelp`; a page has a Hulp tab for
// `page.helpText`. Every field writes a path the register schema holds, so
// nothing the form saves is refused or dropped, and every visible text is in
// the Dutch catalogue.
//
// Usage:
//   node --test tests/help-settings.spec.mjs
//
// @spec openspec/changes/help-texts-and-form-help/tasks.md

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const SCHEMAS = JSON.parse(read('lib/Settings/portaliq_register.json')).components.schemas
const NL = JSON.parse(read('l10n/nl.json')).translations

const page = (id) => MANIFEST.pages.find((p) => p.id === id)
const widget = (pageId, id) => page(pageId).config.widgets.find((w) => w.id === id)

/**
 * The schema fragment at a dot-path, or undefined.
 *
 * @param {object} schema The schema.
 * @param {string} path The dot-path.
 * @return {object|undefined}
 */
function propertyAt(schema, path) {
	let node = schema
	for (const key of path.split('.')) {
		node = node?.properties?.[key]
	}
	return node
}

/**
 * Assert a settings form writes only paths of the schema, as strings, and
 * that its visible texts are translated.
 *
 * @param {object} form The settings-form widget.
 * @param {object} schema The schema it saves into.
 * @return {void}
 */
function assertWritesTheSchema(form, schema) {
	assert.equal(form.type, 'settings-form', form.id)
	assert.ok(NL[form.title], `"${form.title}" is not in the Dutch catalogue`)
	assert.ok(NL[form.content.saveLabel] && NL[form.content.savedText], form.id)
	for (const field of form.content.fields) {
		const prop = propertyAt(schema, field.key)
		assert.ok(prop, `${form.id}: the schema has no "${field.key}"`)
		assert.equal(prop.type, 'string', `${form.id}: "${field.key}" is not a text`)
		for (const key of ['label', 'hint', 'patternMessage', 'emptyLabel', 'freeLabel', 'freePatternMessage']) {
			if (field[key]) {
				assert.ok(NL[field[key]], `"${field[key]}" is not in the Dutch catalogue`)
			}
		}
	}
}

test('the portal settings carry a section for the form help details', () => {
	const sections = widget('PortalDetail', 'portal-settings').content.sections
	const help = sections.find((s) => s.key === 'help')
	assert.ok(help, 'no help section on the portal settings')
	assert.equal(help.widgetId, 'portal-settings-help')
	assert.ok(NL[help.title], help.title)

	const form = widget('PortalDetail', 'portal-settings-help')
	assertWritesTheSchema(form, SCHEMAS.portal)
	assert.deepEqual(
		form.content.fields.map((f) => f.key).sort(),
		Object.keys(SCHEMAS.portal.properties.help.properties).map((k) => `help.${k}`).sort(),
		'every help detail the dialog shows can be set, and nothing else',
	)
})

test('the e-mail field refuses what is not an address, and an empty one is allowed', () => {
	const email = widget('PortalDetail', 'portal-settings-help').content.fields.find((f) => f.key === 'help.email')
	const pattern = new RegExp(email.pattern)
	assert.ok(pattern.test(''))
	assert.ok(pattern.test('info@zuiddrecht.nl'))
	assert.ok(!pattern.test('info at zuiddrecht'))
	assert.ok(!pattern.test('mailto:info@zuiddrecht.nl '))
})

test('the help image is a media path of this portal or an https address', () => {
	const image = widget('PortalDetail', 'portal-settings-help').content.fields.find((f) => f.key === 'help.image')
	assert.equal(image.type, 'reference')
	assert.deepEqual(image.filter, { kind: 'image', status: 'published', portal: '@object.slug' })
	const free = new RegExp(image.freePattern)
	assert.ok(free.test('/apps/portaliq/api/content/media/7?portal=zuiddrecht'))
	assert.ok(free.test('https://www.zuiddrecht.nl/hulp.jpg'))
	assert.ok(!free.test('http://www.zuiddrecht.nl/hulp.jpg'), 'a plain http image would be mixed content')
	assert.ok(!free.test('//evil.example/x.jpg'))
})

test('the portal settings carry a help text for each part of Mijn omgeving', () => {
	const sections = widget('PortalDetail', 'portal-settings').content.sections
	const sectionHelp = sections.find((s) => s.key === 'section-help')
	assert.ok(sectionHelp, 'no section-help section on the portal settings')
	assert.equal(sectionHelp.widgetId, 'portal-settings-section-help')
	assert.ok(sections.indexOf(sectionHelp) > sections.findIndex((s) => s.key === 'help'))

	const form = widget('PortalDetail', 'portal-settings-section-help')
	assertWritesTheSchema(form, SCHEMAS.portal)
	assert.deepEqual(
		form.content.fields.map((f) => f.key),
		['overview', 'cases', 'tasks', 'messages', 'contacts'].map((k) => `sectionHelp.${k}`),
	)
	for (const field of form.content.fields) {
		assert.equal(field.type, 'textarea', field.key)
	}
})

test('a page has a Hulp tab that edits its help text', () => {
	const tabs = widget('PageDetail', 'page-tabs').content.tabs
	const tab = tabs.find((t) => t.widgetId === 'page-help')
	assert.ok(tab, 'no Hulp tab on the page')
	assert.equal(tab.label, 'Hulp')
	assert.ok(NL[tab.label])
	assert.equal(tabs.indexOf(tab), tabs.findIndex((t) => t.widgetId === 'page-search') + 1)

	const form = widget('PageDetail', 'page-help')
	assertWritesTheSchema(form, SCHEMAS.page)
	assert.deepEqual(form.content.fields.map((f) => f.key), ['helpText'])
	assert.equal(form.content.fields[0].type, 'textarea')
})

test('every section and tab names a declared widget', () => {
	for (const section of widget('PortalDetail', 'portal-settings').content.sections) {
		assert.ok(widget('PortalDetail', section.widgetId), `section ${section.key} names an undeclared widget`)
	}
	for (const tab of widget('PageDetail', 'page-tabs').content.tabs) {
		for (const id of tab.widgetIds ?? [tab.widgetId]) {
			assert.ok(widget('PageDetail', id), `tab ${tab.label} names an undeclared widget`)
		}
	}
})
