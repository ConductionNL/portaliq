#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// action-form-help.spec.mjs: "Hulp nodig?" on an action's form too, as the
// FormulierHulp board draws it above the Woo request. The intake forms had it
// (help-texts-and-form-help); a form with a page of its own in Mijn now gets
// the portal's help from the page, merged with the action's own `help`.
//
// Usage:
//   node --test tests/site-look/action-form-help.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { mountSfc } from '../support/mount-sfc.mjs'
import { loadSfc } from '../support/render-sfc.mjs'

const FORM = 'src/site/components/c/SchemaForm.vue'
const HELP = { intro: 'Wij helpen u graag.', phone: '14 020' }

/**
 * Mount a one-question form.
 *
 * @param {object} props Extra props.
 * @param {object} [extra] Extra action keys.
 * @return {Promise<object>} The mounted form.
 */
async function mount(props = {}, extra = {}) {
	globalThis.document = {
		title: 'Woo',
		getElementById: () => null,
		createElement: () => ({}),
		documentElement: { lang: 'nl' },
	}
	const form = await mountSfc(FORM, {
		action: {
			id: 'startWoo',
			label: 'Woo-verzoek indienen',
			fields: ['vraag'],
			fieldConfigs: { vraag: { label: 'Uw vraag' } },
			...extra,
		},
		api: { fetchOptions: async () => [] },
		...props,
	})
	await form.flush()
	return form
}

test('a form with the portal help offers "Hulp nodig?"', async () => {
	const form = await mount({ portalHelp: HELP })
	assert.ok(form.find('form-help-open') !== null)
})

test("an action's own help is enough on its own", async () => {
	const form = await mount({}, { help: { email: 'woo@zuiddrecht.example' } })
	assert.ok(form.find('form-help-open') !== null)
})

test('without help there is no link', async () => {
	const form = await mount()
	assert.ok(form.find('form-help') === null)
})

test('the page hands the portal help to its action blocks', async () => {
	const page = await loadSfc('src/site/pages/collections/ContributionPage.vue')
	assert.ok('portal' in page.props, 'the page takes the portal')
	const block = await loadSfc('src/site/components/c/ActionBlock.vue')
	assert.ok('portalHelp' in block.props, 'the action block takes its help')
})
