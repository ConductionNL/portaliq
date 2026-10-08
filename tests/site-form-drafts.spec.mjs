// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-multi-step-forms T8 (REQ-SMF-012, REQ-SMF-021): a create action with
// `draft` offers "Opslaan en later verdergaan", saves the answers and the step
// reached through the portal api, opens a resumed draft on the first step with
// a gap, and deletes the draft when the action was sent.
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
 * @return {object} The api and its call log.
 */
function fakeApi(stored = null) {
	const calls = { saved: [], discarded: [], created: [] }
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
			return { ...draft, expiresAt: '2026-11-01T12:00:00+00:00' }
		},
		async discardDraft(app, id) {
			calls.discarded.push({ app, id })
			return true
		},
		async createObject(action, body) {
			calls.created.push(body)
			return { ok: true, object: { id: 'new-1', ...body } }
		},
		async uploadFieldFile() {
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

	assert.equal(api.calls.saved.length, 1)
	assert.equal(api.calls.saved[0].app, 'dossiq')
	assert.equal(api.calls.saved[0].draft.step, 'one')
	assert.equal(api.calls.saved[0].draft.retentionDays, 30)
	assert.equal(api.calls.saved[0].draft.answers.onderwerp, 'Rotonde')
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

test('sending the action deletes the draft', async () => {
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
	assert.deepEqual(api.calls.discarded, [{ app: 'dossiq', id: 'woo-request' }])
})

test('a resumed draft opens on the step with the gap', async () => {
	const api = fakeApi({
		step: 'one',
		expiresAt: '2026-11-01T12:00:00+00:00',
		answers: { onderwerp: 'Rotonde' },
	})
	const form = await mountSfc(FORM, { action: ACTION, api, locale: 'nl' })
	await form.flush()

	assert.match(form.textOf(form.find('schema-form-step-heading')), /2 of 3/)
	const el = form.findAll((n) => n.props.id === 'f-woo-request-omschrijving')[0]
	assert.ok(el, 'the gap is on screen')
})
