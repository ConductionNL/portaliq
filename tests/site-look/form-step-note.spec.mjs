#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// form-step-note.spec.mjs: a step may carry a note, shown as an info alert
// after its questions, as the WooVerzoek and FormulierHulp boards draw "Is de
// informatie misschien al openbaar?" under the question. Only on its own
// step; a note without words is left out.
//
// Usage:
//   node --test tests/site-look/form-step-note.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { flowSteps } from '../../src/site/components/forms/steps.js'
import { mountSfc } from '../support/mount-sfc.mjs'

const FORM = 'src/site/components/c/SchemaForm.vue'
const NOTE = {
	title: 'Is de informatie misschien al openbaar?',
	text: 'Zoek eerst in de Woo-publicaties. Dan hebt u het meteen.',
}
const TITLES = { other: 'Vraag', review: 'Controleren' }

/**
 * A request in two steps, the first with a note.
 *
 * @return {object} The action.
 */
function action() {
	return {
		id: 'startWoo',
		label: 'Woo-verzoek',
		fields: ['vraag', 'naam'],
		fieldConfigs: {
			vraag: { label: 'Welke informatie wilt u ontvangen?', required: true },
			naam: { label: 'Naam' },
		},
		steps: [
			{ id: 'verzoek', title: 'Uw verzoek', fields: ['vraag'], note: NOTE },
			{ id: 'gegevens', title: 'Uw gegevens', fields: ['naam'] },
			{ id: 'controle', title: 'Controleren', fields: [], review: true },
		],
	}
}

test('a step keeps a note with words and drops one without', () => {
	const flow = flowSteps(
		[
			{ id: 'a', fields: ['x'], note: NOTE },
			{ id: 'b', fields: ['y'], note: { title: ' ', text: '' } },
			{ id: 'c', fields: ['z'], note: 'tekst' },
		],
		TITLES,
	)
	assert.deepEqual(flow[0].note, NOTE)
	assert.ok(!('note' in flow[1]))
	assert.ok(!('note' in flow[2]))
})

test('the note shows after the questions of its own step only', async () => {
	globalThis.document = {
		title: 'Woo',
		getElementById: () => null,
		createElement: () => ({}),
		documentElement: { lang: 'nl' },
	}
	const form = await mountSfc(FORM, {
		action: action(),
		api: { fetchOptions: async () => [] },
	})
	await form.flush()
	const note = form.find('schema-form-step-note')
	assert.ok(note !== null)
	assert.match(form.textOf(note), /Is de informatie misschien al openbaar\?/)
	const field = form.find('schema-field-vraag')
	const siblings = note.parent.children
	assert.ok(siblings.indexOf(field) < siblings.indexOf(note), 'after the question')
	const input = form.findAll((n) => n.props.id === 'f-startWoo-vraag')[0]
	await form.fire(input, 'input', { value: 'De stukken' })
	await form.fire(form.find('schema-form'), 'submit')
	await form.flush()
	assert.ok(form.find('schema-form-step-note') === null, 'not on the next step')
})
