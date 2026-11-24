#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// form-step-fetch.spec.mjs: a step that declares a fetch runs it on the
// server before it opens; its answer fills the step's read-only fields and
// its check line; a fetch that does not answer keeps the step, the answers
// and the draft, and offers a retry (data-lookups-and-checks-in-forms
// REQ-DIF-005, situation 4).
//
// @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t05
//
// Usage:
//   node --test tests/form-step-fetch.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import stepFlow from '../src/site/components/forms/stepFlow.js'
import { flowSteps, stepFetches } from '../src/site/components/forms/steps.js'
import { fetchStep } from '../src/site/lib/intakeApi.js'

const OWNERSHIP = {
	id: 'eigenaar',
	fields: ['eigenaar'],
	checkLabel: 'Wij controleren of u eigenaar bent',
}

/**
 * A component stand-in on the address step of a form whose next step fetches.
 *
 * @param {object} overrides What differs.
 * @return {object} The stand-in, recording what it opened and fetched.
 */
function flowVm(overrides = {}) {
	const flow = flowSteps(
		[
			{ id: 'adres', title: 'Adres', fields: ['postcode'] },
			{ id: 'pand', title: 'Pand', fields: ['eigenaar'], fetch: [OWNERSHIP] },
		],
		{ other: 'Overig', review: 'Controleren' },
	)
	const vm = {
		opened: [],
		fetched: [],
		flow,
		stepIndex: 0,
		currentStep: flow[0],
		errors: {},
		values: { postcode: '3311AB' },
		backToReview: false,
		deciding: false,
		decisionDown: false,
		fetching: false,
		fetchDown: false,
		isShownField: () => true,
		checkFields: () => ({}),
		openStep: (index) => vm.opened.push(index),
		focusSummary() {},
		async fetchStep(step, fetch) {
			vm.fetched.push([step.id, fetch.id, vm.fetching])
		},
		$nextTick: (fn) => fn(),
		...overrides,
	}
	return vm
}

test('the steps keep only the fetch id, the fields it fills and its check label', () => {
	assert.deepEqual(
		stepFetches([
			{
				id: 'eigenaar',
				fields: ['eigenaar', 3],
				checkLabel: null,
				source: 'kadaster',
			},
			{ fields: [] },
		]),
		{ fetch: [{ id: 'eigenaar', fields: ['eigenaar'], checkLabel: '' }] },
	)
	assert.deepEqual(stepFetches(undefined), {})
	assert.deepEqual(stepFetches([{ id: '' }]), {})
	const flow = flowVm().flow
	assert.deepEqual(flow[1].fetch, [OWNERSHIP])
	assert.equal(flow[0].fetch, undefined)
})

test('the step that fetches runs its fetch before it opens', async () => {
	const vm = flowVm()
	await stepFlow.methods.nextStep.call(vm)
	assert.deepEqual(vm.fetched, [['pand', 'eigenaar', true]])
	assert.deepEqual(vm.opened, [1])
	assert.equal(vm.fetching, false)
	assert.equal(vm.fetchDown, false)
})

test('a fetch that does not answer keeps the step and the answers, and a retry opens it', async () => {
	let down = true
	const vm = flowVm({
		async fetchStep() {
			if (down) {
				throw new Error('503')
			}
		},
	})
	await stepFlow.methods.nextStep.call(vm)
	assert.deepEqual(vm.opened, [], 'the address step stays')
	assert.equal(vm.fetchDown, true)
	assert.deepEqual(vm.values, { postcode: '3311AB' })

	down = false
	await stepFlow.methods.nextStep.call(vm)
	assert.deepEqual(vm.opened, [1])
	assert.equal(vm.fetchDown, false)
})

test('without a fetch handler the step opens as before', async () => {
	const vm = flowVm({ fetchStep: undefined })
	await stepFlow.methods.nextStep.call(vm)
	assert.deepEqual(vm.opened, [1])
})

test('fetchStep posts the step, the fetch and the answers, and throws on a refusal', async () => {
	const calls = []
	const answering = (status, body) => async (url, init) => {
		calls.push([url, JSON.parse(init.body)])
		return new Response(JSON.stringify(body), { status })
	}
	const conn = {
		base: '/apps/portaliq',
		portal: 'zuiddrecht',
		fetchImpl: answering(200, {
			fetch: 'eigenaar',
			values: { eigenaar: 'S. de Vries' },
			check: 'Volgens het Kadaster staat dit pand op uw naam.',
		}),
	}

	const fetched = await fetchStep(conn, {
		route: 'verbouwen',
		step: 'pand',
		fetch: 'eigenaar',
		answers: { postcode: '3311AB' },
	})
	assert.deepEqual(fetched, {
		values: { eigenaar: 'S. de Vries' },
		check: 'Volgens het Kadaster staat dit pand op uw naam.',
	})
	assert.match(calls[0][0], /\/intake\/fetch$/)
	assert.deepEqual(calls[0][1], {
		route: 'verbouwen',
		step: 'pand',
		fetch: 'eigenaar',
		answers: { postcode: '3311AB' },
		portal: 'zuiddrecht',
	})

	const down = {
		...conn,
		fetchImpl: answering(503, { error: 'fetch_unavailable' }),
	}
	await assert.rejects(
		fetchStep(down, {
			route: 'verbouwen',
			step: 'pand',
			fetch: 'eigenaar',
			answers: {},
		}),
	)
})
