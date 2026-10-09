#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// action-form-day-entry.spec.mjs: an action form asks for one entry of a day
// (action-form-day-entry), the way the Esdoornveen board MobielDetail asks a
// student for her BPV hours: two times, a pause as cards side by side, the
// hours worked out, work processes as check cards with their code, a photo,
// "Versturen naar ..." and "Bewaren en later versturen".
//
// The action is the normalised shape EntryFormKeysTest builds in PHP.
//
// Usage:
//   node --test tests/action-form-day-entry.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { fieldErrors, fieldInput, formBody } from '../src/site/components/c/forms.js'
import {
	checkedValues,
	durationHours,
	hoursText,
	toggledValues,
	withComputed,
} from '../src/site/components/forms/dayEntry.js'
import { mountSfc } from './support/mount-sfc.mjs'

const writeHourDay = {
	id: 'writeHourDay',
	type: 'create',
	register: 'learniq',
	schema: 'bpv-hour-day',
	label: 'Uren schrijven',
	submitLabel: 'Versturen naar je praktijkopleider',
	fields: ['startTime', 'stopTime', 'pauseMinutes', 'hours', 'description', 'werkprocessen'],
	fieldConfigs: {
		startTime: { label: 'Begonnen om', required: true, size: 'small', input: 'time' },
		stopTime: { label: 'Gestopt om', required: true, size: 'small', input: 'time' },
		pauseMinutes: { label: 'Pauze', widget: 'choices', inline: true, valueType: 'integer' },
		hours: {
			label: 'Dat is vandaag',
			valueType: 'number',
			computed: { kind: 'duration', from: 'startTime', to: 'stopTime', minus: 'pauseMinutes' },
		},
		description: { label: 'Wat heb je gedaan?', size: 'large' },
		werkprocessen: { label: 'Bij welk werkproces hoort dit?', widget: 'checks', shown: 2 },
	},
	optionsProviders: {
		pauseMinutes: {
			type: 'static',
			options: [
				{ value: '0', label: 'Geen' },
				{ value: '30', label: '30 min' },
				{ value: '60', label: '60 min' },
			],
		},
		werkprocessen: {
			type: 'collection',
			register: 'learniq',
			schema: 'werkproces',
			valueField: 'id',
			labelField: 'title',
			noteField: 'code',
		},
	},
	saveForLater: { label: 'Bewaren en later versturen', defaults: { lifecycle: 'concept' } },
	footnote: 'Petra keurt je uren goed of stuurt ze terug met een vraag.',
}

const processes = [
	{ value: 'w3', label: 'Bouwt mechatronische systemen op', note: 'B1-K1-W3' },
	{ value: 'w4', label: 'Test en stelt systemen af', note: 'B1-K1-W4' },
	{ value: 'w5', label: 'Lokaliseert storingen', note: 'B1-K2-W1' },
]

/**
 * A translator that shows the key and fills its variables.
 *
 * @param {string} key The key.
 * @param {object} [vars] The variables.
 * @return {string} The text.
 */
function t(key, vars) {
	return Object.entries(vars || {}).reduce(
		(text, [name, value]) => text.split(`{${name}}`).join(String(value)),
		`[${key}]`,
	)
}

test('the hours are the time between start and stop minus the pause', () => {
	assert.equal(durationHours('08:00', '16:30', '30'), 8)
	assert.equal(durationHours('08.00', '16.00', 30), 7.5)
	assert.equal(durationHours('9:00', '10:20', ''), 1.33)
	assert.equal(durationHours('16:30', '08:00', 0), null, 'a stop before the start')
	assert.equal(durationHours('08:00', '', 0), null, 'a time is missing')
	assert.equal(durationHours('08:00', '09:00', 'lang'), null)
	assert.equal(hoursText('7.5', 'nl'), '7,5')
	assert.equal(hoursText('7.5', 'en'), '7.5')
	assert.equal(hoursText('', 'nl'), '')
})

test('a computed total follows the answers and is sent as a number', () => {
	const values = { startTime: '08:00', stopTime: '16:30', pauseMinutes: '30', hours: '' }
	const next = withComputed(writeHourDay, values)
	assert.equal(next.hours, '8')
	assert.notEqual(next, values, 'a new object when the total changed')
	assert.equal(withComputed(writeHourDay, next), next, 'the same object when nothing changed')
	assert.equal(withComputed(writeHourDay, { ...next, stopTime: '' }).hours, '')

	const body = formBody(writeHourDay, {
		...next,
		description: 'Frequentieregelaar aangesloten.',
		werkprocessen: ['w3', 'w4'],
	})
	assert.deepEqual(body, {
		startTime: '08:00',
		stopTime: '16:30',
		pauseMinutes: 30,
		hours: 8,
		description: 'Frequentieregelaar aangesloten.',
		werkprocessen: ['w3', 'w4'],
	})
	assert.deepEqual(formBody(writeHourDay, { werkprocessen: '' }).werkprocessen, [])
})

