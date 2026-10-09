#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-action-forms.spec.mjs: an action that needs input opens its form on
// the site, sends each value in the type its field declares, and a refusal
// says in plain words which field to change (site-action-forms).
//
// The actions are learniq's as declared on development (9 October 2026),
// after portaliq's normaliser: approveHourWeek and enrolEmployees (endpoint
// forwards), submitHourWeek and createExcuseRequest (creates) and
// fillInSelfAssessment (an update row action).
//
// Usage:
//   node --test tests/site-action-forms.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { asksInput, inputFields } from '../src/shared/actionInput.js'
import {
	fieldErrors,
	formBody,
	forwardResult,
	invalidFieldErrors,
	refusalKey,
	typedValue,
	valueTypeOf,
} from '../src/site/components/c/forms.js'
import { mountSfc } from './support/mount-sfc.mjs'

const BLOCK = 'src/site/components/c/ActionBlock.vue'

const approveHourWeek = {
	id: 'approveHourWeek',
	type: 'endpoint-forward',
	label: 'Uren goedkeuren',
	endpoint: '/apps/learniq/api/portal/hour-weeks/approve',
	method: 'POST',
	subjectField: 'practicalTrainerId',
	fields: ['hourWeekId', 'hoursApproved', 'note'],
	optionsProviders: {
		hourWeekId: {
			type: 'collection',
			register: 'learniq',
			schema: 'bpv-hour-week',
			labelField: 'isoWeek',
			valueField: 'id',
		},
	},
	fieldConfigs: {
		hourWeekId: { label: 'De week', required: true, size: 'medium' },
		hoursApproved: {
			label: 'Uren die je goedkeurt',
			required: true,
			size: 'medium',
			input: 'number',
			valueType: 'number',
		},
		note: { label: 'Waarom', size: 'medium' },
	},
	submitLabel: 'Keur deze uren goed',
	successMessage: 'De uren zijn goedgekeurd.',
}

const enrolEmployees = {
	id: 'enrolEmployees',
	type: 'endpoint-forward',
	label: 'Plaatsen boeken',
	endpoint: '/apps/learniq/api/portal/employer/bookings',
	method: 'POST',
	subjectField: 'organisationRef',
	fields: ['cohortId', 'participantCount'],
	optionsProviders: {
		cohortId: {
			type: 'collection',
			register: 'learniq',
			schema: 'cohort',
			labelField: 'name',
			valueField: 'id',
		},
	},
	fieldConfigs: {
		cohortId: { label: 'Cursus en datum', required: true, size: 'medium' },
		participantCount: {
			label: 'Aantal deelnemers',
			required: true,
			size: 'medium',
			widget: 'count',
			min: 1,
			max: 12,
		},
	},
}

const fillInSelfAssessment = {
	id: 'fillInSelfAssessment',
	type: 'update',
	label: 'Nu invullen',
	register: 'learniq',
	schema: 'werkproces-progress',
	fields: ['selfAssessment'],
	requiredFields: ['selfAssessment'],
	optionsProviders: {
		selfAssessment: {
			type: 'static',
			options: [
				{ value: 'goed', label: 'Goed' },
				{ value: 'voldoende', label: 'Voldoende' },
			],
		},
	},
	fieldConfigs: {
		selfAssessment: { label: 'Je inschatting', required: true, size: 'medium' },
	},
	successMessage: 'Je inschatting is opgeslagen.',
}

function t(key, vars) {
	return Object.entries(vars || {}).reduce(
		(text, [name, value]) => text.split(`{${name}}`).join(String(value)),
		`[${key}]`,
	)
}

/**
 * A portal api that records every call.
 *
 * @param {object} [answers] `forward`, `update`, `created` answers.
 * @return {object} The api with its `calls`.
 */
function recordingApi(answers = {}) {
	const calls = []
	return {
		calls,
		async fetchOptions(provider) {
			calls.push({ options: provider.schema })
			return [{ value: `${provider.schema}-1`, label: 'Eerste' }]
		},
		async forwardAction(app, actionId, body) {
			calls.push({ forward: actionId, app, body })
			return answers.forward || { ok: true, status: 200, body: {} }
		},
		async createObject(action, body) {
			calls.push({ create: action.id, body })
			return answers.created || { ok: true, object: { id: 'n1', ...body } }
		},
		async updateObject(action, id, body) {
			calls.push({ update: action.id, id, body })
			return answers.update || { ok: true, object: { id, ...body } }
		},
	}
}

