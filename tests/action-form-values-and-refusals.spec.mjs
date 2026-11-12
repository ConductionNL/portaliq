#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// action-form-values-and-refusals.spec.mjs: which required select a form
// fills with its only option, the value an input sends, an answer in its
// field's type, and the message for a refusal that names no field
// (src/site/components/c/forms.js); pinned on every branch before these were
// split into named steps.
//
// Usage:
//   node --test tests/action-form-values-and-refusals.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { refusalKey, sentValue, typedValue, withSingleOptions } from '../src/site/components/c/forms.js'

const action = {
	fields: ['kind', 'school', 'naam', 'bijlage', 'groep'],
	fieldConfigs: { kind: { required: true }, school: { required: true }, naam: { required: true }, bijlage: { required: true, type: 'file' }, groep: { required: 'yes' } },
}

test('a required select with exactly one option is filled with it, once', () => {
	const options = {
		kind: [{ value: 'vera', label: 'Vera' }],
		school: [{ value: 'a' }, { value: 'b' }],
		groep: [{ value: 'g' }],
		bijlage: [{ value: 'f' }],
	}
	assert.deepEqual(withSingleOptions(action, {}, options), { kind: 'vera' })
	assert.deepEqual(withSingleOptions(action, { kind: 'anders' }, options), { kind: 'anders' }, 'a chosen value stays')
	assert.deepEqual(withSingleOptions(action, { kind: '' }, options), { kind: 'vera' })
	assert.deepEqual(withSingleOptions(action, { kind: null }, options), { kind: 'vera' })
	assert.deepEqual(withSingleOptions(action, {}, { kind: [null] }), {})
	assert.deepEqual(withSingleOptions(action, {}, { kind: [{ value: '' }] }), {})
	assert.deepEqual(withSingleOptions(action, {}, { kind: [{ value: 0 }] }), { kind: '0' })
	assert.deepEqual(withSingleOptions(action, {}, { kind: [{}] }), {})
	assert.deepEqual(withSingleOptions(action, {}, { kind: [] }), {})
	assert.deepEqual(withSingleOptions(action, {}, null), {})
	const provided = { ...action, optionsProviders: { naam: { collection: 'c' } } }
	assert.deepEqual(withSingleOptions(provided, {}, { naam: 'x' }), {}, 'a select whose options are not a list offers none')
	assert.deepEqual(withSingleOptions(null, { a: 1 }, {}), { a: 1 })
})

test('an input sends its value as text, a local moment as its instant', () => {
	assert.equal(sentValue('text', 'Jan'), 'Jan')
	assert.equal(sentValue('number', 3), '3')
	assert.equal(sentValue('text', null), '')
	assert.equal(sentValue('text', undefined), '')
	assert.equal(sentValue('datetime-local', ''), '')
	assert.equal(sentValue('datetime-local', null), '')
	assert.equal(sentValue('datetime-local', 'geen datum'), 'geen datum')
	assert.equal(sentValue('datetime-local', '2026-01-30T10:15'), new Date('2026-01-30T10:15').toISOString())
	assert.equal(sentValue('date', '2026-01-30'), '2026-01-30')
})

test('an answer is sent in its field\'s type when it fits, else as typed', () => {
	assert.equal(typedValue('integer', ' 12 '), 12)
	assert.equal(typedValue('number', '1,5'), 1.5)
	assert.equal(typedValue('number', '-3'), -3)
	assert.equal(typedValue('number', '1e3'), '1e3')
	assert.equal(typedValue('integer', ''), '')
	assert.equal(typedValue('boolean', 'Ja'), true)
	assert.equal(typedValue('boolean', '0'), false)
	assert.equal(typedValue('boolean', 'misschien'), 'misschien')
	assert.equal(typedValue('string', '12'), '12')
	assert.equal(typedValue(undefined, 'ja'), 'ja')
	assert.equal(typedValue('toString', 'x'), 'x')
})

test('a refusal without a field reads by its status', () => {
	for (const status of [400, 422]) {
		assert.equal(refusalKey(status), 'Not everything is filled in correctly. Check your answers.')
	}
	for (const status of [403, 404, 409]) {
		assert.equal(refusalKey(status), 'This can no longer be done for this item.')
	}
	for (const status of [0, 500, 401, '400', '404', null, undefined]) {
		assert.equal(refusalKey(status), 'Saving did not work.', String(status))
	}
})
