// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// Files that came with a message open (inbox-reply-with-attachments
// REQ-IRA-004, portaliq#702). A dossiq letter with an attachment showed its
// subject only: the inbox never rendered the files. The server now lists them
// as `_files` for an inbox collection that declares `filesDownload`; the site
// inbox shows each one and downloads it through the message's own collection.
//
// @spec openspec/changes/inbox-reply-with-attachments/specs/portal-inbox-reply/spec.md#requirement-files-that-came-with-a-message-open-req-ira-004

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	attachmentsOf,
	downloadCollection,
} from '../src/site/pages/inbox/inbox.js'
import { instance, inState, t } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const InboxPage = await loadSfc('src/site/pages/inbox/InboxPage.vue')

const LETTER = {
	id: 'b1',
	subject: 'Besluit op uw Woo-verzoek',
	body: 'Uw verzoek is toegekend.',
	receivedAt: '2026-10-02T10:00:00Z',
	read: false,
	_source: {
		appId: 'dossiq',
		label: 'Zaken',
		register: 'dossiq',
		schema: 'portaalBericht',
		collection: 'berichten',
	},
	_files: [
		{ id: 41, name: 'besluit.pdf', size: 1200 },
		{ id: '', name: 'no id' },
		null,
	],
}

test('only files with an id are offered', () => {
	assert.deepEqual(attachmentsOf(LETTER), [
		{ id: 41, name: 'besluit.pdf', size: 1200 },
	])
	assert.deepEqual(attachmentsOf({ id: 'x' }), [])
	assert.deepEqual(attachmentsOf({ _files: 'nope' }), [])
	assert.deepEqual(attachmentsOf(undefined), [])
})

test("a download goes through the message's own collection", () => {
	assert.deepEqual(downloadCollection(LETTER), {
		id: 'berichten',
		register: 'dossiq',
		schema: 'portaalBericht',
	})
	assert.equal(downloadCollection({ _source: { register: 'dossiq' } }), null)
	assert.equal(downloadCollection({}), null)
})

test('the inbox lists the files under the message, in the page language', async () => {
	const html = await renderComponent(
		inState(InboxPage, { loading: false, messages: [LETTER] }),
		{ api: {}, t, locale: 'nl' },
	)
	assert.match(html, /Uw verzoek is toegekend\./)
	assert.match(html, /data-testid="inbox-row-files"/)
	assert.match(html, />Bijlagen</)
	assert.match(html, /data-testid="inbox-row-download">besluit\.pdf<\/button>/)
	assert.equal(
		(html.match(/data-testid="inbox-row-download"/g) || []).length,
		1,
		'a file without an id is not offered',
	)

	const plain = await renderComponent(
		inState(InboxPage, {
			loading: false,
			messages: [{ ...LETTER, _files: undefined }],
		}),
		{ api: {}, t, locale: 'en' },
	)
	assert.doesNotMatch(plain, /inbox-row-files/)
})

test('pressing a file downloads it with the message id and its collection', async () => {
	const calls = []
	const page = instance(InboxPage, {
		api: {
			async downloadFile(collection, id, file) {
				calls.push([collection, id, file])
				return { ok: true }
			},
		},
		t,
	})
	await page.download(LETTER, LETTER._files[0])
	assert.deepEqual(calls, [
		[
			{ id: 'berichten', register: 'dossiq', schema: 'portaalBericht' },
			'b1',
			LETTER._files[0],
		],
	])
	assert.equal(page.downloadFailedFor, null)
	assert.equal(page.downloadingId, null)
})

test('a failed download says so on that message', async () => {
	const page = instance(InboxPage, {
		api: {
			async downloadFile() {
				return { ok: false, status: 404 }
			},
		},
		t,
	})
	await page.download(LETTER, LETTER._files[0])
	assert.equal(page.downloadFailedFor, 'b1')

	const html = await renderComponent(
		inState(InboxPage, {
			loading: false,
			messages: [LETTER],
			downloadFailedFor: 'b1',
		}),
		{ api: {}, t, locale: 'nl' },
	)
	assert.match(html, /role="alert">Downloaden is niet gelukt\.<\/p>/)
})