test('each value goes in the type its field declares', () => {
	const hours = {
		fields: ['hoursSubmitted', 'isoWeek', 'count', 'final', 'weeks', 'extra'],
		fieldConfigs: {
			hoursSubmitted: { input: 'number', valueType: 'number' },
			count: { widget: 'count' },
			final: { valueType: 'boolean' },
			weeks: { input: 'number', valueType: 'integer' },
			extra: { input: 'number' },
		},
	}
	assert.equal(valueTypeOf(hours, 'hoursSubmitted'), 'number')
	assert.equal(valueTypeOf(hours, 'isoWeek'), 'string')
	assert.equal(valueTypeOf(hours, 'count'), 'integer')
	assert.equal(valueTypeOf(hours, 'extra'), 'number', 'a number input alone')
	assert.deepEqual(
		formBody(hours, {
			hoursSubmitted: '7,5',
			isoWeek: '2026-W41',
			count: '3',
			final: 'ja',
			weeks: '',
			extra: '16',
		}),
		{
			hoursSubmitted: 7.5,
			isoWeek: '2026-W41',
			count: 3,
			final: true,
			extra: 16,
		},
		'numbers as numbers, a decimal comma read, an empty number left out',
	)
	assert.equal(typedValue('number', 'acht'), 'acht', 'no number stays as typed')
	assert.equal(typedValue('boolean', 'nee'), false)
})

test('the form says a number field needs a number before it sends', () => {
	const errors = fieldErrors(
		approveHourWeek,
		{ hourWeekId: 'w1', hoursApproved: 'acht' },
		{},
		t,
	)
	assert.deepEqual(errors, {
		hoursApproved: '[{field}: enter a number, for example 8 or 7.5.]'.replace(
			'{field}',
			'Uren die je goedkeurt',
		),
	})
	const whole = fieldErrors(
		{ fields: ['n'], fieldConfigs: { n: { valueType: 'integer' } } },
		{ n: '2.5' },
		{},
		t,
	)
	assert.match(whole.n, /whole number/)
})

test('an action needs input only for a field the resident fills in', () => {
	assert.equal(asksInput(approveHourWeek), true)
	assert.equal(asksInput(enrolEmployees), true)
	assert.equal(asksInput(fillInSelfAssessment), true)
	assert.deepEqual(
		inputFields({
			...approveHourWeek,
			fields: ['hourWeekId', 'practicalTrainerId'],
		}),
		['hourWeekId'],
		'the stamped subject field is never asked',
	)
	assert.equal(
		asksInput({
			id: 'close',
			type: 'update',
			fields: ['state'],
			set: { state: 'closed' },
		}),
		false,
		'a transition whose values are all set runs at once',
	)
	assert.equal(asksInput({ id: 'startTask', endpoint: '/x' }), false)
	assert.equal(
		asksInput({
			id: 'x',
			fields: ['a'],
			fieldConfigs: { a: { visible: false } },
		}),
		false,
	)
})

test('an action block on an endpoint action with fields shows its form and sends typed answers', async () => {
	const api = recordingApi()
	const block = await mountSfc(BLOCK, {
		block: { type: 'action', action: 'approveHourWeek' },
		contribution: { app: 'learniq', actions: [approveHourWeek] },
		api,
		t,
	})
	await block.flush()
	assert.ok(block.find('schema-form'), 'a form, not a bare button')
	assert.deepEqual(
		api.calls.filter((c) => c.forward),
		[],
		'nothing is sent on render',
	)
	const field = (name) =>
		block.findAll((n) => n.props.id === `f-approveHourWeek-${name}`)[0]
	await block.fire(field('hourWeekId'), 'change', { value: 'bpv-hour-week-1' })
	await block.fire(field('hoursApproved'), 'input', { value: '16' })
	await block.fire(block.find('schema-form'), 'submit')

	assert.deepEqual(
		api.calls.filter((c) => c.forward),
		[
			{
				forward: 'approveHourWeek',
				app: 'learniq',
				body: { hourWeekId: 'bpv-hour-week-1', hoursApproved: 16, note: '' },
			},
		],
	)
	assert.equal(
		block.textOf(block.find('schema-form-done')),
		'De uren zijn goedgekeurd.',
	)
	assert.equal(block.emitted.created.length, 1)
})

test('a cta on an endpoint action with fields opens the form instead of posting nothing', async () => {
	const api = recordingApi()
	const cta = await mountSfc(BLOCK, {
		block: {
			type: 'cta',
			action: 'enrolEmployees',
			label: 'Medewerkers inschrijven',
		},
		contribution: { app: 'learniq', actions: [enrolEmployees] },
		api,
		t,
	})
	const open = cta.find('action-open-enrolEmployees')
	assert.ok(open, 'a button that opens the form')
	assert.equal(cta.textOf(open), 'Medewerkers inschrijven')
	assert.equal(cta.find('schema-form'), null, 'closed until pressed')
	await cta.fire(open, 'click')
	assert.deepEqual(
		api.calls.filter((c) => c.forward),
		[],
		'pressing sends nothing',
	)
	assert.ok(cta.find('schema-form'))
	assert.equal(open.props['aria-expanded'], 'true')
})

