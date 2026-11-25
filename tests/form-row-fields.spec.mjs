#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// form-row-fields.spec.mjs: a bound `form` widget's field list edits its
// `form` row, not the widget (site-nlds-widget-palette T11b, decision of
// 24 Nov 2026, option b).
//
// WHAT THIS CATCHES. The anonymous submit accepts only the field ids on the
// bound form row (FormSubmitActions::fieldIdsOf). A field added on the widget
// alone renders on the page and is then refused on submit. So: the inspector
// reads the row's fields, writes them back to the row with the version check,
// keeps the stored list when OpenRegister refuses the write, and never lets a
// user without write access change the list. The pure client is asserted on
// fakes; the wiring into the inspector and both hosts on the sources.
//
// Usage:
//   node --test tests/form-row-fields.spec.mjs
//
// @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	boundFormId,
	createFormRowFields,
	formRowEditable,
	refusalOf,
} from '../src/editor/formRowFields.js'

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8')

const EMAIL = { id: 'email', label: 'E-mail', type: 'email', required: true }
const NAME = { id: 'name', label: 'Naam', type: 'text', required: false }

/**
 * A fake OpenRegister holding one form row.
 *
 * @param {object} options The fake's behaviour.
 * @return {object} The fake with its calls.
 */
function fakeRegister({ getStatus = 0, putStatus = 0, updated = 'v1' } = {}) {
	const calls = { get: [], put: [] }
	const stored = {
		'@self': { id: 'form-1', updated },
		portal: 'zuiddrecht',
		destination: { register: 'r', schema: 's' },
		fields: [EMAIL],
		submitLabel: 'Versturen',
		status: 'active',
	}
	const refuse = (status) => {
		const error = new Error(`HTTP ${status}`)
		error.response = { status, data: {} }
		return error
	}
	return {
		calls,
		stored,
		client: createFormRowFields({
			get: async (url) => {
				calls.get.push(url)
				if (getStatus) {
					throw refuse(getStatus)
				}
				return { data: JSON.parse(JSON.stringify(stored)) }
			},
			put: async (url, payload, config) => {
				calls.put.push({ url, payload, config })
				if (putStatus) {
					throw refuse(putStatus)
				}
				stored.fields = payload.fields
				stored['@self'].updated = `${stored['@self'].updated}+`
				return { data: payload }
			},
			url: (id) => `/apps/openregister/api/objects/portaliq/form/${id}`,
		}),
	}
}

test('a widget is bound by its trimmed formId', () => {
	assert.equal(boundFormId({ formId: ' form-1 ' }), 'form-1')
	assert.equal(boundFormId({ formId: 7 }), '')
	assert.equal(boundFormId({}), '')
	assert.equal(boundFormId(null), '')
})

test('the inspector reads the fields from the bound row', async () => {
	const fake = fakeRegister()
	const state = await fake.client.load('form-1')
	assert.equal(state.state, 'ready')
	assert.deepEqual(state.fields, [EMAIL])
	assert.equal(state.version, 'v1')
	assert.equal(state.row['@self'], undefined)
	assert.deepEqual(fake.calls.get, ['/apps/openregister/api/objects/portaliq/form/form-1'])
})

test('a refused or missing read says why and lists nothing', async () => {
	assert.equal((await fakeRegister({ getStatus: 403 }).client.load('form-1')).state, 'forbidden')
	assert.equal((await fakeRegister({ getStatus: 401 }).client.load('form-1')).state, 'forbidden')
	assert.equal((await fakeRegister({ getStatus: 404 }).client.load('form-1')).state, 'missing')
	const failed = await fakeRegister({ getStatus: 500 }).client.load('form-1')
	assert.equal(failed.state, 'failed')
	assert.deepEqual(failed.fields, [])
})

test('an added field is written to the row with the version check, then read back', async () => {
	const fake = fakeRegister()
	const loaded = await fake.client.load('form-1')
	const saved = await fake.client.save(loaded, [EMAIL, NAME])
	assert.equal(fake.calls.put.length, 1)
	const { payload, config } = fake.calls.put[0]
	assert.deepEqual(payload.fields, [EMAIL, NAME])
	// The rest of the row goes back unchanged: the destination stays the row's.
	assert.deepEqual(payload.destination, { register: 'r', schema: 's' })
	assert.equal(payload.portal, 'zuiddrecht')
	assert.equal(payload['@self'], undefined)
	assert.equal(config.headers['If-Match'], 'v1')
	assert.equal(saved.state, 'ready')
	assert.deepEqual(saved.fields, [EMAIL, NAME])
	assert.equal(saved.version, 'v1+')
})

test('a write OpenRegister refuses keeps the stored fields and locks the list', async () => {
	const fake = fakeRegister({ putStatus: 403 })
	const loaded = await fake.client.load('form-1')
	const refused = await fake.client.save(loaded, [EMAIL, NAME])
	assert.equal(refused.state, 'forbidden')
	assert.deepEqual(refused.fields, [EMAIL])
	assert.equal(formRowEditable(refused), false)
	// A locked list writes nothing more.
	const again = await fake.client.save(refused, [NAME])
	assert.equal(again, refused)
	assert.equal(fake.calls.put.length, 1)
})

test('a row changed by someone else is not overwritten', async () => {
	const fake = fakeRegister()
	const loaded = await fake.client.load('form-1')
	fake.stored['@self'].updated = 'v2'
	const refused = await fake.client.save(loaded, [EMAIL, NAME])
	assert.equal(refused.state, 'conflict')
	assert.equal(fake.calls.put.length, 0)
	const raced = fakeRegister({ putStatus: 409 })
	const state = await raced.client.save(await raced.client.load('form-1'), [NAME])
	assert.equal(state.state, 'conflict')
})

test('only an unbound widget or a readable row is editable', () => {
	assert.equal(formRowEditable(null), true)
	assert.equal(formRowEditable({ state: 'ready' }), true)
	for (const state of ['loading', 'forbidden', 'conflict', 'missing', 'failed']) {
		assert.equal(formRowEditable({ state }), false, state)
	}
	assert.equal(refusalOf({ response: { status: 502 } }), 'failed')
	assert.equal(refusalOf(undefined), 'failed')
})

test('the inspector lists and writes the bound row, and both hosts hand the client in', () => {
	const grid = read('src/editor/PageGridEditor.vue')
	assert.match(grid, /:fields="formFieldsShown"/)
	assert.match(grid, /:readonly="!formRowWritable"/)
	assert.match(grid, /@update:fields="writeFormFields"/)
	assert.match(grid, /this\.formRows\.save\(this\.formRow, list\)/)
	// The widget takes the list only once the row stored it.
	assert.match(grid, /if \(next\.state === 'ready'\) \{\s*this\.editor\.setProp\('fields', next\.fields\)/)
	assert.match(grid, /this\.writeFormFields\(addField\(this\.formFieldsShown, type, this\.formFieldInto\)\)/)
	const list = read('src/editor/FormFieldList.vue')
	assert.match(list, /readonly: \{\s*type: Boolean/)
	assert.match(list, /class="form-field-list__add"\s*:disabled="readonly"/)
	for (const host of ['src/editor/SiteEditMode.vue', 'src/views/PageLayoutDesigner.vue']) {
		const source = read(host)
		assert.match(source, /:formRows="formRows"/, host)
		assert.match(source, /createFormRowFields\(/, host)
		assert.match(source, /api\/objects\/portaliq\/form\//, host)
	}
})
