#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// public-catalogue-request.spec.mjs: the requests the public catalogue reads
// send (every query field, the bearer, the error) and how each answer is
// normalised, pinned before the request building was split into steps.
//
// Usage:
//   node --test tests/public-catalogue-request.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	fetchCatalogue,
	fetchCatalogueDetail,
	fetchCatalogueKinds,
} from '../src/site/lib/publicCatalogue.js'

/**
 * A browser with a fake server and, optionally, a bearer in this tab.
 *
 * @param {object} answer The body, status and bearer.
 * @return {{calls: Array<{url: string, headers: object}>}}
 */
function stubBrowser(answer = {}) {
	const { body = {}, status = 200, token = '' } = answer
	const calls = []
	globalThis.document = { getElementById: () => null, querySelector: () => null }
	globalThis.window = {
		location: { origin: 'https://school.example', search: '', hash: '', pathname: '/' },
		localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
		sessionStorage: { getItem: () => token || null, setItem() {} },
	}
	globalThis.fetch = async (url, init) => {
		calls.push({ url: String(url), headers: init.headers })
		return { ok: status >= 200 && status < 300, status, json: async () => body }
	}
	return { calls }
}

test('an empty query sends only the portal, without a bearer', async () => {
	const { calls } = stubBrowser()
	const page = await fetchCatalogue('school')
	assert.equal(calls[0].url, 'https://school.example/api/content/catalogue?portal=school')
	assert.deepEqual(calls[0].headers, { Accept: 'application/json' })
	assert.deepEqual(page, { items: [], total: 0, page: 1, pages: 1, facets: [] })
})

test('every query field goes along in its own order and form', async () => {
	const { calls } = stubBrowser({ token: 'abc' })
	await fetchCatalogue('school', {
		q: 'gassen',
		types: ['course', 'news'],
		filters: { Plaats: ['Hal'] },
		sort: 'title',
		page: 3,
		limit: 10,
		upcoming: true,
		app: 'learniq',
		categories: ['a', 'b'],
		range: 'month',
		facetsBy: { kind: 'Cursus' },
	})
	const url = new URL(calls[0].url)
	assert.deepEqual([...url.searchParams.entries()], [
		['portal', 'school'],
		['search', 'gassen'],
		['types', 'course,news'],
		['filters', '{"Plaats":["Hal"]}'],
		['sort', 'title'],
		['page', '3'],
		['limit', '10'],
		['upcoming', '1'],
		['app', 'learniq'],
		['categories', 'a,b'],
		['range', 'month'],
		['facetsBy', '{"kind":"Cursus"}'],
	])
	assert.deepEqual(calls[0].headers, { Accept: 'application/json', Authorization: 'Bearer abc' })
})

test('empty lists, empty maps and false flags stay off the address', async () => {
	const { calls } = stubBrowser()
	await fetchCatalogue('', {
		q: '',
		types: [],
		filters: {},
		upcoming: false,
		categories: [],
		facetsBy: {},
		page: 0,
	})
	assert.equal(calls[0].url, 'https://school.example/api/content/catalogue')
})

test('a full answer is passed on and a malformed one is made safe', async () => {
	stubBrowser({ body: { items: [{ id: 1 }], total: '7', page: '2', pages: 4, facets: [{ label: 'x' }] } })
	assert.deepEqual(await fetchCatalogue('school'), {
		items: [{ id: 1 }],
		total: 7,
		page: 2,
		pages: 4,
		facets: [{ label: 'x' }],
	})
	stubBrowser({ body: { items: 'no', total: 'many', page: 0, pages: null, facets: {} } })
	assert.deepEqual(await fetchCatalogue('school'), { items: [], total: 0, page: 1, pages: 1, facets: [] })
	stubBrowser({ body: null })
	assert.deepEqual(await fetchCatalogue('school'), { items: [], total: 0, page: 1, pages: 1, facets: [] })
})

test('a failing catalogue read throws with its status', async () => {
	stubBrowser({ status: 503 })
	await assert.rejects(fetchCatalogue('school'), (error) => {
		assert.equal(error.message, 'content api 503 for /catalogue')
		assert.equal(error.status, 503)
		return true
	})
})

test('the kinds read names the portal, carries the bearer and fails soft', async () => {
	const { calls } = stubBrowser({ body: { kinds: [{ kind: 'Cursus' }] }, token: 't' })
	assert.deepEqual(await fetchCatalogueKinds('school'), [{ kind: 'Cursus' }])
	assert.equal(calls[0].url, 'https://school.example/api/content/catalogue/kinds?portal=school')
	assert.deepEqual(calls[0].headers, { Accept: 'application/json', Authorization: 'Bearer t' })
	const bare = stubBrowser({ body: { kinds: 'none' } })
	assert.deepEqual(await fetchCatalogueKinds(''), [])
	assert.equal(bare.calls[0].url, 'https://school.example/api/content/catalogue/kinds')
	stubBrowser({ status: 500 })
	assert.deepEqual(await fetchCatalogueKinds('school'), [])
	globalThis.fetch = async () => {
		throw new Error('offline')
	}
	assert.deepEqual(await fetchCatalogueKinds('school'), [])
})

test('the detail read sends the address, answers null for a hidden item and throws otherwise', async () => {
	const { calls } = stubBrowser({ body: { item: { id: 1 }, detail: { title: 'T' } }, token: 'z' })
	assert.deepEqual(
		await fetchCatalogueDetail('school', { app: 'learniq', kind: 'course', slug: 's', extra: '' }),
		{ item: { id: 1 }, detail: { title: 'T' } },
	)
	assert.equal(
		calls[0].url,
		'https://school.example/api/content/catalogue/detail?portal=school&app=learniq&kind=course&slug=s',
	)
	assert.deepEqual(calls[0].headers, { Accept: 'application/json', Authorization: 'Bearer z' })
	stubBrowser({ body: { detail: { title: 'T' } } })
	assert.deepEqual(await fetchCatalogueDetail('school', {}), { item: {}, detail: { title: 'T' } })
	stubBrowser({ body: { item: { id: 1 } } })
	assert.equal(await fetchCatalogueDetail('school', {}), null)
	stubBrowser({ status: 404 })
	assert.equal(await fetchCatalogueDetail('school', {}), null)
	stubBrowser({ status: 500 })
	await assert.rejects(fetchCatalogueDetail('school', {}), (error) => {
		assert.equal(error.message, 'content api 500 for /catalogue/detail')
		assert.equal(error.status, 500)
		return true
	})
})
