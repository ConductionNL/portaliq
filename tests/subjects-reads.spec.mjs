#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// subjects-reads.spec.mjs: how a subject row is read, and what the featured,
// subject and count reads answer for every status, a throwing fetch and an
// unreadable body, pinned before the reads shared their request steps.
//
// Usage:
//   node --test tests/subjects-reads.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { fetchCounts, fetchFeatured, fetchSubject, subjectFrom } from '../src/site/lib/subjects.js'

/**
 * A fetch that answers once and records the call.
 *
 * @param {number} status The status.
 * @param {unknown} body The JSON body, or an Error to throw from json().
 * @return {{fetch: Function, calls: Array}}
 */
function answering(status, body) {
	const calls = []
	return {
		calls,
		fetch: async (url, init) => {
			calls.push([url, init])
			return {
				status,
				ok: status >= 200 && status < 300,
				json: async () => {
					if (body instanceof Error) {
						throw body
					}
					return body
				},
			}
		},
	}
}
/**
 * A fetch that cannot reach the server.
 *
 * @return {Promise<never>}
 */
async function throwing() {
	throw new Error('offline')
}

test('a subject row as the page shows it', () => {
	assert.equal(subjectFrom(null), null)
	assert.equal(subjectFrom('x'), null)
	assert.equal(subjectFrom({ title: '  ' }), null)
	assert.deepEqual(subjectFrom({ name: ' Wonen ', uuid: 7, slug: 'wonen-1', summary: ' s ', description: 3, publicationCount: '12', featuredOrder: '2', featured: true, image: { url: '/i.png' } }), {
		id: '7', slug: 'wonen-1', title: 'Wonen', summary: 's', description: '', image: { url: '/i.png', alt: 'Wonen' }, publicationCount: 12, featuredOrder: 2, featured: true,
	})
	assert.deepEqual(subjectFrom({ id: 'a', title: 'T', slug: '-bad', publicationCount: '', featuredOrder: null, featured: 'yes' }), {
		id: 'a', slug: '', title: 'T', summary: '', description: '', image: null, publicationCount: null, featuredOrder: Infinity, featured: false,
	})
	const odd = subjectFrom({ title: 'T', publicationCount: 'many', featuredOrder: 'x' })
	assert.deepEqual([odd.id, odd.publicationCount, odd.featuredOrder], ['', null, Infinity])
	assert.equal(subjectFrom({ title: 'T', publicationCount: 0, featuredOrder: 0 }).publicationCount, 0)
	assert.equal(subjectFrom({ title: 'T', featuredOrder: undefined }).featuredOrder, Infinity)
})

test('the featured read: the request, every status and a failure', async () => {
	globalThis.window = { location: { origin: 'https://site.example' } }
	const ok = answering(200, { results: [{ title: 'B', slug: 'b', featured: true }, { title: 'A', slug: 'a', featured: true, featuredOrder: 1 }] })
	const result = await fetchFeatured({ count: 1, fetchImpl: ok.fetch })
	assert.deepEqual(result.state, 'ok')
	assert.deepEqual(result.subjects.map((s) => s.slug), ['a'])
	assert.deepEqual(ok.calls[0], ['https://site.example/index.php/apps/opencatalogi/api/themes?featured=true', { headers: { Accept: 'application/json' }, credentials: 'omit' }])
	assert.deepEqual(await fetchFeatured({ fetchImpl: answering(404, {}).fetch }), { state: 'absent', subjects: [] })
	assert.deepEqual(await fetchFeatured({ fetchImpl: answering(403, {}).fetch }), { state: 'failed', subjects: [] })
	assert.deepEqual(await fetchFeatured({ fetchImpl: throwing }), { state: 'failed', subjects: [] })
	assert.deepEqual(await fetchFeatured({ fetchImpl: answering(200, new Error('html')).fetch }), { state: 'failed', subjects: [] })
	const elsewhere = answering(200, {})
	delete globalThis.window
	await fetchFeatured({ fetchImpl: elsewhere.fetch, endpoint: '/themes' })
	assert.equal(elsewhere.calls[0][0], 'http://localhost/themes?featured=true')
})

test('the subject read: a bad slug, every status and a failure', async () => {
	const ok = answering(200, { title: 'Wonen', slug: 'wonen' })
	const result = await fetchSubject('wonen', { fetchImpl: ok.fetch, endpoint: '/themes' })
	assert.equal(result.state, 'ok')
	assert.equal(result.subject.title, 'Wonen')
	assert.deepEqual(ok.calls[0], ['/themes/wonen', { headers: { Accept: 'application/json' }, credentials: 'omit' }])
	for (const slug of ['', null, '-x', 'a b', '../x']) {
		assert.deepEqual(await fetchSubject(slug, { fetchImpl: throwing }), { state: 'notFound', subject: null }, String(slug))
	}
	assert.deepEqual(await fetchSubject('x', { fetchImpl: answering(404, {}).fetch }), { state: 'notFound', subject: null })
	assert.deepEqual(await fetchSubject('x', { fetchImpl: answering(403, {}).fetch }), { state: 'notFound', subject: null })
	assert.deepEqual(await fetchSubject('x', { fetchImpl: answering(500, {}).fetch }), { state: 'failed', subject: null })
	assert.deepEqual(await fetchSubject('x', { fetchImpl: answering(200, { title: '' }).fetch }), { state: 'notFound', subject: null })
	assert.deepEqual(await fetchSubject('x', { fetchImpl: answering(200, new Error('html')).fetch }), { state: 'failed', subject: null })
	assert.deepEqual(await fetchSubject('x', { fetchImpl: throwing }), { state: 'failed', subject: null })
})

test('the count read: the request, every status and a failure', async () => {
	globalThis.window = { location: { origin: 'https://site.example' } }
	const ok = answering(200, { facets: {} })
	assert.deepEqual(await fetchCounts('year', { fetchImpl: ok.fetch }), { state: 'ok', counts: [] })
	assert.equal(ok.calls[0][0], 'https://site.example/index.php/apps/opencatalogi/api/federation/publications?_limit=0&_facets%5BpublicationDate%5D%5Btype%5D=date_histogram&_facets%5BpublicationDate%5D%5Binterval%5D=year')
	assert.deepEqual(ok.calls[0][1], { headers: { Accept: 'application/json' }, credentials: 'omit' })
	assert.deepEqual(await fetchCounts('subject', { fetchImpl: answering(404, {}).fetch }), { state: 'absent', counts: [] })
	assert.deepEqual(await fetchCounts('subject', { fetchImpl: answering(502, {}).fetch }), { state: 'failed', counts: [] })
	assert.deepEqual(await fetchCounts('subject', { fetchImpl: throwing }), { state: 'failed', counts: [] })
	assert.deepEqual(await fetchCounts('subject', { fetchImpl: answering(200, new Error('html')).fetch }), { state: 'failed', counts: [] })
	delete globalThis.window
})
