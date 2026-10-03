// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The site's schema form (site-reaches-portal-parity T11, REQ-SRP-022): it
// renders exactly the action's whitelisted fields, each with a label, a
// required marker and an inline error, a date property as a date input and an
// enum as a select; a collection dropdown offers only what the subject-scoped
// api returned; and a submit sends only the whitelisted fields.
//
// Usage:
//   node --test tests/schema-form.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	fieldErrors,
	fieldInput,
	formBody,
	sentValue,
	staticOptions,
	withSingleOptions,
} from '../src/site/components/c/forms.js'
import { mountSfc } from './support/mount-sfc.mjs'

const FORM = 'src/site/components/c/SchemaForm.vue'

/**
 * Type a date into a date group's Dag, Maand and Jaar boxes.
 *
 * @param {object} form The mounted form.
 * @param {string} id The field's id (the Dag box's id).
 * @param {string} day The day.
 * @param {string} month The month.
 * @param {string} year The year.
 * @return {Promise<void>}
 */
async function fillDate(form, id, day, month, year) {
	const box = (suffix) => form.findAll((n) => n.props.id === `${id}${suffix}`)[0]
	await form.fire(box(''), 'input', { value: day })
	await form.fire(box('-month'), 'input', { value: month })
	await form.fire(box('-year'), 'input', { value: year })
}

/**
 * The guardian's absence report as learniq declares it, after portaliq's
 * normaliser added what the schema says (a date input, the enum as options).
 *
 * @return {object} The action.
 */
function absenceAction() {
	return {
		id: 'createExcuseRequest',
		type: 'create',
		label: "Report a child's absence",
		register: 'learniq',
		schema: 'excuse-request',
		fields: ['learnerRef', 'dateFrom', 'reason', 'reasonKind'],
		fieldConfigs: {
			learnerRef: { label: 'Child', required: true, size: 'medium' },
			dateFrom: {
				label: 'First day absent',
				required: true,
				size: 'medium',
				input: 'date',
			},
			reason: { label: 'Reason', size: 'large' },
			reasonKind: { label: 'Kind of absence', required: true, size: 'medium' },
		},
		optionsProviders: {
			learnerRef: {
				type: 'collection',
				register: 'learniq',
				schema: 'learner-profile',
				valueField: 'id',
				labelField: 'name',
			},
			reasonKind: {
				type: 'static',
				options: [
					{ value: 'illness', label: 'Illness' },
					{ value: 'medical-appointment', label: 'Medical appointment' },
				],
			},
		},
		submitLabel: 'Report the absence',
		successMessage: 'The school has your report.',
	}
}

/**
 * A fake portal api that records what the form sends.
 *
 * @param {object} [answers] Overrides.
 * @return {object} The api and its call log.
 */
function fakeApi(answers = {}) {
	const calls = { created: [], options: [] }
	return {
		calls,
		async fetchOptions(provider) {
			calls.options.push(provider)
			return answers.options || [{ value: 'vera-1', label: 'Vera' }]
		},
		async createObject(action, body) {
			calls.created.push(body)
			return answers.created || { ok: true, object: { id: 'new-1', ...body } }
		},
		async uploadFieldFile() {
			return { ok: true }
		},
	}
}

test('a date property is a date input and an enum a select, never a text box', async () => {
	const action = absenceAction()
	assert.equal(fieldInput(action, 'dateFrom'), 'date')
	assert.equal(
		fieldInput(action, 'reasonKind', staticOptions(action).reasonKind),
		'select',
	)
	assert.equal(fieldInput(action, 'reason'), 'textarea')
	assert.equal(
		fieldInput(action, 'learnerRef'),
		'select',
		'a collection provider is a select before its options arrive',
	)

	const form = await mountSfc(FORM, { action, api: fakeApi() })
	await form.flush()
	const inputs = form.findAll((n) =>
		['input', 'select', 'textarea'].includes(n.tag),
	)
	assert.deepEqual(
		inputs.map((n) => `${n.tag}:${n.props.type || ''}:${n.props.id}`),
		[
			'select::f-createExcuseRequest-learnerRef',
			'input:text:f-createExcuseRequest-dateFrom',
			'input:text:f-createExcuseRequest-dateFrom-month',
			'input:text:f-createExcuseRequest-dateFrom-year',
			'textarea::f-createExcuseRequest-reason',
			'select::f-createExcuseRequest-reasonKind',
		],
	)
	const kind = form.find('schema-field-reasonKind')
	assert.match(form.textOf(kind), /^Kind of absence Choose an option/)
	assert.doesNotMatch(form.text(), /\*/, 'no asterisk on any label')
	// The date is a fieldset whose legend is the question (REQ-SMF-003).
	const date = form.find('schema-field-dateFrom')
	assert.equal(date.tag, 'fieldset')
	assert.equal(
		form.textOf(date.children.find((n) => n.tag === 'legend')),
		'First day absent',
	)
	const day = inputs.find((n) => n.props.id === 'f-createExcuseRequest-dateFrom')
	assert.equal(day.props.inputmode, 'numeric')
	assert.match(form.textOf(date), /Day Month Year/)
	assert.match(form.textOf(kind), /Choose an option Illness Medical appointment/)
})

