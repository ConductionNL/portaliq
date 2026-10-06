#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-public-catalogue.spec.mjs: the catalogue block and a dated list that
// fills itself from the catalogue (portal-public-catalogue), as the plain
// functions they draw from, the request they send and the block over a fake
// server.
//
// Usage:
//   node --test tests/portal-public-catalogue.spec.mjs
//
// @spec openspec/changes/portal-public-catalogue/specs/portaliq-cms/spec.md

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { fetchCatalogue } from '../src/site/lib/publicCatalogue.js'
import {
	countText,
	eventItemsOf,
	initialQuery,
	resultCard,
	strings,
	toggleFilter,
} from '../src/site/widgets/nlCatalogue/catalogue.js'
import { instance } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

// Loaded before any test stubs a document: the compiled styles look for one.
const NlCatalogue = await loadSfc('src/site/widgets/nlCatalogue/NlCatalogue.vue')
const NlEventList = await loadSfc('src/site/widgets/nlEventList/NlEventList.vue')

const COURSE = {
	id: 'learniq:course:1',
	type: 'course',
	kind: 'Cursus',
	title: 'F-gassen: herhaling en examen',
	summary: 'In één dag de theorie opfrissen en examen doen.',
	date: '2026-10-08',
	meta: ['1 dag', 'ook op 22 okt, 5 nov'],
	facets: { Plaats: ['Praktijkhal Zuiddrecht'] },
	note: 'Nog 1 plek',
	noteTone: 'warning',
}
const NEWS = {
	id: 'news:n1',
	newsId: 'n1',
	type: 'news',
	kind: '',
	title: 'Toetsweek 1: het rooster staat online',
	date: '2026-09-30T08:00:00+00:00',
}

/**
 * A browser with a fake server answering the catalogue.
 *
 * @param {object} body The answer.
 * @param {number} [status] Its status.
 * @return {{calls: Array<string>}}
 */
function stubBrowser(body, status = 200) {
	const calls = []
	globalThis.document = { getElementById: () => null, querySelector: () => null }
	globalThis.window = {
		location: {
			origin: 'https://school.example',
			search: '?_search=toetsweek',
			hash: '',
		},
		localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
	}
	globalThis.fetch = async (url) => {
		calls.push(String(url))
		return { ok: status >= 200 && status < 300, status, json: async () => body }
	}
	return { calls }
}

test('a facet value turns on and off, and an empty facet goes', () => {
	const on = toggleFilter({}, 'Plaats', 'Praktijkhal Zuiddrecht')
	assert.deepEqual(on, { Plaats: ['Praktijkhal Zuiddrecht'] })
	assert.deepEqual(toggleFilter(on, 'Plaats', 'Praktijkhal Zuiddrecht'), {})
	assert.deepEqual(toggleFilter(on, 'Plaats', 'Bij u op de zaak'), {
		Plaats: ['Praktijkhal Zuiddrecht', 'Bij u op de zaak'],
	})
})

test('the count reads as the board: with the words searched for, or the authored count', () => {
	assert.equal(countText('nl', 18, 'toetsweek'), '18 resultaten voor "toetsweek"')
	assert.equal(countText('nl', 1, ''), '1 resultaat')
	assert.equal(countText('nl', 9, '', '{count} cursussen'), '9 cursussen')
	assert.equal(countText('en', 2, 'x'), '2 results for "x"')
	assert.deepEqual(Object.keys(strings.nl).sort(), Object.keys(strings.en).sort())
})

test('the header search hands its words over on the address', () => {
	assert.equal(initialQuery('?route=/zoeken&_search=toetsweek'), 'toetsweek')
	assert.equal(initialQuery(''), '')
})

