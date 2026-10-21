#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// form-prefill-notice.spec.mjs: a form that filled in fields from the
// resident's own account says so on the step that shows them, as the
// WooVerzoekGegevens and FormulierFouten boards draw it ("Wij vulden al iets
// voor u in"), so the resident knows where the answer came from and that
// they may change it. A form that filled nothing says nothing.
//
// Usage:
//   node --test tests/site-look/form-prefill-notice.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { filledFields } from '../../src/site/components/forms/profilePrefill.js'
import { mountSfc } from '../support/mount-sfc.mjs'

const FORM = 'src/site/components/c/SchemaForm.vue'
const NL = JSON.parse(
	readFileSync(new URL('../../src/shared/i18n/nl.json', import.meta.url), 'utf8'),
)

/**
 * The Dutch translator.
 *
 * @param {string} key The English source string.
 * @param {object} [vars] Placeholder values.
 * @return {string} The text.
 */
function t(key, vars = {}) {
	return (NL[key] ?? key).replace(/\{(\w+)\}/g, (_, name) =>
		String(vars[name] ?? ''),
	)
}

/**
 * A request in two steps: the question, then the requester's details.
 *
 * @return {object} The action.
 */
function action() {
	return {
		id: 'startWoo',
		label: 'Woo-verzoek',
		fields: ['vraag', 'naam', 'email'],
		fieldConfigs: {
			vraag: { label: 'Uw vraag', required: true },
			naam: { label: 'Naam', prefill: 'name' },
			email: { label: 'E-mailadres', prefill: 'email' },
		},
		steps: [
			{ id: 'verzoek', title: 'Uw verzoek', fields: ['vraag'] },
			{ id: 'gegevens', title: 'Uw gegevens', fields: ['naam', 'email'] },
			{ id: 'controle', title: 'Controleren', fields: [], review: true },
		],
	}
}

/**
 * Mount the form with a profile.
 *
 * @param {object|null} profile What `getDetails` answers.
 * @return {Promise<object>} The mounted form.
 */
async function mount(profile) {
	globalThis.document = {
		title: 'Woo',
		getElementById: () => null,
		createElement: () => ({}),
		documentElement: { lang: 'nl' },
	}
	const form = await mountSfc(FORM, {
		action: action(),
		api: { fetchOptions: async () => [], getDetails: async () => profile },
		t,
	})
	await form.flush()
	return form
}

/**
 * Answer the first step and go on.
 *
 * @param {object} form The mounted form.
 * @return {Promise<void>}
 */
async function toDetails(form) {
	const input = form.findAll((n) => n.props.id === 'f-startWoo-vraag')[0]
	await form.fire(input, 'input', { value: 'De stukken over het fietspad' })
	await form.fire(form.find('schema-form'), 'submit')
	await form.flush()
}

test('the fields the profile filled are the empty ones that got a value', () => {
	assert.deepEqual(
		filledFields(
			{ naam: '', email: 'a@b.nl' },
			{ naam: 'Sanne', email: 'a@b.nl' },
			action(),
		),
		['naam'],
	)
})

test('the step with prefilled fields says where they came from', async () => {
	const form = await mount({ displayName: 'Sanne de Vries', email: '' })
	assert.ok(
		form.find('schema-form-prefilled') === null,
		'not on the question step',
	)
	await toDetails(form)
	const notice = form.find('schema-form-prefilled')
	assert.ok(notice !== null, 'on the details step')
	assert.match(form.textOf(notice), /Wij vulden al iets voor u in/)
	assert.match(form.textOf(notice), /Pas het hier aan voor dit verzoek\./)
})

test('a form that filled nothing says nothing', async () => {
	const form = await mount(null)
	await toDetails(form)
	assert.ok(form.find('schema-form-prefilled') === null)
})