test('every input has a label pointing at it, and a required one says so', async () => {
	const form = await mountSfc(FORM, { action: absenceAction(), api: fakeApi() })
	await form.flush()
	const labels = form.findAll((n) => n.tag === 'label')
	const ids = form
		.findAll((n) => ['input', 'select', 'textarea'].includes(n.tag))
		.map((n) => n.props.id)
	assert.deepEqual(
		labels.map((l) => l.props.for),
		ids,
	)
	const date = form.findAll(
		(n) => n.props.id === 'f-createExcuseRequest-dateFrom',
	)[0]
	// Required is aria-required, never the native attribute whose browser
	// bubble would compete with the error summary (REQ-SMF-001).
	assert.equal(date.props.required, undefined)
	assert.equal(date.props['aria-required'], 'true')
	const reason = form.findAll(
		(n) => n.props.id === 'f-createExcuseRequest-reason',
	)[0]
	assert.equal(reason.props['aria-required'], undefined)
	assert.equal(
		form.textOf(labels.find((l) => l.props.for === reason.props.id)),
		'Reason (optional)',
	)
	assert.equal(
		form.textOf(form.find('schema-form-optional-note')),
		'A field without "optional" must be filled in.',
	)
})

test('a collection dropdown lists only what the subject-scoped api returned', async () => {
	const api = fakeApi()
	const form = await mountSfc(FORM, { action: absenceAction(), api })
	await form.flush()
	assert.equal(api.calls.options.length, 1)
	assert.equal(api.calls.options[0].schema, 'learner-profile')
	assert.match(
		form.textOf(form.find('schema-field-learnerRef')),
		/Choose an option Vera/,
	)
})

test('an empty required field gets an inline error and nothing is sent', async () => {
	const api = fakeApi()
	const form = await mountSfc(FORM, { action: absenceAction(), api })
	await form.flush()
	await form.fire(form.find('schema-form'), 'submit')

	assert.equal(api.calls.created.length, 0)
	// The error summary takes focus and links each error, in field order (REQ-SMF-002).
	const heading = form.find('error-summary-heading')
	assert.equal(form.textOf(heading), 'Something is still missing')
	assert.equal(form.focused(), heading, 'the summary heading has focus')
	const links = form.findAll((n) =>
		String(n.props['data-testid'] || '').startsWith('error-summary-link-'),
	)
	assert.deepEqual(
		links.map((a) => [a.props.href, form.textOf(a)]),
		[
			['#f-createExcuseRequest-dateFrom', 'First day absent is required.'],
			['#f-createExcuseRequest-reasonKind', 'Kind of absence is required.'],
		],
	)
	assert.equal(form.find('schema-form-error'), null, 'no second top alert')
	assert.equal(
		form.textOf(form.find('schema-field-error-dateFrom')),
		'First day absent is required.',
	)
	assert.equal(
		form.find('schema-field-error-reason'),
		null,
		'an optional field has no error',
	)
	const date = form.findAll(
		(n) => n.props.id === 'f-createExcuseRequest-dateFrom',
	)[0]
	assert.equal(date.props['aria-invalid'], 'true')
	assert.match(
		form.find('schema-field-dateFrom').props['aria-describedby'],
		/f-createExcuseRequest-dateFrom-error/,
		'the date fieldset is described by its error',
	)
})

test('a filled form sends only the whitelisted fields and shows the success message', async () => {
	const api = fakeApi()
	const form = await mountSfc(FORM, { action: absenceAction(), api })
	await form.flush()
	const field = (name) =>
		form.findAll((n) => n.props.id === `f-createExcuseRequest-${name}`)[0]
	await form.fire(field('learnerRef'), 'change', { value: 'vera-1' })
	await fillDate(form, 'f-createExcuseRequest-dateFrom', '2', '10', '2026')
	await form.fire(field('reasonKind'), 'change', { value: 'illness' })
	await form.fire(form.find('schema-form'), 'submit')

	assert.deepEqual(api.calls.created, [
		{
			learnerRef: 'vera-1',
			dateFrom: '2026-10-02',
			reason: '',
			reasonKind: 'illness',
		},
	])
	assert.equal(
		form.textOf(form.find('schema-form-done')),
		'The school has your report.',
	)
	assert.equal(form.emitted.submitted.length, 1)
	assert.equal(field('dateFrom').props.value, '', 'the form is empty again')
})

test('a refused save says so in words', async () => {
	const api = fakeApi({ created: { ok: false, status: 400 } })
	const action = { id: 'a', type: 'create', fields: ['title'], fieldConfigs: {} }
	const form = await mountSfc(FORM, {
		action,
		api,
		t: (key) =>
			key === 'Saving did not work.' ? 'Opslaan is niet gelukt.' : key,
	})
	await form.fire(form.find('schema-form'), 'submit')
	assert.equal(
		form.textOf(form.find('schema-form-error')),
		'Opslaan is niet gelukt.',
	)
})

