#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-api-downloads.spec.mjs: how the portal api saves a download (the
// request with the bearer, the link it clicks, the name it gives and the
// cleanup it defers), reads the news feed and the newsletter archive, builds
// a row action's multipart body and reads "my cases", pinned before those
// copies were folded into shared helpers.
//
// Usage:
//   node --test tests/portal-api-downloads.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createPortalApi, rowActionFormData } from '../src/shared/portalApi.js'

const COLLECTION = { id: 'statements', label: 'Mijn Afschriften 2026!', register: 'budgetiq', schema: 'statement' }

/**
 * A browser that answers every request with one response and records the
 * links the api clicks and the cleanups it defers.
 *
 * @param {object} answer `{ok, status, body}` or `{throws: true}`.
 * @return {object} The calls, links, revoked urls and deferred callbacks.
 */
function browser(answer) {
	const seen = { calls: [], links: [], revoked: [], deferred: [] }
	globalThis.fetch = async (url, init) => {
		seen.calls.push({ url, init })
		if (answer.throws) {
			throw new Error('offline')
		}
		return {
			ok: answer.ok,
			status: answer.status,
			blob: async () => 'BLOB',
			json: async () => {
				if (answer.badJson) {
					throw new Error('not json')
				}
				return answer.body
			},
		}
	}
	globalThis.window = {
		URL: {
			createObjectURL: (blob) => `blob:${blob}`,
			revokeObjectURL: (url) => seen.revoked.push(url),
		},
	}
	globalThis.document = {
		createElement: (tag) => {
			const link = { tag, clicked: 0, removed: 0, click() { this.clicked++ }, remove() { this.removed++ } }
			seen.links.push(link)
			return link
		},
		body: { appendChild: (node) => { node.appended = true } },
	}
	seen.realTimeout = globalThis.setTimeout
	globalThis.setTimeout = (callback, wait) => { seen.deferred.push({ callback, wait }); return 1 }
	return seen
}

/**
 * Put the browser back.
 *
 * @param {object} seen What browser() returned.
 * @return {void}
 */
function restore(seen) {
	globalThis.setTimeout = seen.realTimeout
	delete globalThis.fetch
	delete globalThis.window
	delete globalThis.document
}

const api = () => createPortalApi({ apiBase: '/portal/api' }, { getToken: () => 'tok', setToken: () => {} })

test('a saved download clicks a named link and revokes it ten seconds later', async () => {
	for (const [run, url, name] of [
		[(a) => a.downloadPdf(COLLECTION), '/portal/api/collections/budgetiq/statement/export.pdf?collection=statements', 'mijn-afschriften-2026-.pdf'],
		[(a) => a.downloadPdf({ ...COLLECTION, label: '' }, 'r 1'), '/portal/api/collections/budgetiq/statement/r%201/export.pdf?collection=statements', 'export.pdf'],
		[(a) => a.downloadFile(COLLECTION, 'o/1', { id: 'f 1', name: 'brief.pdf' }), '/portal/api/collections/budgetiq/statement/o%2F1/files/f%201?collection=statements', 'brief.pdf'],
		[(a) => a.downloadFile(COLLECTION, 'o1', { id: 'f1' }), '/portal/api/collections/budgetiq/statement/o1/files/f1?collection=statements', 'download'],
		[(a) => a.downloadCollectionDocument(COLLECTION, 'o1', { id: 'd1', title: 'Notulen' }), '/portal/api/collections/budgetiq/statement/o1/documents/d1', 'Notulen'],
		[(a) => a.downloadCollectionDocument(COLLECTION, 'o1', { id: 'd1' }), '/portal/api/collections/budgetiq/statement/o1/documents/d1', 'download'],
	]) {
		const seen = browser({ ok: true, status: 200 })
		try {
			assert.deepEqual(await run(api()), { ok: true })
			assert.equal(seen.deferred.length, 1)
			assert.equal(seen.links[0].removed, 0)
			seen.deferred[0].callback()
		} finally {
			restore(seen)
		}
		assert.equal(seen.calls.length, 1)
		assert.equal(seen.calls[0].url, url)
		assert.deepEqual(seen.calls[0].init, { headers: { Authorization: 'Bearer tok' } })
		assert.equal(seen.links.length, 1)
		const link = seen.links[0]
		assert.equal(link.tag, 'a')
		assert.equal(link.href, 'blob:BLOB')
		assert.equal(link.download, name)
		assert.equal(link.appended, true)
		assert.equal(link.clicked, 1)
		assert.equal(seen.deferred[0].wait, 10000)
		assert.equal(link.removed, 1)
		assert.deepEqual(seen.revoked, ['blob:BLOB'])
	}
})

test('a citizen document is saved from the case route', async () => {
	const seen = browser({ ok: true, status: 200 })
	try {
		await api().downloadCitizenDocument(COLLECTION, 'c 1', { id: 'd1', title: 'Besluit' })
	} finally {
		restore(seen)
	}
	assert.match(seen.calls[0].url, /\/c%201\/documents\/d1$/)
	assert.equal(seen.links[0].download, 'Besluit')
})

