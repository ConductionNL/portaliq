#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// publication-detail.spec.mjs: a withdrawn publication's link
// (publication-error-reports-and-withheld-notices REQ-PER-003, REQ-PER-004).
// With the portal's withheld notices on, a 410 from the publication read
// shows the date, the public reason and the title only when the answer
// carries one. Off, the same 410 shows the not-found page and nothing of the
// withdrawal.
//
// Usage:
//   node --test tests/publication-detail.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	withdrawalFrom,
	withdrawalLines,
	withheldDocumentsOf,
} from '../src/site/lib/publicationDetail.js'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const BLOCK = 'src/site/components/PublicationDetailBlock.vue'
// The block's translator reads JSON node cannot import bare; the keys stand in.
const STUBS = {
	'../../shared/i18n/index.js': 'export function createTranslator() { return (key) => key }\n',
}
const TOMBSTONE = {
	id: 'pub-1',
	withdrawnAt: '2026-10-01T09:00:00+02:00',
	publicReason: 'Gepubliceerd in strijd met de AVG',
	summary: 'niet tonen',
}

/**
 * Run the block's load() against one answer of the publication read.
 *
 * @param {object|null} publications The site record's `publications`.
 * @param {number} status The read's status.
 * @param {object} body The read's answer.
 * @return {Promise<object>} The component state after the load.
 */
async function loadWith(publications, status, body) {
	const block = await loadSfc(BLOCK, STUBS)
	const asked = []
	globalThis.window = { location: { origin: 'https://portaal.example' } }
	globalThis.fetch = async (url) => {
		asked.push(String(url))
		return { ok: status >= 200 && status < 300, status, json: async () => body }
	}
	const vm = {
		...block.data(),
		subjectId: 'pub-1',
		endpoint: '/index.php/apps/opencatalogi/api/federation/publications',
		publications,
		matchesId: () => true,
		loadDocuments: () => {},
		loadThemeNames: () => {},
		tellSubject: () => {},
	}
	await block.methods.load.call(vm)
	vm.asked = asked
	return vm
}

test('a 410 with notices on shows the withdrawal', async () => {
	const vm = await loadWith({ withheldNotices: true }, 410, TOMBSTONE)
	assert.deepEqual(vm.withdrawal, {
		withdrawnAt: '2026-10-01T09:00:00+02:00',
		publicReason: 'Gepubliceerd in strijd met de AVG',
	})
	assert.equal(vm.error, false)
	assert.equal(vm.publication, null)
	assert.equal(vm.loading, false)

	const lines = withdrawalLines(vm.withdrawal, 'nl')
	assert.equal(lines.sentence, 'Deze publicatie is ingetrokken op 1 oktober 2026')
	assert.equal(lines.reason, 'Gepubliceerd in strijd met de AVG')
	assert.equal(withdrawalLines(vm.withdrawal, 'en').sentence, 'This publication was withdrawn on 1 October 2026')
})

test('a 410 with notices off shows not found', async () => {
	for (const publications of [null, { withheldNotices: false }, { errorReports: true }]) {
		const vm = await loadWith(publications, 410, TOMBSTONE)
		assert.equal(vm.withdrawal, null, JSON.stringify(publications))
		assert.equal(vm.error, true)
		assert.equal(withdrawalFrom(410, TOMBSTONE, publications), null)
	}

	// Any other miss is not found too, notices on or not.
	const missing = await loadWith({ withheldNotices: true }, 404, {})
	assert.equal(missing.withdrawal, null)
	assert.equal(missing.error, true)
})

/**
 * Render the block holding one withdrawal.
 *
 * @param {object} withdrawal What withdrawalFrom() returned.
 * @return {Promise<string>} The HTML.
 */
async function renderWithdrawal(withdrawal) {
	const component = await loadSfc(BLOCK, STUBS)
	const data = component.data
	return renderComponent(
		{
			...component,
			mounted() {},
			data() {
				return { ...data.call(this), loading: false, withdrawal }
			},
		},
		{ subjectId: 'pub-1' },
	)
}

