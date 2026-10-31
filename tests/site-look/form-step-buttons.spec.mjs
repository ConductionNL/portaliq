#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// form-step-buttons.spec.mjs: the buttons under a form in steps, as the
// WooVerzoek, FormulierHulp and FormulierFouten boards draw them. A rule
// above them; "Annuleren" where there is no step to go back to, "Vorige
// stap" with a chevron back on a later step, and "Volgende stap" with a
// chevron forward at the end of the row. A form only offers "Annuleren"
// when its host says where that leads (`cancellable`).
//
// Usage:
//   node --test tests/site-look/form-step-buttons.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { mountSfc } from '../support/mount-sfc.mjs'

const FORM = 'src/site/components/c/SchemaForm.vue'
const NL = JSON.parse(
	readFileSync(new URL('../../src/shared/i18n/nl.json', import.meta.url), 'utf8'),
)

/**
 * The site's Dutch translator.
 *
 * @param {string} key The English source string.
 * @param {object} [vars] Placeholder values.
 * @return {string} The Dutch text.
 */
function t(key, vars = {}) {
	let text = NL[key] ?? key
	for (const [name, value] of Object.entries(vars)) {
		text = text.split(`{${name}}`).join(String(value))
	}
	return text
}

/**
 * A Woo request in two steps and a review.
 *
 * @return {object} The action.
 */
function action() {
	return {
		id: 'startWooVerzoek',
		label: 'Woo-verzoek',
		fields: ['vraag', 'naam'],
		fieldConfigs: {
			vraag: { label: 'Welke informatie wilt u ontvangen?', size: 'full' },
			naam: { label: 'Naam' },
		},
		steps: [
			{ id: 'verzoek', title: 'Uw verzoek', fields: ['vraag'] },
			{ id: 'gegevens', title: 'Uw gegevens', fields: ['naam'] },
			{
				id: 'controle',
				title: 'Controleren en versturen',
				fields: [],
				review: true,
			},
		],
	}
}

/**
 * Mount the form.
 *
 * @param {object} props Extra props.
 * @return {Promise<object>} The mounted form.
 */
async function mount(props = {}) {
	globalThis.document = {
		title: 'Woo',
		getElementById: () => null,
		createElement: () => ({}),
		documentElement: { lang: 'nl' },
	}
	const form = await mountSfc(FORM, {
		action: action(),
		api: { fetchOptions: async () => [] },
		t,
		...props,
	})
	await form.flush()
	return form
}

test('a cancellable form offers "Annuleren" on its first step and says so', async () => {
	const form = await mount({ cancellable: true })
	const cancel = form.find('schema-form-cancel')
	assert.ok(cancel !== null, 'the cancel button')
	assert.equal(form.textOf(cancel), 'Annuleren')
	assert.equal(cancel.props.type, 'button', 'it never submits')
	await form.fire(cancel, 'click')
	assert.equal((form.emitted.cancel || []).length, 1)
})

test('a later step goes back instead of out', async () => {
	const form = await mount({ cancellable: true })
	await form.fire(form.find('schema-form'), 'submit')
	await form.flush()
	assert.ok(form.find('schema-form-cancel') === null)
	assert.ok(form.find('schema-form-previous') !== null)
})

test('a form whose host gives no way out shows no "Annuleren"', async () => {
	const form = await mount()
	assert.ok(form.find('schema-form-cancel') === null)
})

test('"Volgende stap" carries a chevron forward and stands at the end of a ruled row', async () => {
	const form = await mount({ cancellable: true })
	const next = form.find('schema-form-next')
	assert.ok(
		next.children.some((c) => c.tag === 'svg'),
		'the chevron',
	)
	const row = next.parent
	assert.match(String(row.props.class), /pq-schema-form__buttons--steps/)
	const source = readFileSync(new URL(`../../${FORM}`, import.meta.url), 'utf8')
	const css = source.slice(source.indexOf('<style')).replace(/\s+/g, ' ')
	assert.match(
		css,
		/\.pq-schema-form__buttons--steps \{[^}]*border-block-start: 1px solid/,
	)
	assert.match(
		css,
		/\.pq-schema-form__buttons--steps \.pq-schema-form__forward \{[^}]*margin-inline-start: auto/,
	)
})
