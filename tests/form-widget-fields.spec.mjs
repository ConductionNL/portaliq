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
	answerFields,
	fieldList,
	fieldSections,
	fieldSettings,
	FORM_FIELD_TYPES,
	formFieldGroup,
	groupFields,
	isFieldGroup,
	moveField,
	removeField,
	updateField,
	withFormFieldGroup,
} from '../src/lib/formFieldTypes.js'
import {
	choiceErrors,
	filterOptions,
	moveActive,
	optionNamed,
} from '../src/site/lib/comboboxOptions.js'
import {
	chosenList,
	chosenValue,
	dateLimit,
	dateRangeErrors,
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
	// A field from the palette goes through the same write as an inspector
	// edit: onto the bound form row when there is one, else onto the widget
	// (T11b, tests/form-row-fields.spec.mjs).
	assert.match(editor, /this\.writeFormFields\(addField\(this\.formFieldsShown, type, this\.formFieldInto\)\)/)
	assert.match(editor, /if \(!this\.formRow\) \{\s*this\.editor\.setProp\('fields', list\)/)
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
	assert.match(block, /formErrors\(answerFields\(this\.fields\), this\.values\)/)
})

test('a date field offers the picker and its earliest and latest date (D1 rows 22, 23)', () => {
	const names = fieldSettings('date').map((setting) => setting.name)
	for (const name of ['picker', 'earliest', 'latest']) {
		assert.ok(names.includes(name), `date has no ${name} setting`)
	}
	const [date] = addField([], 'date')
	const picked = updateField([date], date.id, 'picker', true)[0]
	assert.equal(picked.picker, true)
	const bounded = updateField([picked], date.id, 'earliest', '2026-01-01')[0]
	assert.equal(bounded.earliest, '2026-01-01')
	assert.equal(
		updateField([bounded], date.id, 'picker', false)[0].picker,
		false,
		'the picker can be switched off again',
	)
})

test('dateLimit keeps only a real yyyy-mm-dd', () => {
	assert.equal(dateLimit('2026-02-28'), '2026-02-28')
	assert.equal(dateLimit(' 2026-02-28 '), '2026-02-28')
	assert.equal(dateLimit('2026-02-30'), '', 'a date that does not exist is no limit')
	assert.equal(dateLimit('28-02-2026'), '')
	assert.equal(dateLimit(''), '')
	assert.equal(dateLimit(undefined), '')
})

test('a date before the earliest or after the latest date is refused, in words', () => {
	const fields = [
		{ id: 'start', type: 'date', label: 'Startdatum', picker: true, earliest: '2026-12-01', latest: '2026-12-31' },
		{ id: 'free', type: 'date', label: 'Vrije datum' },
		{ id: 'name', type: 'text', label: 'Naam', earliest: '2026-12-01' },
	]
	assert.deepEqual(dateRangeErrors(fields, { start: '2026-12-15', free: '1900-01-01', name: '1900-01-01' }), {})
	assert.deepEqual(dateRangeErrors(fields, { start: '2026-11-30' }), {
		start: 'Kies bij Startdatum een datum op of na 1-12-2026.',
	})
	assert.deepEqual(dateRangeErrors(fields, { start: '2027-01-01' }), {
		start: 'Kies bij Startdatum een datum op of voor 31-12-2026.',
	})
	assert.deepEqual(
		dateRangeErrors(fields, { start: '' }),
		{},
		'an empty date is the required rule\'s to judge',
	)
	assert.deepEqual(
		dateRangeErrors(fields, { start: '30-11-2026' }),
		{},
		'a half-typed date is the date rule\'s to judge',
	)
	assert.deepEqual(
		dateRangeErrors([{ id: 'd', type: 'date', label: 'D', earliest: 'gisteren' }], { d: '2000-01-01' }),
		{},
		'an unreadable limit limits nothing',
	)
})