test('a tombstone without a title shows none', async () => {
	const html = await renderWithdrawal(withdrawalFrom(410, TOMBSTONE, { withheldNotices: true }))
	assert.match(html, /data-testid="publication-withdrawn"/)
	assert.match(html, /Deze publicatie is ingetrokken op 1 oktober 2026/)
	assert.match(html, /Gepubliceerd in strijd met de AVG/)
	assert.doesNotMatch(html, /publication-withdrawn-title/)
	assert.doesNotMatch(html, /niet tonen/, 'nothing else of the record')
	assert.doesNotMatch(html, /Fout melden/)

	const withTitle = await renderWithdrawal(
		withdrawalFrom(410, { ...TOMBSTONE, title: 'Besluit parkeerbeleid' }, { withheldNotices: true }),
	)
	assert.match(withTitle, /data-testid="publication-withdrawn-title"/)
	assert.match(withTitle, /Besluit parkeerbeleid/)

	const untitled = withdrawalLines(withdrawalFrom(410, TOMBSTONE, { withheldNotices: true }), 'nl')
	assert.equal(untitled.title, '')

	const titled = withdrawalLines(
		withdrawalFrom(410, { ...TOMBSTONE, title: 'Besluit parkeerbeleid' }, { withheldNotices: true }),
		'nl',
	)
	assert.equal(titled.title, 'Besluit parkeerbeleid')

	// Nothing else of the record travels, and an empty answer still says it was withdrawn.
	assert.deepEqual(Object.keys(withdrawalFrom(410, TOMBSTONE, { withheldNotices: true })).sort(), ['publicReason', 'withdrawnAt'])
	assert.equal(withdrawalLines(withdrawalFrom(410, null, { withheldNotices: true }), 'nl').sentence, 'Deze publicatie is ingetrokken.')
})

// REQ-PER-005 against the PROPOSED contract: opencatalogi's public read does
// not carry withheld documents yet, so the fixture's keys are the proposal.
const WITH_WITHHELD = {
	id: 'pub-1',
	title: 'Besluit op Woo-verzoek',
	withheldDocuments: [
		{ reference: 'Document 4', grounds: [{ code: '5.1.2.e', label: 'Eerbiediging van de persoonlijke levenssfeer' }] },
		{ reference: 'Document 7', grounds: [{ code: '5.2.1', label: 'Persoonlijke beleidsopvattingen' }] },
		{ grounds: [{ code: '5.1.1.c' }] },
	],
}

/**
 * Render the block holding one loaded publication.
 *
 * @param {object|null} publications The site record's `publications`.
 * @return {Promise<string>} The HTML.
 */
async function renderPublication(publications) {
	const component = await loadSfc(BLOCK, STUBS)
	const data = component.data
	return renderComponent(
		{
			...component,
			mounted() {},
			data() {
				return { ...data.call(this), loading: false, publication: WITH_WITHHELD, documents: [] }
			},
		},
		{ subjectId: 'pub-1', publications },
	)
}

test('two withheld documents list their grounds', async () => {
	assert.deepEqual(withheldDocumentsOf(WITH_WITHHELD, { withheldNotices: true }), [
		{ reference: 'Document 4', grounds: [{ code: '5.1.2.e', label: 'Eerbiediging van de persoonlijke levenssfeer' }] },
		{ reference: 'Document 7', grounds: [{ code: '5.2.1', label: 'Persoonlijke beleidsopvattingen' }] },
	])

	const html = await renderPublication({ withheldNotices: true })
	assert.match(html, /Niet openbaar gemaakt/)
	assert.equal((html.match(/data-testid="publication-withheld-document"/g) || []).length, 2)
	assert.match(html, /5\.1\.2\.e: Eerbiediging van de persoonlijke levenssfeer/)
	assert.match(html, /5\.2\.1: Persoonlijke beleidsopvattingen/)
})

test('notices off lists nothing', async () => {
	for (const publications of [null, { withheldNotices: false }, { errorReports: true }]) {
		assert.deepEqual(withheldDocumentsOf(WITH_WITHHELD, publications), [])
		const html = await renderPublication(publications)
		assert.doesNotMatch(html, /publication-withheld/, JSON.stringify(publications))
	}
	assert.deepEqual(withheldDocumentsOf({ withheldDocuments: 'no list' }, { withheldNotices: true }), [])
})
