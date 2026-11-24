#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// form-widget-fields.spec.mjs: fields are added inside a `form` widget, never
// to the grid (site-nlds-widget-palette REQ-SNW-003, design D1 and D4).
//
// WHAT THIS CATCHES. The field list is a prop an editor builds by clicking, so
// the failures are quiet: two fields with one name submit one value, a field
// keeps a setting its type never reads, the Formulieren group adds a field
// while no form is selected (the spec's "No form selected" scenario), or the
// group drops out of its place between Navigatie and Terugkoppeling. And on the
// site: a checkbox group submits the order the visitor clicked in, or a radio
// keeps two answers. Each of those is asserted on the pure modules; the wiring
// into the palette, the inspector and FormBlock is asserted on the sources.
//
// Usage:
//   node --test tests/form-widget-fields.spec.mjs
//
// @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	addField,
	fieldList,
	fieldSettings,
	FORM_FIELD_TYPES,
	formFieldGroup,
	moveField,
	removeField,
	updateField,
	withFormFieldGroup,
} from '../src/lib/formFieldTypes.js'
import {
	chosenList,
	chosenValue,
	minimumErrors,
} from '../src/site/lib/formFieldValues.js'

const source = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8')

test('every field type declares a Dutch label and its NL Design System component', () => {
	const types = FORM_FIELD_TYPES.map((entry) => entry.type)
	assert.equal(new Set(types).size, types.length, 'a type is declared twice')
	for (const entry of FORM_FIELD_TYPES) {
		assert.ok(entry.label.trim() !== '', `${entry.type} has no label`)
		assert.ok(entry.nlds.trim() !== '', `${entry.type} names no component`)
	}
	for (const type of [
		'text',
		'textarea',
		'number',
		'date',
		'select',
		'radio',
		'checkboxes',
		'checkbox',
		'switch',
		'range',
		'textAffixed',
	]) {
		assert.ok(types.includes(type), `${type} is missing`)
	}
})

test("name, label and required are always editable, then the type's own settings", () => {
	const names = fieldSettings('number').map((setting) => setting.name)
	assert.deepEqual(names, ['label', 'id', 'required', 'min', 'max', 'step'])
	assert.deepEqual(
		fieldSettings('nonsense').map((setting) => setting.name),
		['label', 'id', 'required'],
	)
})

test('adding "Datum" and labelling it "Geboortedatum" gives a date field with that label', () => {
	let fields = addField([], 'date')
	assert.deepEqual(fields, [
		{ id: 'date', type: 'date', label: 'Datum', required: false },
	])
	fields = updateField(fields, 'date', 'label', 'Geboortedatum')
	assert.equal(fields[0].label, 'Geboortedatum')
	assert.equal(fields[0].type, 'date')
})

test('a new field gets a name no other field carries, and an unknown type adds nothing', () => {
	let fields = addField([], 'text')
	fields = addField(fields, 'text')
	fields = addField(fields, 'text')
	assert.deepEqual(
		fields.map((field) => field.id),
		['text', 'text2', 'text3'],
	)
	assert.equal(addField(fields, 'nonsense').length, 3)
	assert.deepEqual(
		addField([], 'select')[0].options,
		[],
		'a choice field starts with an empty option list',
	)
})

test('a rename to a taken or empty name is refused; any other rename is kept', () => {
	const fields = addField(addField([], 'text'), 'email')
	assert.deepEqual(updateField(fields, 'email', 'id', 'text'), fields)
	assert.deepEqual(updateField(fields, 'email', 'id', '  '), fields)
	assert.equal(updateField(fields, 'email', 'id', 'mail')[1].id, 'mail')
})

test('a setting the type does not declare is not written; values take their kind', () => {
	const fields = addField([], 'number')
	assert.deepEqual(
		updateField(fields, 'number', 'options', 'a\nb'),
		fields,
		'a number has no options',
	)
	assert.equal(updateField(fields, 'number', 'min', '3')[0].min, 3)
	assert.equal(
		'min'
			in updateField(
				updateField(fields, 'number', 'min', '3'),
				'number',
				'min',
				'',
			)[0],
		false,
	)
	assert.equal(updateField(fields, 'number', 'required', true)[0].required, true)
	const select = addField([], 'select')
	assert.deepEqual(
		updateField(select, 'select', 'options', ' Ja \n\nNee ')[0].options,
		['Ja', 'Nee'],
	)
})

test('remove and move return a new list; moving past an end changes nothing', () => {
	const fields = addField(addField(addField([], 'text'), 'email'), 'date')
	assert.deepEqual(
		removeField(fields, 'email').map((field) => field.id),
		['text', 'date'],
	)
	assert.deepEqual(
		moveField(fields, 'date', -1).map((field) => field.id),
		['text', 'date', 'email'],
	)
	assert.deepEqual(
		moveField(fields, 'text', -1).map((field) => field.id),
		['text', 'email', 'date'],
	)
	assert.deepEqual(
		moveField(fields, 'date', 1).map((field) => field.id),
		['text', 'email', 'date'],
	)
	assert.notEqual(removeField(fields, 'nobody'), fields)
	assert.deepEqual(fieldList('not a list'), [])
})