test('a card links an app item to its own address and a news item to its article', () => {
	const course = resultCard(
		{ ...COURSE, href: '/cursusaanbod/f-gassen' },
		{ lang: 'nl', newsRoute: '/nieuws' },
	)
	assert.equal(course.kind, 'Cursus')
	assert.equal(course.tone, 'warning')
	assert.equal(course.link.route, '/cursusaanbod/f-gassen')
	const news = resultCard(NEWS, { lang: 'nl', newsRoute: '/nieuws/' })
	assert.equal(news.kind, 'Nieuws')
	assert.equal(news.link.route, '/nieuws/n1')
	assert.equal(
		resultCard({ ...COURSE, href: 'javascript:alert(1)' }, { lang: 'nl' }).link,
		null,
	)
})

test('the catalogue items with a date become rows of a dated list', () => {
	assert.deepEqual(eventItemsOf([COURSE, { title: 'Zonder datum' }]), [
		{
			date: '2026-10-08',
			endDate: '',
			dateLabel: '',
			title: COURSE.title,
			href: '',
			meta: '1 dag · ook op 22 okt, 5 nov',
			note: 'Nog 1 plek',
			noteTone: 'warning',
		},
	])
})

test('the request names the portal, the words, the types, the choices and the page', async () => {
	const { calls } = stubBrowser({
		items: [COURSE],
		total: 1,
		page: 1,
		pages: 1,
		facets: [],
	})
	const page = await fetchCatalogue('warmtepompacademie', {
		q: 'gassen',
		types: ['course'],
		filters: { Plaats: ['Praktijkhal Zuiddrecht'] },
		sort: 'date',
		page: 2,
		limit: 5,
		upcoming: true,
	})
	const url = new URL(calls[0])
	assert.equal(url.pathname, '/api/content/catalogue')
	assert.equal(url.searchParams.get('portal'), 'warmtepompacademie')
	assert.equal(url.searchParams.get('search'), 'gassen')
	assert.equal(url.searchParams.get('types'), 'course')
	assert.deepEqual(JSON.parse(url.searchParams.get('filters')), {
		Plaats: ['Praktijkhal Zuiddrecht'],
	})
	assert.equal(url.searchParams.get('upcoming'), '1')
	assert.equal(page.total, 1)
})

test('the block starts with the header search, asks the server and says when search is unavailable', async () => {
	const { calls } = stubBrowser({
		items: [COURSE],
		total: 1,
		page: 1,
		pages: 1,
		facets: [
			{
				label: 'Plaats',
				values: [
					{ value: 'Praktijkhal Zuiddrecht', count: 1, selected: false },
				],
			},
		],
	})
	const block = instance(NlCatalogue, {
		portal: 'warmtepompacademie',
		types: ['course'],
	})
	assert.equal(block.q, 'toetsweek')
	await block.reload(1)
	assert.equal(new URL(calls[0]).searchParams.get('search'), 'toetsweek')
	assert.equal(block.result.total, 1)
	await block.toggle('Plaats', 'Praktijkhal Zuiddrecht')
	assert.deepEqual(JSON.parse(new URL(calls[1]).searchParams.get('filters')), {
		Plaats: ['Praktijkhal Zuiddrecht'],
	})

	stubBrowser({}, 500)
	await block.reload(1)
	assert.equal(
		block.failed,
		true,
		'a broken search never reads as "nothing found"',
	)

	stubBrowser({})
	const html = await renderComponent(NlCatalogue, { portal: 'warmtepompacademie' })
	assert.match(html, /role="search"/)
	assert.match(html, /<label class="utrecht-form-label" for="nl-catalogue-\d+-q">/)
})

test('a dated list with a source asks the catalogue for what is coming', async () => {
	const { calls } = stubBrowser({
		items: [COURSE],
		total: 1,
		page: 1,
		pages: 1,
		facets: [],
	})
	const list = instance(NlEventList, {
		portal: 'warmtepompacademie',
		source: { types: ['course'] },
		items: [],
	})
	await list.loadSource()
	const url = new URL(calls[0])
	assert.equal(url.searchParams.get('types'), 'course')
	assert.equal(url.searchParams.get('upcoming'), '1')
	assert.equal(list.fetched[0].title, COURSE.title)

	const plain = instance(NlEventList, { items: [] })
	await plain.loadSource()
	assert.equal(calls.length, 1, 'without a source nothing is asked')
})
