#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// step-flow-next.spec.mjs: what "Volgende stap" checks and where it goes
// when the step's shown fields fail, pass, or are partly hidden, and that
// returning from the review is undone, pinned before nextStep was split
// into named steps.
//
// Usage:
//   node --test tests/step-flow-next.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import stepFlow from '../src/site/components/forms/stepFlow.js'
import { flowSteps } from '../src/site/components/forms/steps.js'

/**
 * A component stand-in running the shared step flow.
 *
 * @param {object} overrides What differs.
 * @return {object} The stand-in, recording what it opened, checked and focused.
 */
function flowVm(overrides = {}) {
	const flow = flowSteps(
		[
			{ id: 'start', title: 'Start', fields: ['a', 'hidden'] },
			{ id: 'next', title: 'Next', fields: ['b'] },
			{ id: 'skipped', title: 'Skipped', fields: ['hidden'] },
			{ id: 'last', title: 'Last', fields: ['c'] },
		],
		{ other: 'Overig', review: 'Controleren' },
	)
	const vm = {
		opened: [],
		checked: [],
		focused: 0,
		flow,
		stepIndex: 0,
		currentStep: flow[0],
		errors: { old: 'x' },
		backToReview: false,
		deciding: false,
		decisionDown: false,
		isShownField: (field) => field !== 'hidden',
		checkFields(fields) {
			vm.checked.push(fields)
			return {}
		},
		openStep: (index) => vm.opened.push(index),
		focusSummary() {
			vm.focused++
		},
		$nextTick: (fn) => fn(),
		...overrides,
	}
	return vm
}

test('only the shown fields are checked; a failure stays and focuses the summary', async () => {
	const failing = flowVm({ checkFields: (fields) => (failing.checked.push(fields), { a: 'Vul a in' }) })
	await stepFlow.methods.nextStep.call(failing)
	assert.deepEqual(failing.checked, [['a']])
	assert.deepEqual(failing.errors, { a: 'Vul a in' })
	assert.deepEqual(failing.opened, [])
	assert.equal(failing.focused, 1)
})

test('a passing step clears the errors and opens the next shown step', async () => {
	const vm = flowVm()
	await stepFlow.methods.nextStep.call(vm)
	assert.deepEqual(vm.errors, {})
	assert.deepEqual(vm.opened, [1])
	const later = flowVm({ stepIndex: 1 })
	later.currentStep = later.flow[1]
	await stepFlow.methods.nextStep.call(later)
	assert.deepEqual(later.opened, [3], 'a step whose fields are all hidden is skipped')
	assert.equal(later.focused, 0)
})

test('a step opened from the review goes back to it, once', async () => {
	const vm = flowVm({ backToReview: true })
	await stepFlow.methods.nextStep.call(vm)
	assert.deepEqual(vm.opened, [vm.flow.length - 1])
	assert.equal(vm.backToReview, false)
})