test('the helpers: required checks, the body and a datetime value', () => {
	const action = absenceAction()
	assert.deepEqual(
		Object.keys(fieldErrors(action, { reasonKind: ' ' }, {}, null)).sort(),
		['dateFrom', 'learnerRef', 'reasonKind'],
	)
	assert.deepEqual(
		formBody(
			{ fields: ['a', 'up'], fieldConfigs: { up: { type: 'file' } } },
			{ a: 'x', up: 'ignored', sneaked: 'y' },
		),
		{ a: 'x' },
	)
	assert.equal(
		sentValue('datetime-local', '2026-10-02T09:30'),
		new Date('2026-10-02T09:30').toISOString(),
	)
	assert.equal(sentValue('text', null), '')
})

test('a required select with one option starts on it, and can still be changed', async () => {
	const api = fakeApi()
	const form = await mountSfc(FORM, { action: absenceAction(), api })
	await form.flush()
	const child = () =>
		form.findAll((n) => n.props.id === 'f-createExcuseRequest-learnerRef')[0]
	assert.equal(child().props.value, 'vera-1', 'the only child is preselected')
	assert.match(
		form.textOf(form.find('schema-field-learnerRef')),
		/Choose an option Vera/,
		'the select stays a select with its placeholder',
	)
	const kind = form.findAll(
		(n) => n.props.id === 'f-createExcuseRequest-reasonKind',
	)[0]
	assert.equal(kind.props.value, '', 'two options: nothing is chosen for you')

	await form.fire(child(), 'change', { value: '' })
	assert.equal(child().props.value, '', 'the resident can clear it')
	await form.fire(child(), 'change', { value: 'vera-1' })

	const field = (name) =>
		form.findAll((n) => n.props.id === `f-createExcuseRequest-${name}`)[0]
	await fillDate(form, 'f-createExcuseRequest-dateFrom', '2', '10', '2026')
	await form.fire(field('reasonKind'), 'change', { value: 'illness' })
	await form.fire(form.find('schema-form'), 'submit')
	assert.equal(api.calls.created.length, 1)
	assert.equal(api.calls.created[0].learnerRef, 'vera-1')
	assert.equal(
		child().props.value,
		'vera-1',
		'the next report starts on the child again',
	)
	assert.equal(field('dateFrom').props.value, '')
})

test('a guardian with two children picks one', async () => {
	const api = fakeApi({
		options: [
			{ value: 'vera-1', label: 'Vera' },
			{ value: 'sam-2', label: 'Sam' },
		],
	})
	const form = await mountSfc(FORM, { action: absenceAction(), api })
	await form.flush()
	const child = form.findAll(
		(n) => n.props.id === 'f-createExcuseRequest-learnerRef',
	)[0]
	assert.equal(child.props.value, '')
})

test('the single option helper: required selects only, never over a choice', () => {
	const action = absenceAction()
	const one = { learnerRef: [{ value: 'vera-1', label: 'Vera' }] }
	assert.equal(
		withSingleOptions(action, { learnerRef: '' }, one).learnerRef,
		'vera-1',
	)
	assert.equal(
		withSingleOptions(action, { learnerRef: 'other' }, one).learnerRef,
		'other',
		'a choice is kept',
	)
	const optional = {
		...action,
		fieldConfigs: { ...action.fieldConfigs, learnerRef: { label: 'Child' } },
	}
	assert.equal(withSingleOptions(optional, { learnerRef: '' }, one).learnerRef, '')
	assert.equal(withSingleOptions(action, { learnerRef: '' }, {}).learnerRef, '')
	assert.equal(
		withSingleOptions(
			action,
			{ learnerRef: '' },
			{ learnerRef: [{ value: '', label: 'None' }] },
		).learnerRef,
		'',
	)
	assert.equal(
		withSingleOptions(
			{
				fields: ['up'],
				fieldConfigs: { up: { type: 'file', required: true } },
			},
			{ up: '' },
			{ up: [{ value: 'x', label: 'X' }] },
		).up,
		'',
		'a file field is never filled',
	)
})

test('the shell registry runs slice c, and every slice c string is in both shared bundles', async () => {
	await import('../src/site/pages/registry.js')
	const { blockSlotLoader } =
		await import('../src/site/pages/collections/blockSlots.js')
	for (const name of ['action', 'rowAction', 'proposals', 'attachedActions']) {
		assert.equal(typeof blockSlotLoader(name), 'function', name)
	}
	const { readFileSync } = await import('node:fs')
	const { default: strings } = await import('../src/site/pages/c/strings.js')
	for (const lang of ['nl', 'en']) {
		const bundle = JSON.parse(
			readFileSync(
				new URL(`../src/shared/i18n/${lang}.json`, import.meta.url),
				'utf8',
			),
		)
		for (const [key, text] of Object.entries(strings[lang])) {
			assert.equal(bundle[key], text, `${lang}: ${key}`)
			assert.doesNotMatch(text, /—/, 'no em-dash')
		}
	}
})
