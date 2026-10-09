#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-counts.spec.mjs: the live counts on the home page
// (home-and-theme-landing-pages REQ-HTL-004). One request for the facet
// counts, without the viewer's session; each count links into the search; a
// count the endpoint did not answer is not shown as zero.
//
// Usage:
//   node --test tests/portal-counts.spec.mjs
//
// @spec openspec/changes/home-and-theme-landing-pages/specs/portal-federated-search/spec.md

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { countsFrom, countsUrl, fetchCounts } from '../src/site/lib/subjects.js'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const CONTRACT = JSON.parse(readFileSync(new URL('./fixtures/subjects-contract.json', import.meta.url), 'utf8'))
const WIDGET = 'src/site/widgets/nlPortalCounts/NlPortalCounts.vue'

/**
 * A fetch that answers one body and remembers what it was asked.
 *
 * @param {number} status The status.
 * @param {object} body The body.
 * @param {Array<object>} asked Where the calls are recorded.
 * @return {Function} The fetch.
 */
function answer(status, body, asked) {
	return async (url, init) => {
		asked.push({ url, init })
		return { ok: status < 400, status, json: async () => body }
	}
}

test('the request omits credentials and asks only for the facet', async () => {
	const asked = []
	const done = await fetchCounts('category', { fetchImpl: answer(200, CONTRACT.counts, asked) })
	assert.equal(done.state, 'ok')
	assert.equal(asked.length, 1, 'one request')
	assert.equal(asked[0].init.credentials, 'omit', 'as an anonymous visitor, never as the signed-in officer')
	assert.equal(asked[0].init.headers.Authorization, undefined)
	const url = new URL(asked[0].url)
	assert.equal(url.searchParams.get('_limit'), '0')
	assert.equal(url.searchParams.get('_facets[wooCategory][type]'), 'terms')
	assert.equal(new URL(countsUrl('subject')).searchParams.get('_facets[themes][type]'), 'terms')
	assert.equal(new URL(countsUrl('year')).searchParams.get('_facets[publicationDate][interval]'), 'year')
	assert.equal(new URL(countsUrl('nonsense')).searchParams.get('_facets[wooCategory][type]'), 'terms', 'an unknown choice counts per category')
})

test('each count links to a filtered search', () => {
	const counts = countsFrom(CONTRACT.counts, 'category')
	assert.deepEqual(counts.map((c) => [c.value, c.count, c.href]), [
		['infocat006', 12, '/zoeken?f.wooCategory=infocat006'],
		['infocat004', 7, '/zoeken?f.wooCategory=infocat004'],
	])
	assert.equal(counts[0].label, 'Bij vertegenwoordigende organen ingekomen stukken', 'a category by its name')
	assert.equal(countsFrom(CONTRACT.counts, 'category', '/zoeken', 'en')[0].label, 'Documents received by representative bodies')

	const subjects = countsFrom(CONTRACT.counts, 'subject', '/publicaties')
	assert.deepEqual(subjects.map((c) => [c.label, c.count, c.href]), [
		['Parkeren', 14, '/publicaties?f.themes=th-parkeren'],
		['Fietsen', 1, '/publicaties?f.themes=th-fietsen'],
	], 'the other dialect of the facet envelope reads the same')

	const years = countsFrom(CONTRACT.counts, 'year')
	assert.deepEqual(years.map((c) => [c.label, c.count, c.href]), [
		['2026', 25, '/zoeken?periodFrom=2026-01-01&periodTo=2026-12-31'],
		['2025', 15, '/zoeken?periodFrom=2025-01-01&periodTo=2025-12-31'],
	], 'a year links to its period')
})

test('a missing count is not shown as zero', async () => {
	const names = countsFrom(CONTRACT.counts, 'category').map((c) => c.value)
	assert.equal(names.includes('infocat001'), false, 'a bucket that answered 0 is left out')
	assert.deepEqual(countsFrom({}, 'category'), [])
	assert.deepEqual(countsFrom({ facets: { wooCategory: { data: { buckets: [{ value: 'infocat004' }] } } } }, 'category'), [], 'a bucket with no count is left out')
	assert.equal((await fetchCounts('category', { fetchImpl: answer(404, {}, []) })).state, 'absent')
	assert.equal((await fetchCounts('category', { fetchImpl: answer(500, {}, []) })).state, 'failed')
	assert.equal((await fetchCounts('category', { fetchImpl: async () => { throw new Error('offline') } })).state, 'failed')
	const html = await renderSfc(WIDGET, { heading: 'Wat we publiceren, in aantallen' })
	assert.match(html, /Wat we publiceren, in aantallen/)
	assert.doesNotMatch(html, /nl-portal-count"/, 'no number before the answer')
})

test('the widget reads the counts of the author\'s choice when it is shown', async () => {
	const widget = await loadSfc(WIDGET)
	const asked = []
	globalThis.window = { fetch: answer(200, CONTRACT.counts, asked), location: { origin: 'https://portaal.example' } }
	try {
		const vm = { by: 'subject', searchRoute: '/zoeken', counts: [], state: 'loading' }
		await widget.mounted.call(vm)
		assert.equal(vm.state, 'ok')
		assert.equal(vm.counts.length, 2)
		assert.match(asked[0].url, /^https:\/\/portaal\.example\//)
		assert.equal(new URL(asked[0].url).searchParams.has('_facets[themes][type]'), true)
	} finally {
		delete globalThis.window
	}
	assert.equal(widget.methods.say.call({}, 'absent'), 'De publicatiecatalogus is niet geïnstalleerd, dus er zijn geen aantallen om te tonen.')
})
