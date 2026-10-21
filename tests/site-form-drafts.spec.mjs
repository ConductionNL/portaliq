// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-multi-step-forms T8 (REQ-SMF-012, REQ-SMF-021) and
// submit-creates-the-case-directly 5.1-5.4: a create action with `draft`
// offers "Opslaan en later verdergaan", saves the answers through the portal
// api as a draft of the record itself, shows it as Voorlopig (decision 200),
// uploads picked files into the draft, shows a wrong value on its field,
// opens a resumed draft on the first step with a gap, and leaves sending the
// draft to the server (no delete afterwards).
//
// Usage:
//   node --test tests/site-form-drafts.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { landingStep, retentionDate } from '../src/site/components/forms/steps.js'
import { mountSfc } from './support/mount-sfc.mjs'

const FORM = 'src/site/components/c/SchemaForm.vue'

const ACTION = {
	id: 'woo-request',
	appId: 'dossiq',
	type: 'create',
	register: 'dossiq',
	schema: 'woo',
	fields: ['onderwerp', 'omschrijving'],
	fieldConfigs: {
		onderwerp: { label: 'Onderwerp', required: true },
		omschrijving: { label: 'Omschrijving', required: true },
	},
	steps: [
		{ id: 'one', title: 'Onderwerp', fields: ['onderwerp'] },
		{ id: 'two', title: 'Omschrijving', fields: ['omschrijving'] },
	],
	draft: { retentionDays: 30 },
}

/**
 * A portal api double that records the draft calls.
 *
 * @param {object|null} stored The draft getDraft answers.
 * @param {object|null} refusal What saveDraft answers instead of keeping it.
 * @return {object} The api and its call log.
 */
function fakeApi(stored = null, refusal = null) {
	const calls = { saved: [], discarded: [], created: [], uploaded: [] }
	return {
		calls,
		async fetchOptions() {
			return []
		},
		async getDraft() {
			return stored
		},
		async saveDraft(app, id, draft) {
			calls.saved.push({ app, id, draft })
			if (refusal) {
				return refusal
			}
			return {
				...draft,
				id: 'draft-1',
				expiresAt: '2026-11-01T12:00:00+00:00',
			}
		},
		async discardDraft(app, id) {
			calls.discarded.push({ app, id })
			return true
		},
		async createObject(action, body) {
			calls.created.push(body)
			return { ok: true, object: { id: 'new-1', ...body } }
		},
		async uploadFieldFile(action, id, field, file) {
			calls.uploaded.push({ id, field, name: file.name })
			return { ok: true }
		},
	}
}

test('landing step: the first step with a missing answer, else the review', () => {
	const flow = [
		{ fields: ['a'], review: false },
		{ fields: ['b'], review: false },
		{ fields: [], review: true },
	]
	const check = (answers) => (fields) =>
		Object.fromEntries(fields.filter((f) => !answers[f]).map((f) => [f, 'x']))
	assert.equal(
		landingStep(flow, check({ a: '1' }), () => true),
		1,
	)
	assert.equal(
		landingStep(flow, check({ a: '1', b: '2' }), () => true),
		2,
	)
	assert.equal(
		landingStep(flow, check({}), () => true),
		0,
	)
})

test('retention date reads the saved date, and nothing for a bad one', () => {
	assert.match(retentionDate('2026-11-01T12:00:00+00:00', 'nl'), /november 2026/)
	assert.equal(retentionDate('nope', 'nl'), '')
})

test('the save button shows with a draft declared, and saves answers and step', async () => {
	const api = fakeApi()
	const form = await mountSfc(FORM, { action: ACTION, api, locale: 'nl' })
	await form.flush()

	const button = form.find('schema-form-save-draft')
	assert.ok(button, 'the button shows')
	assert.match(form.textOf(button), /Save and continue later/)
	const el = form.findAll((n) => n.props.id === 'f-woo-request-onderwerp')[0]
	await form.fire(el, 'input', { value: 'Rotonde' })
	await form.fire(button, 'click')
	await form.flush()
	// The save dialog first (REQ-FSR-001), then "Opslaan en stoppen".
	await form.fire(form.find('form-save-draft-save'), 'click')
	await form.flush()

	assert.equal(api.calls.saved.length, 1)
	assert.equal(api.calls.saved[0].app, 'dossiq')
	assert.equal(api.calls.saved[0].draft.step, 'one')
	assert.equal(
		api.calls.saved[0].draft.retentionDays,
		undefined,
		'the server decides the retention',
	)
	assert.equal(api.calls.saved[0].draft.answers.onderwerp, 'Rotonde')
	assert.match(form.textOf(form.find('schema-form-draft-status')), /Provisional/)
})

