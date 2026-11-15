// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// intake-conditional-questions-and-drafts T05 (REQ-ICQ-001): a published form
// on the site shows a question only while its condition holds, does not ask a
// hidden required question, and sends no answer to a hidden one.
//
// Usage:
//   node --test tests/intake-conditional-site.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { mountSfc } from './support/mount-sfc.mjs'

const INTAKE = 'src/site/components/IntakeFormBlock.vue'

test('a hidden required question is not shown, not asked and not sent', async () => {
	const sent = []
	const render = {
		formName: 'Afval melden',
		fields: [
			{ name: 'soort', label: 'Wat ligt er?', required: true },
			{
				name: 'gewicht',
				label: 'Hoe zwaar is het?',
				required: true,
				visibleWhen: { field: 'soort', op: 'eq', value: 'Grofvuil' },
			},
		],
	}
	globalThis.window = {
		location: { hash: '', pathname: '/site', search: '' },
		history: { replaceState() {} },
		sessionStorage: { getItem: () => null, setItem() {}, removeItem() {} },
		localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
		fetch: async (url, init) => {
			if (String(url).includes('/intake/submit')) {
				sent.push(JSON.parse(init.body))
				return {
					ok: true,
					status: 200,
					json: async () => ({ reference: 'MLD-1', confirmationText: '' }),
				}
			}
			return { ok: true, status: 200, json: async () => render }
		},
	}
	globalThis.document = {
		title: '',
		referrer: '',
		querySelector: () => null,
		getElementById: () => null,
		createElement: () => ({}),
	}
	const form = await mountSfc(INTAKE, { route: 'afval', portal: 'gemeente' })
	await form.flush()
	const formEl = () => form.findAll((n) => n.tag === 'form')[0]

	assert.ok(form.find('intake-field-gewicht') === null, 'hidden at the start')
	await form.fire(form.find('intake-field-soort'), 'input', { value: 'Grofvuil' })
	assert.ok(form.find('intake-field-gewicht') !== null, 'shown once the condition holds')
	await form.fire(form.find('intake-field-soort'), 'input', { value: 'Tuinafval' })
	assert.ok(form.find('intake-field-gewicht') === null, 'hidden again')
	await form.fire(formEl(), 'submit')
	await form.flush()
	assert.equal(sent.length, 1, 'the hidden required question did not block the send')
	assert.equal(sent[0].answers.gewicht ?? '', '')
})

test('the embed form shows a question only while its condition holds and sends no hidden answer', async () => {
	const form = await mountSfc('src/embed/EmbedForm.vue', {
		t: (key) => key,
		fields: [
			{ name: 'soort', label: 'Wat ligt er?' },
			{
				name: 'gewicht',
				label: 'Hoe zwaar is het?',
				visibleWhen: { field: 'soort', op: 'eq', value: 'Grofvuil' },
			},
		],
	})
	await form.flush()
	assert.ok(form.find('embed-field-gewicht') === null)
	await form.fire(form.find('embed-field-soort'), 'input', { value: 'Grofvuil' })
	assert.ok(form.find('embed-field-gewicht') !== null)
	await form.fire(form.find('embed-field-gewicht'), 'input', { value: '40 kg' })
	await form.fire(form.find('embed-field-soort'), 'input', { value: 'Tuinafval' })
	assert.ok(form.find('embed-field-gewicht') === null)
	await form.fire(form.find('embed-form'), 'submit')
	assert.deepEqual(form.emitted.submit.at(-1)[0], { soort: 'Tuinafval' })
})
