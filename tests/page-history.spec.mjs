#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// page-history.spec.mjs: the History dialog of the page designer
// (site-page-seo-history-and-media T04, T05, REQ-SPH-003). It lists a page's
// published versions from portaliq's history endpoint, and restoring one
// writes that version's body into the DRAFT, never into `body`, so the live
// page does not change until the editor publishes. It also pins that the
// designer opens the dialog and restores through its draft write.
//
// Usage:
//   node --test tests/page-history.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { createPageHistory, restoredDraft } from '../src/lib/pageHistory.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

const MONDAY = { type: 'grid', widgets: [{ id: 'w1', widgetKey: 'text', props: { text: 'Monday' } }] }
const FRIDAY = { type: 'grid', widgets: [{ id: 'w1', widgetKey: 'text', props: { text: 'Friday' } }] }

test('the versions come from the page history endpoint, newest first as served', async () => {
	const urls = []
	const history = createPageHistory({
		get: async (url) => {
			urls.push(url)
			return {
				data: {
					versions: [
						{ id: 12, publishedAt: '2026-09-25T10:00:00+00:00', by: 'Anna', restorable: true, body: FRIDAY },
						{ id: 7, publishedAt: '2026-09-21T09:00:00+00:00', by: 'Bram', restorable: true, body: MONDAY },
					],
				},
			}
		},
		url: (path, params) => '/apps/portaliq' + path.replace('{id}', params.id),
	})

	const result = await history.load('page-1')

	assert.deepEqual(urls, ['/apps/portaliq/api/pages/page-1/history'])
	assert.equal(result.state, 'ready')
	assert.deepEqual(result.versions.map((v) => v.id), [12, 7])
})

test('no versions reads as empty, a refusal as an error', async () => {
	const empty = await createPageHistory({ get: async () => ({ data: { versions: [] } }), url: (p) => p }).load('p')
	assert.equal(empty.state, 'empty')

	const refused = await createPageHistory({
		get: async () => {
			const error = new Error('HTTP 403')
			error.response = { status: 403, data: { error: 'not_an_editor' } }
			throw error
		},
		url: (p) => p,
	}).load('p')
	assert.equal(refused.state, 'error')
	assert.equal(refused.reason, 'not_an_editor')
})

test('restoring Monday puts Monday in the draft and leaves the published body alone', () => {
	const page = { title: 'Contact', route: '/contact', status: 'published', body: FRIDAY }

	const payload = restoredDraft(page, { id: 7, restorable: true, body: MONDAY })

	assert.deepEqual(payload.draftBody, MONDAY)
	assert.deepEqual(payload.body, FRIDAY)
	assert.equal(payload.status, 'published')
	assert.equal(page.draftBody, undefined, 'the loaded page is not mutated')
})

test('a version without its content cannot be restored', () => {
	assert.throws(() => restoredDraft({ body: FRIDAY }, { id: 9, restorable: false, body: null }))
})

test('the designer opens the History dialog and restores through a draft write', () => {
	const designer = readFileSync(join(ROOT, 'src/views/PageLayoutDesigner.vue'), 'utf8')
	assert.match(designer, /import PageHistoryDialog from '\.\.\/dialogs\/PageHistoryDialog\.vue'/)
	assert.match(designer, /data-testid="designer-history"/)
	assert.match(designer, /restoredDraft\(this\.page, version\)/)

	const dialog = readFileSync(join(ROOT, 'src/dialogs/PageHistoryDialog.vue'), 'utf8')
	assert.match(dialog, /Restore this version/)
	assert.match(dialog, /createPageHistory/)
})