test('FormBlock draws a picker date as one bounded calendar control', () => {
	const block = source('src/site/components/FormBlock.vue')
	assert.match(block, /v-if="isDatePicker\(field\)"[\s\S]*?type="date"/)
	assert.match(block, /:min="dateLimit\(field\.earliest\) \|\| undefined"/)
	assert.match(block, /:max="dateLimit\(field\.latest\) \|\| undefined"/)
	assert.match(block, /return field\.type === 'date' && field\.picker === true/)
	assert.match(
		block,
		/if \(this\.isDatePicker\(field\)\) \{\s*return false/,
		'a picker is one control with its own label, not a group',
	)
	assert.match(block, /\.\.\.dateRangeErrors\(fields, values\)/)
})

test('a select can be searchable (D1 row 82, Select Combobox)', () => {
	assert.ok(fieldSettings('select').some((setting) => setting.name === 'searchable' && setting.kind === 'boolean'))
	const [select] = addField([], 'select')
	assert.equal(updateField([select], select.id, 'searchable', true)[0].searchable, true)
})

test('the combobox narrows the options on their words, ignoring case and accents', () => {
	const options = ['nh', 'zh', 'fr']
	const labels = { nh: 'Noord-Holland', zh: 'Zuid-Holland', fr: 'Fryslân' }
	const labelOf = (option) => labels[option]
	assert.deepEqual(filterOptions(options, labelOf, ''), options, 'nothing typed shows every option')
	assert.deepEqual(filterOptions(options, labelOf, 'HOLLAND'), ['nh', 'zh'], 'in their declared order')
	assert.deepEqual(filterOptions(options, labelOf, 'fryslan'), ['fr'])
	assert.deepEqual(filterOptions(options, labelOf, 'utrecht'), [])
	assert.equal(optionNamed(options, labelOf, ' zuid-holland '), 'zh')
	assert.equal(optionNamed(options, labelOf, 'fr'), 'fr', 'the option itself names it too')
	assert.equal(optionNamed(options, labelOf, 'Holland'), null, 'a part names nothing')
	assert.equal(optionNamed(options, labelOf, ''), null)
})

test('the arrow keys move through the shown options and wrap around', () => {
	assert.equal(moveActive(-1, 3, 1), 0)
	assert.equal(moveActive(-1, 3, -1), 2)
	assert.equal(moveActive(2, 3, 1), 0)
	assert.equal(moveActive(0, 3, -1), 2)
	assert.equal(moveActive(1, 3, 1), 2)
	assert.equal(moveActive(5, 2, 1), 0, 'an index past a narrowed list starts again')
	assert.equal(moveActive(0, 0, 1), -1)
})

test('a select answer that is not one of its options is refused, in words', () => {
	const fields = [
		{ id: 'province', type: 'select', label: 'Provincie', searchable: true, options: ['nh', 'zh'] },
		{ id: 'note', type: 'text', label: 'Opmerking' },
	]
	assert.deepEqual(choiceErrors(fields, { province: 'zh', note: 'iets' }), {})
	assert.deepEqual(choiceErrors(fields, { province: '' }), {}, 'empty is the required rule\'s')
	assert.deepEqual(choiceErrors(fields, { province: 'Utrecht' }), {
		province: 'Kies bij Provincie een keuze uit de lijst.',
	})
})

test('FormBlock draws a searchable select as the combobox, on Utrecht CSS', () => {
	const block = source('src/site/components/FormBlock.vue')
	assert.match(block, /<SelectCombobox\s+v-else-if="field\.type === 'select' && field\.searchable === true"/)
	assert.match(block, /:labelledby="`\$\{fieldElementId\(field\)\}-label`"/)
	assert.match(block, /\.\.\.choiceErrors\(fields, values\)/)
	const combobox = source('src/site/components/forms/SelectCombobox.vue')
	assert.match(combobox, /role="combobox"/)
	assert.match(combobox, /:aria-expanded="open \? 'true' : 'false'"/)
	assert.match(combobox, /:aria-controls="listId"/)
	assert.match(combobox, /:aria-activedescendant=/)
	assert.match(combobox, /role="listbox"/)
	assert.match(combobox, /role="option"/)
	assert.match(combobox, /aria-live="polite"/, 'the number of options left is announced')
	assert.match(combobox, /import '@utrecht\/combobox-css\/dist\/index\.css'/)
	assert.match(combobox, /import '@utrecht\/listbox-css\/dist\/index\.css'/)
	assert.match(combobox, /event\.key === 'Escape'/)
})

// --- group (fieldset), D1 row 28 -------------------------------------------

function addressForm() {
	let list = addField([], 'email')
	list = addField(list, 'group')
	list = updateField(list, 'group', 'label', 'Uw adres')
	list = addField(list, 'text', 'group')
	list = addField(list, 'text', 'group')
	list = addField(list, 'checkbox')
	return list
}

test('a group is a declared type with a legend and a description, never required', () => {
	const group = FORM_FIELD_TYPES.find((entry) => entry.type === 'group')
	assert.equal(group.nlds, 'Fieldset')
	assert.deepEqual(
		fieldSettings('group').map((setting) => setting.name),
		['label', 'id', 'description'],
	)
	const [added] = addField([], 'group')
	assert.deepEqual(added, { id: 'group', type: 'group', label: 'Groep van velden', fields: [] })
	assert.ok(isFieldGroup(added))
	assert.equal(updateField([added], 'group', 'required', true)[0].required, undefined)
})

test('a field added into a group goes at the end of that group, named over the whole form', () => {
	const list = addressForm()
	assert.deepEqual(list.map((field) => field.id), ['email', 'group', 'checkbox'])
	assert.deepEqual(groupFields(list[1]).map((field) => field.id), ['text', 'text2'])
	// The next text field outside the group still gets a name nobody carries.
	const more = addField(list, 'text')
	assert.equal(more[more.length - 1].id, 'text3')
})

test('a group never goes inside a group, and an unknown target adds to the form', () => {
	const list = addressForm()
	const nested = addField(list, 'group', 'group')
	assert.equal(groupFields(nested[1]).length, 2)
	assert.equal(nested[nested.length - 1].type, 'group')
	assert.equal(nested[nested.length - 1].id, 'group2')
	const lost = addField(list, 'email', 'gone')
	assert.equal(lost[lost.length - 1].id, 'email2')
	// A group smuggled inside a group is not one of its fields.
	assert.deepEqual(groupFields({ type: 'group', fields: [{ id: 'x', type: 'group' }, { id: 'y' }] }).map((f) => f.id), ['y'])
})

test('a field in a group is edited, renamed, moved and removed in its place', () => {
	let list = addressForm()
	list = updateField(list, 'text', 'label', 'Straat')
	assert.equal(groupFields(list[1])[0].label, 'Straat')
	// A rename to a name used outside the group is refused.
	assert.deepEqual(updateField(list, 'text', 'id', 'email'), list)
	list = updateField(list, 'text', 'id', 'street')
	assert.equal(groupFields(list[1])[0].id, 'street')
	// It moves among the group's fields only.
	list = moveField(list, 'text2', -1)
	assert.deepEqual(groupFields(list[1]).map((f) => f.id), ['text2', 'street'])
	assert.deepEqual(moveField(list, 'text2', -1), list)
	list = removeField(list, 'street')
	assert.deepEqual(groupFields(list[1]).map((f) => f.id), ['text2'])
	// Removing the group removes its fields with it.
	assert.deepEqual(removeField(list, 'group').map((f) => f.id), ['email', 'checkbox'])
	// The group itself moves among the form's own fields.
	assert.deepEqual(moveField(list, 'group', 1).map((f) => f.id), ['email', 'checkbox', 'group'])
})

test('a form answers with its loose fields and the fields of its groups, in order', () => {
	const list = addressForm()
	assert.deepEqual(answerFields(list).map((field) => field.id), ['email', 'text', 'text2', 'checkbox'])
	const sections = fieldSections(list)
	assert.equal(sections.length, 3)
	assert.equal(sections[0].group, null)
	assert.equal(sections[1].group.label, 'Uw adres')
	assert.deepEqual(sections[1].fields.map((f) => f.id), ['text', 'text2'])
	assert.deepEqual(sections[2].fields.map((f) => f.id), ['checkbox'])
	// A form without groups is one part, drawn as before.
	assert.equal(fieldSections([{ id: 'a' }, { id: 'b' }]).length, 1)
	// A field in a group is checked like any other.
	const required = updateField(list, 'text', 'required', true)
	assert.ok(answerFields(required).find((field) => field.id === 'text').required)
})

test('FormBlock draws a group as a fieldset with its legend and checks its fields', () => {
	const block = source('src/site/components/FormBlock.vue')
	assert.match(block, /:is="section\.group \? 'fieldset' : 'div'"/)
	assert.match(block, /v-for="field in section\.fields"/)
	assert.match(block, /utrecht-form-fieldset__legend/)
	assert.match(block, /return answerFields\(this\.fields\)/)
	assert.match(block, /explainsOptional\(this\.answering\.map/)
	assert.match(block, /v-if="!answering\.length"/)
})

test('the inspector lists a group\'s fields under it and adds into the group', () => {
	const list = source('src/editor/FormFieldList.vue')
	assert.match(list, /v-for="\(field, index\) in rows"/)
	assert.match(list, /@click="\$emit\('add', field\.id\)"/)
	assert.match(list, /:disabled="readonly \|\| field\.\$first"/)
	const editor = source('src/editor/PageGridEditor.vue')
	assert.match(editor, /@add="openFieldPalette"/)
	assert.match(editor, /this\.formFieldInto = ''/)
})
