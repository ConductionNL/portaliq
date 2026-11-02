#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// form-flow.spec.mjs: repeating groups, calculated values and decisions on a
// form (form-flow-repeating-groups-calculations-and-decisions). The group's
// rules, the cards, the calculations the site shows against the ones the
// server stores, and a step that follows the rule engine's answer.
//
// Usage:
//   node --test tests/form-flow.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { calculatedValues, evaluate } from '../src/site/components/forms/calculate.js'
import {
	canAdd,
	groupCountErrors,
	itemErrors,
	itemLines,
	itemTitle,
	missingMessage,
	repeatOf,
	withItem,
	withoutItem,
} from '../src/site/components/forms/group.js'
import stepFlow from '../src/site/components/forms/stepFlow.js'
import { flowSteps, stepIndexById } from '../src/site/components/forms/steps.js'
import { decideStep, initialValues } from '../src/site/lib/intakeApi.js'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const GROUP = {
	name: 'bewoners',
	type: 'group',
	label: 'Bewoners',
	repeat: { min: 2, max: 3, itemLabel: 'Bewoner', itemsLabel: 'bewoners', addLabel: 'Nog een bewoner toevoegen' },
	fields: [
		{ name: 'naam', label: 'Naam', required: true },
		{ name: 'huisnummer', label: 'Huisnummer' },
	],
}

test('the add button goes at the maximum and the minimum says what is missing', () => {
	assert.deepEqual(repeatOf(GROUP), { min: 2, max: 3, itemLabel: 'Bewoner', itemsLabel: 'bewoners', addLabel: 'Nog een bewoner toevoegen' })
	assert.equal(canAdd(GROUP, [{}, {}]), true)
	assert.equal(canAdd(GROUP, [{}, {}, {}]), false)
	assert.equal(canAdd({ ...GROUP, repeat: { min: 1 } }, new Array(50).fill({})), true, 'no max, no limit')
	assert.equal(missingMessage(GROUP, [{ naam: 'Ans' }]), 'Voeg nog 1 bewoner toe')
	assert.equal(missingMessage(GROUP, []), 'Voeg nog 2 bewoners toe')
	assert.equal(missingMessage(GROUP, [{}, {}]), '')
	assert.equal(repeatOf({ required: true, fields: [] }).min, 1, 'a required group needs one item')
})

test('the count errors name the group, and a group within bounds has none', () => {
	assert.deepEqual(groupCountErrors([GROUP], { bewoners: [{ naam: 'Ans' }] }), { bewoners: 'Voeg nog 1 bewoner toe' })
	assert.deepEqual(groupCountErrors([GROUP], { bewoners: [{}, {}, {}, {}] }), { bewoners: 'U kunt hoogstens 3 toevoegen.' })
	assert.deepEqual(groupCountErrors([GROUP], { bewoners: [{}, {}] }), {})
	assert.deepEqual(groupCountErrors([{ name: 'x', type: 'string' }], {}), {})
})

test('removing an item numbers the rest from 1 again', () => {
	const items = [{ naam: 'Ans' }, { naam: 'Piet' }, { naam: 'Kees' }]
	const left = withoutItem(items, 1)
	assert.deepEqual(left.map((item) => item.naam), ['Ans', 'Kees'])
	assert.deepEqual(left.map((_item, index) => itemTitle(GROUP, index)), ['Bewoner 1', 'Bewoner 2'])
	assert.deepEqual(withItem(left, -1, { naam: 'Jan' }).map((item) => item.naam), ['Ans', 'Kees', 'Jan'])
	assert.deepEqual(withItem(left, 0, { naam: 'Anna' }).map((item) => item.naam), ['Anna', 'Kees'])
	assert.equal(items.length, 3, 'the list handed in is untouched')
})

test('a card says its answers on one or two lines, and an item needs its required answers', () => {
	assert.deepEqual(itemLines(GROUP, { naam: 'Henk de Vries', huisnummer: '14' }), { first: 'Henk de Vries', rest: '14' })
	assert.deepEqual(itemLines(GROUP, {}), { first: '', rest: '' })
	assert.deepEqual(itemErrors(GROUP, { naam: ' ' }), { naam: 'Naam is verplicht.' })
	assert.deepEqual(itemErrors(GROUP, { naam: 'Ans' }), {})
})

