#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// pdf-download.spec.mjs: "Download as PDF" on a collection that opted in
// (cases-export-own-data-pdf). The button shows only for `exportPdf: true`,
// the api asks the export routes with the bearer, and a list that is too long
// and any other failure say so in words.
//
// Usage:
//   node --test tests/pdf-download.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { createPortalApi } from '../src/shared/portalApi.js'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const BUTTON = 'src/site/components/collections/PdfDownloadButton.vue'
const COLLECTION = { id: 'statements', label: 'Afschriften', register: 'budgetiq', schema: 'statement', exportPdf: true }
function nl (key) {
  return ({
	'Download as PDF': 'Download als pdf',
	'This list is too long for one PDF. Filter it first.': 'Deze lijst is te lang voor één pdf. Filter hem eerst.',
	'The PDF could not be made. Try again later.': 'De pdf kon niet worden gemaakt. Probeer het later opnieuw.',
})[key] || key
}

test('the button shows only for a collection that opted in, and only with an api', async () => {
	const api = { downloadPdf: async () => ({ ok: true }) }
	assert.match(await renderSfc(BUTTON, { collection: COLLECTION, api, t: nl }), /Download als pdf/)
	assert.doesNotMatch(await renderSfc(BUTTON, { collection: { ...COLLECTION, exportPdf: false }, api, t: nl }), /pdf-download/)
	assert.doesNotMatch(await renderSfc(BUTTON, { collection: { ...COLLECTION, exportPdf: 'true' }, api, t: nl }), /pdf-download/)
	assert.doesNotMatch(await renderSfc(BUTTON, { collection: COLLECTION, api: null, t: nl }), /pdf-download/)
})

test('a list that is too long and any other failure say so in words', async () => {
	const screen = await loadSfc(BUTTON)
	const run = async (result, id = '') => {
		const asked = []
		const vm = { collection: COLLECTION, id, t: nl, busy: false, message: '', api: { downloadPdf: async (...args) => { asked.push(args); return result } } }
		await screen.methods.download.call(vm)
		return { vm, asked }
	}

	const ok = await run({ ok: true })
	assert.equal(ok.vm.message, '')
	assert.deepEqual(ok.asked, [[COLLECTION, undefined]], 'no id exports the list')
	assert.equal(ok.vm.busy, false)
	assert.deepEqual((await run({ ok: true }, 'a1')).asked, [[COLLECTION, 'a1']], 'an id exports the record')

	assert.equal((await run({ ok: false, status: 400 })).vm.message, 'Deze lijst is te lang voor één pdf. Filter hem eerst.')
	assert.equal((await run({ ok: false, status: 503 })).vm.message, 'De pdf kon niet worden gemaakt. Probeer het later opnieuw.')
	assert.equal((await run({ ok: false, status: 0 })).vm.message, 'De pdf kon niet worden gemaakt. Probeer het later opnieuw.')
})

test('the api asks the list and the record routes with the bearer', async () => {
	const asked = []
	globalThis.fetch = async (url, init) => {
		asked.push({ url, headers: init.headers })
		return { ok: false, status: 400 }
	}
	try {
		const api = createPortalApi({ apiBase: '/portal/api' }, { getToken: () => 'tok', setToken: () => {} })
		assert.deepEqual(await api.downloadPdf(COLLECTION), { ok: false, status: 400 })
		assert.deepEqual(await api.downloadPdf(COLLECTION, 'a 1'), { ok: false, status: 400 })
	} finally {
		delete globalThis.fetch
	}
	assert.equal(asked[0].url, '/portal/api/collections/budgetiq/statement/export.pdf?collection=statements')
	assert.equal(asked[1].url, '/portal/api/collections/budgetiq/statement/a%201/export.pdf?collection=statements')
	assert.equal(asked[0].headers.Authorization, 'Bearer tok')
})

test('the button sits above the list and on the record', () => {
	const page = readFileSync('src/site/pages/collections/ContributionPage.vue', 'utf8')
	assert.match(page, /<PdfDownloadButton :collection="item.collection" :api="api" :t="tr" \/>/)
	const detail = readFileSync('src/site/components/collections/DetailCard.vue', 'utf8')
	assert.match(detail, /<PdfDownloadButton :collection="collection" :id="rowId" :api="api" :t="t" \/>/)
})