test('check cards keep the order of the options, and an empty list is empty', () => {
	assert.deepEqual(checkedValues(''), [])
	assert.deepEqual(toggledValues(['w4'], processes, 'w3'), ['w3', 'w4'])
	assert.deepEqual(toggledValues(['w3', 'w4'], processes, 'w3'), ['w4'])
	const required = {
		fields: ['werkprocessen'],
		fieldConfigs: { werkprocessen: { label: 'Werkproces', required: true, widget: 'checks' } },
	}
	assert.deepEqual(fieldErrors(required, { werkprocessen: [] }, {}, t), {
		werkprocessen: '[{field} is required.]'.replace('{field}', 'Werkproces'),
	})
	assert.deepEqual(fieldErrors(required, { werkprocessen: ['w3'] }, {}, t), {})
	assert.deepEqual(
		fieldErrors(writeHourDay, { startTime: '08:00', stopTime: '09:00', hours: '' }, {}, t),
		{},
		'a computed total never gets an error of its own',
	)
	assert.equal(fieldInput(writeHourDay, 'startTime'), 'time')
})

test('the form draws the day: time inputs, cards side by side, the total, check cards with codes', async () => {
	const api = {
		calls: [],
		async fetchOptions() {
			return processes
		},
		async createObject(action, body) {
			this.calls.push(body)
			return { ok: true, object: { id: 'd1', ...body } }
		},
	}
	const form = await mountSfc('src/site/components/c/SchemaForm.vue', { action: writeHourDay, api, t })
	await form.flush()
	const input = (name) => form.findAll((n) => n.props.id === `f-writeHourDay-${name}`)[0]

	assert.equal(input('startTime').props.type, 'time')
	assert.ok(form.find('choice-cards').props.class['pq-choice-cards--inline'], 'the pause cards sit side by side')
	assert.match(form.textOf(form.find('duration-total')), /Dat is vandaag \[Fill in the start and end time\]/)

	await form.fire(input('startTime'), 'input', { value: '08:00' })
	await form.fire(input('stopTime'), 'input', { value: '16:30' })
	await form.fire(form.find('f-writeHourDay-pauseMinutes-choice-30'), 'change')
	assert.match(form.textOf(form.find('duration-total')), /\[\{hours\} hours\]|\[8 hours\]/)
	assert.match(form.textOf(form.find('duration-total')), /8/)

	const cards = form.find('check-cards')
	assert.match(form.textOf(cards), /B1-K1-W3 Bouwt mechatronische systemen op/)
	assert.doesNotMatch(form.textOf(cards), /Lokaliseert storingen/, 'only the first two until "show all"')
	await form.fire(form.find('f-writeHourDay-werkprocessen-show-all'), 'click')
	assert.match(form.textOf(form.find('check-cards')), /Lokaliseert storingen/)
	await form.fire(form.find('f-writeHourDay-werkprocessen-check-w4'), 'change')

	assert.equal(form.textOf(form.find('schema-form-footnote')), writeHourDay.footnote)
	await form.fire(form.find('schema-form-save-for-later'), 'click')
	assert.equal(api.calls.length, 1)
	assert.equal(api.calls[0].saveForLater, true, 'the server picks the saved defaults')
	assert.equal(api.calls[0].hours, 8)
	assert.deepEqual(api.calls[0].werkprocessen, ['w4'])
	assert.equal(api.calls[0].pauseMinutes, 30)
})

test('sending is the first button and carries no save-for-later flag', async () => {
	const api = {
		calls: [],
		async fetchOptions() {
			return processes
		},
		async createObject(action, body) {
			this.calls.push(body)
			return { ok: true, object: { id: 'd2', ...body } }
		},
	}
	const form = await mountSfc('src/site/components/c/SchemaForm.vue', { action: writeHourDay, api, t })
	await form.flush()
	const input = (name) => form.findAll((n) => n.props.id === `f-writeHourDay-${name}`)[0]
	await form.fire(input('startTime'), 'input', { value: '08:00' })
	await form.fire(input('stopTime'), 'input', { value: '12:00' })
	assert.equal(form.textOf(form.find('schema-form-submit')), 'Versturen naar je praktijkopleider')
	await form.fire(form.find('schema-form'), 'submit')
	assert.equal(api.calls.length, 1)
	assert.equal('saveForLater' in api.calls[0], false)
	assert.equal(api.calls[0].hours, 4)
})
