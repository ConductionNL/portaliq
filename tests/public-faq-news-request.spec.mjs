#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// public-faq-news-request.spec.mjs: the requests the FAQ, product finder and
// public news reads send (the address, the query left out when empty, the
// bearer only for news) and how a refusal or a missing item comes back,
// pinned before their shared request was moved into one helper.
//
// Usage:
//   node --test tests/public-faq-news-request.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { fetchFaq, fetchFinder } from '../src/site/lib/publicFaq.js'
import { fetchPublicNews, fetchPublicNewsItem } from '../src/site/lib/publicNews.js'

/**
 * A browser with a fake server and, optionally, a bearer in this tab.
 *
 * @param {object} answer The body, status and bearer.
 * @return {{calls: Array<{url: string, init: object}>}} The calls.
 */
function stubBrowser(answer = {}) {
	const { body = {}, status = 200, token = '' } = answer
	const calls = []
	globalThis.document = { getElementById: () => null, querySelector: () => null }
	globalThis.window = {
		location: { origin: 'https://school.example', search: '', hash: '', pathname: '/' },
		localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
		sessionStorage: { getItem: () => token || null, setItem() {} },
		history: { replaceState() {} },
	}
	globalThis.fetch = async (url, init) => {
		calls.push({ url: String(url), init })
		return { ok: status >= 200 && status < 300, status, json: async () => body }
	}
	return { calls }
}

test('the FAQ read sends the portal and the filled filters, never a bearer', async () => {
	const { calls } = stubBrowser({ token: 'abc', body: { entries: [{ question: 'Q' }] } })
	assert.deepEqual(await fetchFaq('school', { page: '/zorg', topic: '' }), [{ question: 'Q' }])
	assert.equal(calls[0].url, 'https://school.example/api/content/faq?portal=school&page=%2Fzorg')
	assert.deepEqual(calls[0].init, { headers: { Accept: 'application/json' } })
	stubBrowser({ body: { entries: 'x' } })
	assert.deepEqual(await fetchFaq('school'), [])
})

test('the finder read answers null on a 404 and throws on another refusal', async () => {
	const { calls } = stubBrowser({ body: { finder: { id: 'f' } } })
	assert.deepEqual(await fetchFinder('school', 'f'), { id: 'f' })
	assert.equal(calls[0].url, 'https://school.example/api/content/finder?portal=school&finder=f')
	stubBrowser({ body: {} })
	assert.equal(await fetchFinder('school', ''), null)
	stubBrowser({ status: 404 })
	assert.equal(await fetchFinder('school', 'x'), null)
	stubBrowser({ status: 500 })
	await assert.rejects(fetchFinder('school', 'x'), (error) => error.status === 500 && error.message === 'content api 500 for /finder')
})

test('the news reads send the bearer when this tab holds one', async () => {
	const { calls } = stubBrowser({ token: 'abc', body: { items: [{ id: 1 }] } })
	assert.deepEqual(await fetchPublicNews('school', 5), [{ id: 1 }])
	assert.equal(calls[0].url, 'https://school.example/api/content/news?portal=school&limit=5')
	assert.deepEqual(calls[0].init, { headers: { Accept: 'application/json', Authorization: 'Bearer abc' } })

	const anonymous = stubBrowser({ body: { items: null } })
	assert.deepEqual(await fetchPublicNews('school', 0), [])
	assert.equal(anonymous.calls[0].url, 'https://school.example/api/content/news?portal=school&limit=0')
	assert.deepEqual(anonymous.calls[0].init, { headers: { Accept: 'application/json' } })
})

test('one news item: its address, null when missing, a throw on another refusal', async () => {
	const { calls } = stubBrowser({ body: { item: { id: 'a b' } } })
	assert.deepEqual(await fetchPublicNewsItem('school', 'a b'), { id: 'a b' })
	assert.equal(calls[0].url, 'https://school.example/api/content/news/a%20b?portal=school')
	stubBrowser({ body: {} })
	assert.equal(await fetchPublicNewsItem('school', 'x'), null)
	stubBrowser({ status: 404 })
	assert.equal(await fetchPublicNewsItem('school', 'x'), null)
	stubBrowser({ status: 403 })
	await assert.rejects(fetchPublicNewsItem('school', 'x'), (error) => error.status === 403 && error.message === 'content api 403 for /news/x')
})