test('the group renders its cards and hides the add button at the maximum', async () => {
	const two = await renderSfc('src/site/components/forms/RepeatingGroup.vue', {
		field: GROUP,
		id: 'pq-intake-field-bewoners',
		modelValue: [{ naam: 'Henk de Vries', huisnummer: '14' }, { naam: 'Ans' }],
	})
	assert.match(two, /Bewoner 1/)
	assert.match(two, /Bewoner 2/)
	assert.match(two, /Henk de Vries/)
	assert.match(two, /Wijzigen/)
	assert.match(two, /Verwijderen/)
	assert.match(two, /id="pq-intake-field-bewoners"[^>]*>\s*Nog een bewoner toevoegen/)

	const full = await renderSfc('src/site/components/forms/RepeatingGroup.vue', {
		field: GROUP,
		id: 'pq-intake-field-bewoners',
		modelValue: [{ naam: 'a' }, { naam: 'b' }, { naam: 'c' }],
	})
	assert.doesNotMatch(full, /Nog een bewoner toevoegen/)
})

test('saving needs the required answers; removing emits the list without the item', async () => {
	const group = await loadSfc('src/site/components/forms/RepeatingGroup.vue')
	const emitted = []
	const vm = {
		field: GROUP,
		words: { required: '{field} is verplicht.' },
		items: [{ naam: 'Ans' }, { naam: 'Piet' }],
		subFields: GROUP.fields,
		editing: -1,
		draft: { naam: '', huisnummer: '' },
		problems: {},
		$emit: (...args) => emitted.push(args),
		$nextTick: (fn) => fn(),
		$refs: {},
		close() {},
	}
	group.methods.save.call(vm)
	assert.deepEqual(vm.problems, { naam: 'Naam is verplicht.' })
	assert.deepEqual(emitted, [], 'nothing is kept')

	vm.draft = { naam: 'Kees', huisnummer: '3' }
	group.methods.save.call(vm)
	assert.equal(emitted[0][0], 'update:modelValue')
	assert.deepEqual(emitted[0][1].map((item) => item.naam), ['Ans', 'Piet', 'Kees'])

	group.methods.remove.call(vm, 0)
	assert.deepEqual(emitted[1][1].map((item) => item.naam), ['Piet'])
})

test('a group starts as an empty list', () => {
	assert.deepEqual(initialValues([GROUP, { name: 'x' }], {}), { bewoners: [], x: '' })
})

// The calculations ---------------------------------------------------------

const FIXTURES = JSON.parse(readFileSync('tests/fixtures/form-calculations.json', 'utf8'))

test('the site shows the same value as the server stores, on the same fixtures', () => {
	assert.ok(FIXTURES.length > 5)
	for (const fixture of FIXTURES) {
		const got = evaluate(fixture.calculate, fixture.answers)
		if (fixture.expected === null) {
			assert.equal(got, null, fixture.name)
		} else {
			assert.equal(got, fixture.expected, fixture.name)
		}
	}
})

test('a later calculated field reads an earlier one, and a value typed into a calculated field is ignored', () => {
	const fields = [
		{ name: 'einddatum', calculate: { op: 'addDays', args: ['startdatum', 365] } },
		{ name: 'looptijd', calculate: { op: 'diffDays', args: ['startdatum', 'einddatum'] } },
	]
	assert.deepEqual(calculatedValues(fields, { startdatum: '2026-11-01', einddatum: '2030-01-01' }), {
		einddatum: '2027-11-01',
		looptijd: 365,
	})
	assert.deepEqual(calculatedValues(fields, {}), {})
})

// The decisions ------------------------------------------------------------

/** A component stand-in running the shared step flow. */
function flowVm(overrides = {}) {
	const flow = flowSteps(
		[
			{ id: 'start', title: 'Start', fields: ['a'] },
			{ id: 'route', title: 'Route', fields: ['b'], decides: true },
			{ id: 'bewoner', title: 'Bewoner', fields: ['c'] },
			{ id: 'bedrijf', title: 'Bedrijf', fields: ['d'] },
		],
		{ other: 'Overig', review: 'Controleren' },
	)
	const opened = []
	return {
		opened,
		flow,
		stepIndex: 1,
		currentStep: flow[1],
		errors: {},
		backToReview: false,
		deciding: false,
		decisionDown: false,
		isShownField: () => true,
		checkFields: () => ({}),
		openStep: (index) => opened.push(index),
		focusSummary() {},
		$nextTick: (fn) => fn(),
		...overrides,
	}
}

