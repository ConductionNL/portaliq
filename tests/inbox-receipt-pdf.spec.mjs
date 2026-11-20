// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// A submission receipt offers its answers as a PDF (capability-rows-without-
// a-requirement REQ-CRG-005). Portaliq's own receipt message, the one with a
// reference, shows "Download as PDF" in the inbox; the file comes from the
// receipt route, which answers only for the resident's own receipt.
//
// @spec openspec/changes/capability-rows-without-a-requirement/specs/supplier-portal/spec.md#requirement-a-submission-receipt-offers-the-answers-as-a-pdf-req-crg-005

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createPortalApi } from '../src/shared/portalApi.js'
import { receiptPdfOf } from '../src/site/pages/inbox/inbox.js'
import { instance, inState, t } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const InboxPage = await loadSfc('src/site/pages/inbox/InboxPage.vue')

const RECEIPT = {
	id: 'r1',
	subject: 'Ontvangstbevestiging, kenmerk WMEBV-2026-0193',
	body: 'Wij hebben uw inzending ontvangen.',
	referenceId: 'WMEBV-2026-0193',
	dataCopy: { onderwerp: 'Fietspad' },
	receivedAt: '2026-10-02T10:00:00Z',
	read: true,
	_source: {
		appId: 'portaliq',
		label: '',
		register: 'portaliq',
		schema: 'portalMessage',
		collection: 'portalMessages',
	},
}

test('only portaliq’s own receipt with a reference offers a PDF', () => {
	assert.deepEqual(receiptPdfOf(RECEIPT), { id: 'r1', name: 'WMEBV-2026-0193.pdf' })
	assert.equal(receiptPdfOf({ ...RECEIPT, referenceId: '' }), null)
	assert.equal(receiptPdfOf({ ...RECEIPT, referenceId: undefined }), null)
	assert.equal(receiptPdfOf({ ...RECEIPT, _source: { ...RECEIPT._source, register: 'dossiq' } }), null)
	assert.equal(receiptPdfOf({ ...RECEIPT, _source: { ...RECEIPT._source, schema: 'portaalBericht' } }), null)
	assert.equal(receiptPdfOf({ ...RECEIPT, id: undefined }), null)
	assert.equal(receiptPdfOf(undefined), null)
	assert.deepEqual(receiptPdfOf({ ...RECEIPT, referenceId: 'A/B 1' }), { id: 'r1', name: 'A-B-1.pdf' })
})

test('the inbox shows the PDF button on a receipt only, in the page language', async () => {
	const html = await renderComponent(
		inState(InboxPage, { loading: false, messages: [RECEIPT] }),
		{ api: {}, t, locale: 'nl' },
	)
	assert.match(html, /data-testid="inbox-row-receipt-pdf">\s*Downloaden als pdf\s*<\/button>/)

	const en = await renderComponent(
		inState(InboxPage, { loading: false, messages: [RECEIPT] }),
		{ api: {}, t, locale: 'en' },
	)
	assert.match(en, /data-testid="inbox-row-receipt-pdf">\s*Download as PDF\s*<\/button>/)

	const letter = await renderComponent(
		inState(InboxPage, { loading: false, messages: [{ ...RECEIPT, referenceId: undefined }] }),
		{ api: {}, t, locale: 'nl' },
	)
	assert.doesNotMatch(letter, /inbox-row-receipt-pdf/)
})

test('pressing it downloads the receipt; a refusal says the download did not work', async () => {
	const calls = []
	const page = instance(InboxPage, {
		api: {
			async downloadReceiptPdf(receipt) {
				calls.push(receipt)
				return { ok: calls.length === 1 }
			},
		},
		t,
	})
	await page.downloadReceipt(RECEIPT)
	assert.deepEqual(calls, [{ id: 'r1', name: 'WMEBV-2026-0193.pdf' }])
	assert.equal(page.downloadFailedFor, null)
	assert.equal(page.downloadingId, null)

	await page.downloadReceipt(RECEIPT)
	assert.equal(page.downloadFailedFor, 'r1')

	await page.downloadReceipt({ ...RECEIPT, referenceId: '' })
	assert.equal(calls.length, 2, 'a message that is no receipt asks nothing')
})

test('the adapter fetches the receipt route with the bearer', async () => {
	const seen = []
	const original = globalThis.fetch
	globalThis.fetch = async (url, init) => {
		seen.push([url, init?.headers || {}])
		return { ok: false, status: 404 }
	}
	try {
		const api = createPortalApi({ apiBase: '/apps/portaliq/portal/api', audience: 'resident' })
		const result = await api.downloadReceiptPdf({ id: 'r 1', name: 'x.pdf' })
		assert.deepEqual(result, { ok: false, status: 404 })
		assert.equal(seen[0][0], '/apps/portaliq/portal/api/receipts/r%201/pdf')
	} finally {
		globalThis.fetch = original
	}
})