test('with no form selected the group says to select one first and adds nothing', () => {
	const closed = formFieldGroup('', false)
	assert.equal(closed.enabled, false)
	assert.match(closed.note, /Selecteer eerst een formulier/)
	assert.equal(closed.entries.length, FORM_FIELD_TYPES.length)
	const open = formFieldGroup('', true)
	assert.equal(open.enabled, true)
	assert.equal(open.note, '')
})

test('the field types are searched by label, type, component name and synonym', () => {
	assert.deepEqual(
		formFieldGroup('Radio Group', true).entries.map((entry) => entry.key),
		['radio'],
	)
	assert.deepEqual(
		formFieldGroup('geboortedatum', true).entries.map((entry) => entry.key),
		['date'],
	)
	assert.equal(formFieldGroup('zzz-nothing', true), null)
})

test('Formulieren sits after Navigatie and keeps the widgets filed under it', () => {
	const groups = [
		{ group: 'content', label: 'Inhoud', entries: [{ key: 'a' }] },
		{ group: 'nav', label: 'Navigatie', entries: [{ key: 'b' }] },
		{ group: 'forms', label: 'Formulieren', entries: [{ key: 'nlLookupForm' }] },
		{ group: 'feedback', label: 'Terugkoppeling', entries: [{ key: 'c' }] },
	]
	const merged = withFormFieldGroup(groups, formFieldGroup('', true))
	assert.deepEqual(
		merged.map((group) => group.group),
		['content', 'nav', 'forms', 'feedback'],
	)
	assert.deepEqual(merged[2].entries, [{ key: 'nlLookupForm' }])
	assert.equal(merged[2].fields.length, FORM_FIELD_TYPES.length)
	const withoutForms = withFormFieldGroup(
		[groups[0], groups[3]],
		formFieldGroup('', false),
	)
	assert.deepEqual(
		withoutForms.map((group) => group.group),
		['content', 'forms', 'feedback'],
	)
	assert.deepEqual(withFormFieldGroup(groups, null), groups)
})

test('a radio keeps one answer; checkboxes keep the declared order, not the click order', () => {
	const radio = { id: 'r', type: 'radio', options: ['A', 'B'] }
	assert.equal(chosenValue(radio, 'A', 'B', true), 'B')
	assert.equal(chosenValue(radio, 'B', 'B', false), '')
	const boxes = { id: 'c', type: 'checkboxes', options: ['A', 'B', 'C'] }
	let value = chosenValue(boxes, [], 'C', true)
	value = chosenValue(boxes, value, 'A', true)
	assert.deepEqual(value, ['A', 'C'])
	assert.deepEqual(chosenValue(boxes, value, 'C', false), ['A'])
	assert.deepEqual(chosenList(boxes, 'A'), ['A'])
	assert.deepEqual(chosenList(boxes, undefined), [])
})

test("a started checkbox group must reach its minimum; an empty one is the required rule's", () => {
	const fields = [
		{
			id: 'c',
			type: 'checkboxes',
			label: 'Interesses',
			options: ['A', 'B', 'C'],
			minimum: 2,
		},
	]
	assert.deepEqual(minimumErrors(fields, { c: ['A'] }), {
		c: 'Kies er minstens 2 bij Interesses.',
	})
	assert.deepEqual(minimumErrors(fields, { c: ['A', 'B'] }), {})
	assert.deepEqual(minimumErrors(fields, { c: [] }), {})
})

test('the palette adds a field only with a form selected, and never by drag', () => {
	const palette = source('src/editor/WidgetPalettePanel.vue')
	assert.match(palette, /formFieldGroup\(this\.query, this\.formSelected\)/)
	assert.match(palette, /:disabled="!group\.enabled"/)
	assert.match(palette, /this\.\$emit\('chooseField', field\.key\)/)
	const fieldButtons = palette.slice(
		palette.indexOf('widget-palette-fields'),
		palette.indexOf('</template>', palette.indexOf('widget-palette-fields')),
	)
	assert.doesNotMatch(fieldButtons, /draggable/)
})

test("the inspector edits a form's fields as a list and the palette adds to it", () => {
	const editor = source('src/editor/PageGridEditor.vue')
	assert.match(editor, /:formSelected="formSelected"/)
	assert.match(editor, /@chooseField="addFormField"/)
	assert.match(editor, /<FormFieldList/)
	assert.match(
		editor,
		/editor\.setProp\('fields', addField\(this\.selected\.props\?\.fields, type\)\)/,
	)
	const catalogue = source('src/lib/pageWidgetCatalogue.js')
	assert.match(
		catalogue,
		/form: FormBlock,/,
		"the form widget's props are read for the inspector",
	)
	assert.match(
		catalogue,
		/form: \['portal', 'portalHelp'\]/,
		'the host-supplied props are not offered',
	)
})

test('FormBlock draws every declared type, choice groups under one legend', () => {
	const block = source('src/site/components/FormBlock.vue')
	for (const type of [
		'radio',
		'checkboxes',
		'checkbox',
		'switch',
		'textAffixed',
		'range',
		'textarea',
		'select',
		'date',
	]) {
		assert.match(
			block,
			new RegExp(`field\\.type === '${type}'`),
			`${type} is not drawn`,
		)
	}
	assert.match(
		block,
		/return \['date', 'radio', 'checkboxes'\]\.includes\(field\.type\)/,
	)
	assert.match(block, /:role="field\.type === 'switch' \? 'switch' : undefined"/)
	assert.match(block, /formErrors\(this\.fields, this\.values\)/)
})
