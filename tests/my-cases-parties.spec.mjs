#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// my-cases-parties.spec.mjs: "Wissel naar" in the acting-for bar on Mijn
// zaken (board huisstijl/NamensBalk, REQ-MZC-001, REQ-SMO-008). A resident who
// acts for someone else chooses whose cases the list shows: themself, one
// party, or everyone; everyone reads each and lists a case once.
//
// Usage:
//   node --test tests/my-cases-parties.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { actingFor } from '../src/site/components/e/actingFor.js'
import {
	barOptions,
	barSentence,
	choiceOf,
} from '../src/site/components/mijn/actingForChoice.js'
import { mijnTranslator } from '../src/site/components/mijn/rows.js'
import { EVERYONE, mergedAnswer, readsFor } from '../src/site/pages/e/caseParties.js'
import { instance, t } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const MANDATES = [{ id: 'mandate-henk', label: 'Henk de Vries' }]
const nl = mijnTranslator(null, 'nl')

/**
 * A case row as the case list answers it.
 *
 * @param {string} id The case id.
 * @return {object} The row.
 */
function row(id) {
	return { id, title: id, _source: { appId: 'dossiq', collection: 'cases' } }
}

test('the choices are the resident by name, each party, and everyone', () => {
	assert.deepEqual(
		barOptions({ mandates: MANDATES, name: 'Linda de Vries' }, nl).map(
			(o) => o.label,
		),
		[
			'Uzelf, Linda de Vries',
			'Henk de Vries',
			'Iedereen voor wie u zaken regelt',
		],
	)
	assert.equal(barOptions({ mandates: [], name: '' }, nl)[0].label, 'Uzelf')
})

test('the bar names the party, oneself, or everyone', () => {
	assert.equal(
		barSentence({ choice: 'mandate-henk', party: 'Henk de Vries' }, nl),
		'U regelt nu zaken voor Henk de Vries.',
	)
	assert.equal(
		barSentence({ choice: 'self', party: '' }, nl),
		'U regelt nu uw eigen zaken.',
	)
	assert.equal(
		barSentence({ choice: EVERYONE, party: '' }, nl),
		'U ziet nu de zaken van iedereen voor wie u zaken regelt.',
	)
	assert.equal(choiceOf({ id: 'self', chosen: false }), EVERYONE)
	assert.equal(choiceOf({ id: 'mandate-henk', chosen: true }), 'mandate-henk')
})

test('everyone reads the resident and every mandate; a party reads itself', () => {
	assert.deepEqual(readsFor(EVERYONE, MANDATES), ['self', 'mandate-henk'])
	assert.deepEqual(readsFor('mandate-henk', MANDATES), ['mandate-henk'])
	assert.deepEqual(readsFor('', MANDATES), ['self'])
})

test('the answers merge into one list with each case once', () => {
	const merged = mergedAnswer([
		{ ok: true, cases: [row('a')], mandates: MANDATES },
		{ ok: true, cases: [row('a'), row('b')] },
		{ ok: false, error: 'forbidden' },
	])
	assert.deepEqual(
		merged.cases.map((c) => c.id),
		['a', 'b'],
	)
	assert.deepEqual(merged.mandates, MANDATES)
	assert.equal(
		mergedAnswer([
			{ ok: false, error: 'x' },
			{ ok: true, cases: [] },
		]).ok,
		false,
	)
})

test('Mijn zaken shows the acting-for bar only to a resident who holds a mandate', async () => {
	const MyCasesPage = await loadSfc('src/site/pages/e/MyCasesPage.vue')
	const props = {
		api: {},
		t,
		locale: 'nl',
		session: { displayName: 'Linda de Vries' },
		portal: { myCases: { mandatesHref: '/mijn/machtigingen' } },
		initialData: { ok: true, cases: [] },
	}

	actingFor.mandates = []
	assert.doesNotMatch(
		await renderComponent(MyCasesPage, props),
		/mijn-acting-for-bar/,
	)

	actingFor.mandates = MANDATES
	actingFor.chosen = false
	const html = await renderComponent(MyCasesPage, props)
	assert.match(html, /<label for="pq-cases-parties"[^>]*>Wissel naar<\/label>/)
	assert.match(html, /U ziet nu de zaken van iedereen voor wie u zaken regelt\./)
	assert.match(html, /Uzelf, Linda de Vries/)
	assert.match(html, /<option[^>]*translate="no"[^>]*>\s*Henk de Vries/)
	assert.match(
		html,
		/<option[^>]*selected[^>]*>\s*Iedereen voor wie u zaken regelt/,
	)
	assert.match(
		html,
		/href="\/mijn\/machtigingen"[^>]*>\s*Toegang tot zaken regelen/,
	)
	assert.doesNotMatch(html, /Zaken van/)
	actingFor.mandates = []
})

test('choosing everyone reads every party; choosing back reads once more', async () => {
	const MyCasesPage = await loadSfc('src/site/pages/e/MyCasesPage.vue')
	const asked = []
	actingFor.mandates = MANDATES
	actingFor.id = 'self'
	// A party chosen earlier this session: the page opens on it.
	actingFor.chosen = true
	const page = instance(MyCasesPage, {
		t,
		api: {
			fetchMyCases: async (id) => {
				asked.push(id)
				return { ok: true, cases: [row(id)], mandates: MANDATES }
			},
		},
	})

	page.chooseParty(EVERYONE)
	await new Promise((resolve) => setTimeout(resolve, 0))
	assert.deepEqual(asked, ['self', 'mandate-henk'])
	assert.deepEqual(
		page.data.cases.map((c) => c.id),
		['self', 'mandate-henk'],
	)
	assert.equal(page.choice, EVERYONE)

	page.chooseParty('self')
	await new Promise((resolve) => setTimeout(resolve, 0))
	assert.deepEqual(asked, ['self', 'mandate-henk', 'self'])
	assert.equal(page.choice, 'self')
	actingFor.mandates = []
	actingFor.chosen = false
})

test('without a choice Mijn zaken opens on everyone and reads every mandate the first answer names', async () => {
	const MyCasesPage = await loadSfc('src/site/pages/e/MyCasesPage.vue')
	const asked = []
	// A first visit: nothing knows the mandates yet.
	actingFor.mandates = []
	actingFor.id = 'self'
	actingFor.chosen = false
	const page = instance(MyCasesPage, {
		t,
		api: {
			fetchMyCases: async (id) => {
				asked.push(id)
				return { ok: true, cases: [row(id)], mandates: MANDATES }
			},
		},
	})

	assert.equal(page.choice, EVERYONE)
	await page.load()
	assert.deepEqual(asked, ['self', 'mandate-henk'])
	assert.deepEqual(
		page.data.cases.map((c) => c.id),
		['self', 'mandate-henk'],
	)
	actingFor.mandates = []
})