test('a refused or failed download clicks nothing and says the status', async () => {
	for (const run of [(a) => a.downloadPdf(COLLECTION), (a) => a.downloadFile(COLLECTION, 'o1', { id: 'f1' }), (a) => a.downloadCollectionDocument(COLLECTION, 'o1', { id: 'd1' })]) {
		const refused = browser({ ok: false, status: 403 })
		try {
			assert.deepEqual(await run(api()), { ok: false, status: 403 })
		} finally {
			restore(refused)
		}
		assert.equal(refused.links.length, 0)
		const failed = browser({ throws: true })
		try {
			assert.deepEqual(await run(api()), { ok: false, status: 0 })
		} finally {
			restore(failed)
		}
	}
})

test('the news feed and the newsletter archive read a list or nothing', async () => {
	for (const [name, path] of [['fetchNewsFeed', '/api/news/feed'], ['fetchNewsletterArchive', '/api/newsletters/archive']]) {
		const ok = browser({ ok: true, status: 200, body: [{ id: 1 }] })
		let list
		try {
			list = await createPortalApi({ apiBase: '/portal/api', appRoot: '/apps/portaliq' }, { getToken: () => 'tok', setToken: () => {} })[name]()
		} finally {
			restore(ok)
		}
		assert.deepEqual(list, [{ id: 1 }])
		assert.match(ok.calls[0].url, new RegExp(`${path.replace(/\//g, '\\/')}$`))
		assert.deepEqual(ok.calls[0].init, { headers: { Accept: 'application/json', Authorization: 'Bearer tok' } })
		for (const answer of [{ ok: true, status: 200, body: { items: [] } }, { ok: false, status: 403 }, { throws: true }, { ok: true, status: 200, badJson: true }]) {
			const seen = browser(answer)
			try {
				assert.deepEqual(await api()[name](), [])
			} finally {
				restore(seen)
			}
		}
	}
})

test('a row action with files is multipart: answers as fields, an object as key[name], files under field[]', () => {
	assert.equal(rowActionFormData({ a: 1 }, null), null)
	assert.equal(rowActionFormData({ a: 1 }, { field: '', files: [{}] }), null)
	assert.equal(rowActionFormData({ a: 1 }, { field: 'bijlage' }), null)
	assert.equal(rowActionFormData({ a: 1 }, { field: 'bijlage', files: [] }), null)
	const appended = []
	globalThis.FormData = class {
		append(...args) { appended.push(args) }
	}
	try {
		const form = rowActionFormData(
			{ reason: 'te laat', count: 2, empty: null, gone: undefined, inputs: { amount: 5, note: 'x' }, flag: false },
			{ field: 'bijlage', files: [{ name: 'a.pdf' }, { name: 'b.png' }] },
		)
		assert.ok(form)
		assert.equal(rowActionFormData(null, { field: 'f', files: [{ name: 'c' }] }) !== null, true)
	} finally {
		delete globalThis.FormData
	}
	assert.deepEqual(appended, [
		['reason', 'te laat'],
		['count', '2'],
		['inputs[amount]', '5'],
		['inputs[note]', 'x'],
		['flag', 'false'],
		['bijlage[]', { name: 'a.pdf' }, 'a.pdf'],
		['bijlage[]', { name: 'b.png' }, 'b.png'],
		['f[]', { name: 'c' }, 'c'],
	])
})

test('my cases: the cases, the mandates and a refusal', async () => {
	const ok = browser({ ok: true, status: 200, body: { cases: [{ id: 1 }], mandates: [{ id: 'm' }], activeMandate: { id: 'm' } } })
	let answer
	try {
		answer = await api().fetchMyCases('m 1')
	} finally {
		restore(ok)
	}
	assert.equal(ok.calls[0].url, '/portal/api/my-cases?mandate=m%201')
	assert.equal(ok.calls[0].init.headers.Accept, 'application/json')
	assert.equal(ok.calls[0].init.headers.Authorization, 'Bearer tok')
	assert.deepEqual(answer, { ok: true, status: 200, cases: [{ id: 1 }], mandates: [{ id: 'm' }], activeMandate: { id: 'm' }, error: '' })

	const refused = browser({ ok: false, status: 409, body: { cases: [{ id: 1 }], mandates: 'x', error: 'group_too_large' } })
	try {
		answer = await api().fetchMyCases()
	} finally {
		restore(refused)
	}
	assert.equal(refused.calls[0].url, '/portal/api/my-cases')
	assert.deepEqual(answer, { ok: false, status: 409, cases: [], mandates: [], activeMandate: null, error: 'group_too_large' })

	const bad = browser({ ok: false, status: 500, badJson: true })
	try {
		answer = await api().fetchMyCases()
	} finally {
		restore(bad)
	}
	assert.deepEqual(answer, { ok: false, status: 500, cases: [], mandates: [], activeMandate: null, error: '' })

	const offline = browser({ throws: true })
	try {
		answer = await api().fetchMyCases()
	} finally {
		restore(offline)
	}
	assert.deepEqual(answer, { ok: false, status: 0, cases: [], mandates: [], activeMandate: null, error: '' })
})