test('a step that decides opens the step the outcome names, and an unknown name falls back to the next step', async () => {
	const named = flowVm({ decideStep: async () => ({ nextStep: 'bedrijf' }) })
	await stepFlow.methods.nextStep.call(named)
	assert.deepEqual(named.opened, [3])

	const unknown = flowVm({ decideStep: async () => ({ nextStep: 'bestaat-niet' }) })
	await stepFlow.methods.nextStep.call(unknown)
	assert.deepEqual(unknown.opened, [2], 'the ordinary next step')

	const none = flowVm({ decideStep: async () => ({ nextStep: '' }) })
	await stepFlow.methods.nextStep.call(none)
	assert.deepEqual(none.opened, [2])
	assert.equal(stepIndexById(none.flow, 'review'), -1, 'the review is never a decision target')
})

test('a step that does not decide never asks the engine', async () => {
	let asked = 0
	const vm = flowVm({ stepIndex: 0, decideStep: async () => { asked++; return { nextStep: 'bedrijf' } } })
	vm.currentStep = vm.flow[0]
	await stepFlow.methods.nextStep.call(vm)
	assert.equal(asked, 0)
	assert.deepEqual(vm.opened, [1])
})

test('an engine that is down keeps the step, says so, and a retry goes on', async () => {
	let down = true
	const vm = flowVm({
		decideStep: async () => {
			if (down) {
				throw new Error('503')
			}
			return { nextStep: 'bewoner' }
		},
	})
	await stepFlow.methods.nextStep.call(vm)
	assert.equal(vm.decisionDown, true)
	assert.deepEqual(vm.opened, [], 'the step stays')
	assert.equal(vm.deciding, false)

	down = false
	await stepFlow.methods.nextStep.call(vm)
	assert.equal(vm.decisionDown, false)
	assert.deepEqual(vm.opened, [2])
})

test('a step opened from the review returns to the review whatever the engine says', async () => {
	const vm = flowVm({ backToReview: true, decideStep: async () => ({ nextStep: 'bedrijf' }) })
	await stepFlow.methods.nextStep.call(vm)
	assert.deepEqual(vm.opened, [vm.flow.length - 1])
})

test('the decide call carries the route, the step and the answers and no rule', async () => {
	const calls = []
	const send = async (url, init) => {
		calls.push({ url, body: JSON.parse(init.body), headers: init.headers })
		return { ok: true, status: 200, json: async () => ({ outcome: 'bedrijf', output: 'soortVergunning', nextStep: 'bedrijf' }) }
	}
	const decided = await decideStep('/portal/api', 'parkeren', 'route', { kenteken: 'AB' }, 'zuiddrecht', 'tok', send)
	assert.deepEqual(decided, { outcome: 'bedrijf', output: 'soortVergunning', nextStep: 'bedrijf' })
	assert.equal(calls[0].url, '/portal/api/intake/decide')
	assert.deepEqual(calls[0].body, { route: 'parkeren', step: 'route', answers: { kenteken: 'AB' }, portal: 'zuiddrecht' })
	assert.equal(calls[0].headers.Authorization, 'Bearer tok')

	const down = async () => ({ ok: false, status: 503, json: async () => ({ error: 'decision_unavailable' }) })
	await assert.rejects(decideStep('/portal/api', 'parkeren', 'route', {}, '', '', down), (error) => error.status === 503)
})

test('the form block wires the group, the calculated line and the retry', () => {
	const block = readFileSync('src/site/components/IntakeFormBlock.vue', 'utf8')
	assert.match(block, /<RepeatingGroup/)
	assert.match(block, /isComputedField\(field\)/)
	assert.match(block, /data-testid="intake-form-decision-retry"/)
	assert.match(block, /groupCountErrors\(asked, this\.values\)/)
})