test("a greeting's create action opens its form too", async () => {
	const excuse = {
		id: 'createExcuseRequest',
		type: 'create',
		label: 'Afwezig melden',
		register: 'learniq',
		schema: 'excuse-request',
		fields: ['reason'],
		fieldConfigs: { reason: { label: 'Reden', size: 'medium' } },
	}
	const api = recordingApi()
	const cta = await mountSfc(BLOCK, {
		block: {
			type: 'cta',
			action: 'createExcuseRequest',
			label: 'Afwezig melden',
		},
		action: excuse,
		contribution: { app: 'learniq', actions: [excuse] },
		api,
		t,
	})
	await cta.fire(cta.find('action-open-createExcuseRequest'), 'click')
	await cta.fire(
		cta.findAll((n) => n.props.id === 'f-createExcuseRequest-reason')[0],
		'input',
		{ value: 'Ziek' },
	)
	await cta.fire(cta.find('schema-form'), 'submit')
	assert.deepEqual(
		api.calls.filter((c) => c.forward),
		[],
		'never a forward',
	)
	assert.deepEqual(
		api.calls.filter((c) => c.create),
		[{ create: 'createExcuseRequest', body: { reason: 'Ziek' } }],
	)
})

test('a cta on an action without fields stays one button that forwards', async () => {
	const api = recordingApi()
	const cta = await mountSfc(BLOCK, {
		block: { type: 'cta', action: 'startTask', label: 'Begin' },
		contribution: {
			app: 'learniq',
			actions: [{ id: 'startTask', endpoint: '/x' }],
		},
		api,
		t,
	})
	assert.equal(cta.find('action-open-startTask'), null)
	assert.ok(cta.find('action-button-startTask'))
})

test('an update row action with fields opens its form on the row and patches only the answer', async () => {
	const api = recordingApi()
	const step = await mountSfc('src/site/components/c/RowActionDialog.vue', {
		action: fillInSelfAssessment,
		dialog: 'form',
		collection: {
			id: 'studentWorkProcesses',
			register: 'learniq',
			schema: 'werkproces-progress',
		},
		row: { id: 'wp-1', code: 'B1-K2-W1', selfAssessment: 'voldoende' },
		api,
		t,
	})
	await step.flush()
	assert.ok(step.find('rowaction-form'))
	const select = step.findAll(
		(n) => n.props.id === 'f-fillInSelfAssessment-selfAssessment',
	)[0]
	assert.equal(select.props.value, 'voldoende', 'starts from the row')
	await step.fire(select, 'change', { value: 'goed' })
	await step.fire(step.find('schema-form'), 'submit')
	assert.deepEqual(
		api.calls.filter((c) => c.update),
		[
			{
				update: 'fillInSelfAssessment',
				id: 'wp-1',
				body: { selfAssessment: 'goed' },
			},
		],
	)
	assert.equal(step.emitted.done.length, 1)
})

test('a refused value lands on its field in plain words', async () => {
	assert.deepEqual(
		invalidFieldErrors(
			approveHourWeek,
			{ hoursApproved: 'number', note: 'other', ghost: 'number' },
			t,
		),
		{
			hoursApproved:
				'[{field}: enter a number, for example 8 or 7.5.]'.replace(
					'{field}',
					'Uren die je goedkeurt',
				),
			note: '[Waarom is not filled in correctly. Check it.]',
		},
	)
	assert.equal(
		refusalKey(422),
		'Not everything is filled in correctly. Check your answers.',
	)
	assert.equal(refusalKey(409), 'This can no longer be done for this item.')
	assert.equal(refusalKey(502), 'Saving did not work.')
	assert.deepEqual(
		forwardResult({ ok: false, status: 422, body: { error: 'incomplete' } }),
		{
			ok: false,
			status: 422,
			object: { error: 'incomplete' },
			errors: {},
			invalid: {},
		},
	)

	const api = recordingApi({
		forward: {
			ok: false,
			status: 422,
			body: { invalid: { hoursApproved: 'number' } },
		},
	})
	const block = await mountSfc(BLOCK, {
		block: { type: 'action', action: 'approveHourWeek' },
		contribution: { app: 'learniq', actions: [approveHourWeek] },
		api,
		t,
	})
	await block.flush()
	const field = (name) =>
		block.findAll((n) => n.props.id === `f-approveHourWeek-${name}`)[0]
	await block.fire(field('hourWeekId'), 'change', { value: 'bpv-hour-week-1' })
	await block.fire(field('hoursApproved'), 'input', { value: '16' })
	await block.fire(block.find('schema-form'), 'submit')
	assert.match(
		block.textOf(block.find('schema-field-error-hoursApproved')),
		/enter a number/,
	)

	const vague = recordingApi({
		forward: { ok: false, status: 422, body: { error: 'incomplete' } },
	})
	const other = await mountSfc(BLOCK, {
		block: { type: 'action', action: 'approveHourWeek' },
		contribution: { app: 'learniq', actions: [approveHourWeek] },
		api: vague,
		t,
	})
	await other.flush()
	const f2 = (name) =>
		other.findAll((n) => n.props.id === `f-approveHourWeek-${name}`)[0]
	await other.fire(f2('hourWeekId'), 'change', { value: 'bpv-hour-week-1' })
	await other.fire(f2('hoursApproved'), 'input', { value: '16' })
	await other.fire(other.find('schema-form'), 'submit')
	assert.equal(
		other.textOf(other.find('schema-form-error')),
		'[Not everything is filled in correctly. Check your answers.]',
		'not the generic "not available right now"',
	)
})
