// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// count-field: a field may ask for a count with a stepper (− and +), and a
// line under it ("3 deelnemers × [PRIJS]"). The value sent is the whole
// number, kept between min and max.
//
// Usage:
//   node --test tests/site-count-field.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { countLine, countValue } from '../src/site/components/forms/fields.js'
import { mountSfc } from './support/mount-sfc.mjs'

const FORM = 'src/site/components/c/SchemaForm.vue'

test('a count stays a whole number between min and max', () => {
	assert.equal(countValue('3', 1, 12), '3')
	assert.equal(countValue('0', 1, 12), '1')
	assert.equal(countValue('40', 1, 12), '12')
	assert.equal(countValue('', 1, 12), '1')
	assert.equal(countValue('abc', 2, 12), '2')
	assert.equal(countValue(7, 1, undefined), '7')
})

test('the line names the unit in its form and the price label', () => {
	const unit = { one: 'deelnemer', other: 'deelnemers' }
	assert.equal(countLine(3, unit, '[PRIJS]'), '3 deelnemers × [PRIJS]')
	assert.equal(countLine('1', unit, ''), '1 deelnemer')
	assert.equal(countLine(2, {}, '[PRIJS]'), '', 'no unit, no line')
})

test('through the action form: the stepper starts at min and steps within max', async () => {
	const action = {
		id: 'enrolEmployees',
		type: 'endpoint-forward',
		label: 'Medewerkers inschrijven',
		fields: ['participantCount'],
		fieldConfigs: {
			participantCount: {
				label: 'Aantal deelnemers',
				required: true,
				widget: 'count',
				min: 1,
				max: 3,
				unit: { one: 'deelnemer', other: 'deelnemers' },
				priceLabel: '[PRIJS]',
			},
		},
	}
	const sent = []
	const api = {
		async fetchOptions() {
			return []
		},
		async createObject(a, body) {
			sent.push(body)
			return { ok: true, object: { id: 'b-1', ...body } }
		},
		async runAction(a, body) {
			sent.push(body)
			return { ok: true, result: {} }
		},
		async uploadFieldFile() {
			return { ok: true }
		},
	}
	const form = await mountSfc(FORM, { action, api, locale: 'nl' })
	await form.flush()

	const line = form.find('f-enrolEmployees-participantCount-line')
	assert.ok(line, 'the line shows')
	assert.equal(form.textOf(line).trim(), '1 deelnemer × [PRIJS]')

	const more = form.find('f-enrolEmployees-participantCount-more')
	await form.fire(more, 'click')
	await form.fire(more, 'click')
	await form.fire(more, 'click')
	assert.equal(
		form.textOf(form.find('f-enrolEmployees-participantCount-line')).trim(),
		'3 deelnemers × [PRIJS]',
		'the stepper stops at max',
	)
	await form.fire(form.find('f-enrolEmployees-participantCount-fewer'), 'click')
	assert.equal(
		form.textOf(form.find('f-enrolEmployees-participantCount-line')).trim(),
		'2 deelnemers × [PRIJS]',
	)
})