test('a wrong value is refused on its field and nothing reads as saved', async () => {
	const api = fakeApi(null, {
		saved: false,
		status: 422,
		errors: { onderwerp: 'Fill in words, not a number.' },
	})
	const form = await mountSfc(FORM, { action: ACTION, api, locale: 'nl' })
	await form.flush()

	await form.fire(form.find('schema-form-save-draft'), 'click')
	await form.flush()
	await form.fire(form.find('form-save-draft-save'), 'click')
	await form.flush()

	assert.match(form.text(), /Fill in words, not a number\./)
	assert.equal(form.find('schema-form-draft-status'), null)
})

test('picked files are uploaded into the draft', async () => {
	const action = {
		...ACTION,
		fields: [...ACTION.fields, 'bijlage'],
		fieldConfigs: {
			...ACTION.fieldConfigs,
			bijlage: { label: 'Bijlage', type: 'file' },
		},
		steps: [
			...ACTION.steps.slice(0, 1),
			{
				id: 'two',
				title: 'Omschrijving',
				fields: ['omschrijving', 'bijlage'],
			},
		],
	}
	const api = fakeApi()
	const form = await mountSfc(FORM, { action, api, locale: 'nl' })
	await form.flush()
	form.vm.files = { bijlage: [{ name: 'plan.pdf', size: 10 }] }

	await form.fire(form.find('schema-form-save-draft'), 'click')
	await form.flush()
	await form.fire(form.find('form-save-draft-save'), 'click')
	await form.flush()

	assert.deepEqual(api.calls.uploaded, [
		{ id: 'draft-1', field: 'bijlage', name: 'plan.pdf' },
	])
	assert.deepEqual(
		form.vm.files,
		{},
		'an attached file is not uploaded again on send',
	)
})

test('no draft declared: no save button', async () => {
	const { draft, ...plain } = ACTION
	const form = await mountSfc(FORM, {
		action: plain,
		api: fakeApi(),
		locale: 'nl',
	})
	await form.flush()
	assert.equal(form.find('schema-form-save-draft'), null)
})

test('sending the action leaves the draft to the server', async () => {
	const api = fakeApi()
	const form = await mountSfc(FORM, {
		action: { ...ACTION, steps: undefined },
		api,
		locale: 'nl',
	})
	await form.flush()
	for (const field of ['onderwerp', 'omschrijving']) {
		const el = form.findAll((n) => n.props.id === `f-woo-request-${field}`)[0]
		await form.fire(el, 'input', { value: 'x' })
	}
	await form.fire(form.find('schema-form'), 'submit')
	await form.flush()

	assert.equal(api.calls.created.length, 1)
	assert.deepEqual(api.calls.discarded, [])
})

test('a resumed draft opens on the step with the gap', async () => {
	const api = fakeApi({
		step: 'one',
		expiresAt: '2026-11-01T12:00:00+00:00',
		answers: { onderwerp: 'Rotonde' },
	})
	const form = await mountSfc(FORM, { action: ACTION, api, locale: 'nl' })
	await form.flush()

	// The form asks first (REQ-FSR-002), then continues with the answers.
	assert.ok(form.find('form-resume-draft-dialog'), 'the resume question shows')
	await form.fire(form.find('form-resume-draft-resume'), 'click')
	await form.flush()

	assert.match(form.textOf(form.find('schema-form-step-heading')), /2 of 3/)
	const el = form.findAll((n) => n.props.id === 'f-woo-request-omschrijving')[0]
	assert.ok(el, 'the gap is on screen')
})

test('an endpoint action that declares a draft asks for none: the server keeps no draft of it (REQ-RR3-005)', async () => {
	const asked = []
	const api = {
		async fetchOptions() {
			return []
		},
		async getDraft(app, id) {
			asked.push(['get', app, id])
			return null
		},
		async saveDraft(app, id) {
			asked.push(['save', app, id])
			return null
		},
	}
	const endpoint = {
		...ACTION,
		type: undefined,
		register: undefined,
		schema: undefined,
		endpoint: '/index.php/apps/dossiq/api/portal/woo-verzoek',
	}
	const form = await mountSfc(FORM, {
		action: endpoint,
		api,
		send: async () => ({ ok: true }),
	})
	await form.flush()
	assert.deepEqual(asked, [], 'no draft read on open')
	assert.equal(form.find('schema-form-save-draft'), null, 'no save button')

	const create = await mountSfc(FORM, { action: ACTION, api })
	await create.flush()
	assert.deepEqual(
		asked,
		[['get', 'dossiq', 'woo-request']],
		'a create action still resumes',
	)
})
