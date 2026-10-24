#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// catalogue-boards.spec.mjs: the catalogue block reads like the boards
// Zoeken, Opleidingen and Cursusaanbod: facets by kind and by audience asked
// of the server, a facet as radios or a menu, a hidden field label, the
// search in the rail, the meta style of a card, a chevron on a linked card,
// and pages and menus that look like the boards
// (site-catalogue-follows-the-school-boards).
//
// Usage:
//   node --test tests/site-look/catalogue-boards.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { fetchCatalogue } from '../../src/site/lib/publicCatalogue.js'
import {
	chooseOne,
	facetControl,
	facetsByOf,
	metaLine,
} from '../../src/site/widgets/nlCatalogue/catalogue.js'
import { instance } from '../support/page-instance.mjs'
import { loadSfc, renderComponent } from '../support/render-sfc.mjs'

const NlCatalogue = await loadSfc('src/site/widgets/nlCatalogue/NlCatalogue.vue')

const PROGRAMME = {
	id: 'learniq:programme:1',
	type: 'programme',
	kind: 'Opleiding',
	title: 'Mechatronica',
	summary: 'Je bouwt, test en onderhoudt machines.',
	meta: ['Niveau 4', 'BOL of BBL', '4 jaar'],
	href: '/opleidingen/mechatronica',
}
const NEWS = {
	id: 'news:n1',
	newsId: 'n1',
	type: 'news',
	kind: '',
	title: 'Ouderavond op donderdag 29 oktober',
	date: '2026-10-01T10:00:00+00:00',
	meta: ['hele school'],
}
const FACETS = [
	{
		label: 'Soort',
		values: [{ value: 'Nieuws', count: 5, selected: true }],
	},
	{
		label: 'Voor wie',
		values: [
			{ value: 'hele school', count: 3, selected: false },
			{ value: 'groep 7', count: 1, selected: false },
		],
	},
	{
		label: 'Schooljaar',
		values: [{ value: '2026-2027', count: 5, selected: false }],
	},
]

/**
 * A browser whose server answers with `body`.
 *
 * @param {object} body The answer.
 * @return {{calls: Array<string>}}
 */
function stubBrowser(body) {
	const calls = []
	globalThis.document = { getElementById: () => null, querySelector: () => null }
	globalThis.window = {
		location: { origin: 'https://school.example', search: '', hash: '' },
		localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
	}
	globalThis.fetch = async (url) => {
		calls.push(String(url))
		return { ok: true, status: 200, json: async () => body }
	}
	return { calls }
}

/**
 * The block rendered with a result already in hand.
 *
 * @param {object} props The block's props.
 * @param {object} result The server's answer.
 * @return {Promise<string>}
 */
async function render(props, result) {
	stubBrowser({})
	const html = await renderComponent(
		{
			...NlCatalogue,
			data() {
				return {
					...NlCatalogue.data.call(this),
					q: 'ouderavond',
					filters: { Soort: ['Nieuws'] },
					result,
				}
			},
		},
		props,
	)
	// Without Vue's fragment markers, so a test reads the markup.
	return html.replace(/<!--[\s\S]*?-->/g, '')
}

test('the block asks for a facet by kind (with the page word for news) and by audience', async () => {
	assert.deepEqual(
		facetsByOf({ kindFacet: ' Soort ', audienceFacet: 'Voor wie', lang: 'nl' }),
		{ kind: 'Soort', news: 'Nieuws', audience: 'Voor wie' },
	)
	assert.deepEqual(facetsByOf({ lang: 'nl' }), {})

	const { calls } = stubBrowser({ items: [], total: 0, facets: [] })
	await fetchCatalogue('wilgenboom', {
		facetsBy: { kind: 'Soort', news: 'Nieuws' },
	})
	assert.deepEqual(JSON.parse(new URL(calls[0]).searchParams.get('facetsBy')), {
		kind: 'Soort',
		news: 'Nieuws',
	})
	await fetchCatalogue('wilgenboom', { facetsBy: {} })
	assert.equal(new URL(calls[1]).searchParams.has('facetsBy'), false)

	const block = instance(NlCatalogue, {
		portal: 'wilgenboom',
		kindFacet: 'Soort',
		audienceFacet: 'Voor wie',
	})
	await block.reload(1)
	assert.deepEqual(JSON.parse(new URL(calls[2]).searchParams.get('facetsBy')), {
		kind: 'Soort',
		news: 'Nieuws',
		audience: 'Voor wie',
	})
})

test('a facet may be chosen from radios or a menu, one value at a time', async () => {
	assert.equal(facetControl({ 'Voor wie': 'radio' }, 'Voor wie'), 'radio')
	assert.equal(facetControl({ Schooljaar: 'select' }, 'Schooljaar'), 'select')
	assert.equal(facetControl({ Soort: 'tiles' }, 'Soort'), 'checkbox')
	assert.equal(facetControl(null, 'Soort'), 'checkbox')
	assert.deepEqual(chooseOne({ Soort: ['Nieuws'] }, 'Voor wie', 'groep 7'), {
		Soort: ['Nieuws'],
		'Voor wie': ['groep 7'],
	})
	assert.deepEqual(chooseOne({ 'Voor wie': ['groep 7'] }, 'Voor wie', ''), {})

	const html = await render(
		{ facetDisplay: { 'Voor wie': 'radio', Schooljaar: 'select' } },
		{ items: [NEWS], total: 1, page: 1, pages: 1, facets: FACETS },
	)
	assert.match(html, /type="checkbox" checked/)
	assert.match(html, /type="radio" name="nl-catalogue-\d+-Voor wie"/)
	assert.match(
		html,
		/<select class="utrecht-select nl-catalogue__control" aria-label="Schooljaar">/,
	)
	assert.match(html, /<option value="">Alles<\/option>/)
})

test('the field label may be for screen readers only, and the search may sit in the rail', async () => {
	const hidden = await render(
		{ labelHidden: true },
		{ items: [], total: 0, page: 1, pages: 1, facets: [] },
	)
	assert.match(
		hidden,
		/<label class="[^"]*\bnl-catalogue__hidden\b[^"]*"[^>]*>Zoek in het aanbod/,
	)

	const rail = await render(
		{ searchPlacement: 'rail' },
		{ items: [], total: 0, page: 1, pages: 1, facets: FACETS },
	)
	assert.match(
		rail,
		/<aside[^>]*>\s*<form class="nl-catalogue__search nl-catalogue__search--rail"/,
	)
	assert.match(rail, /nl-catalogue__submit--icon/)
	assert.equal(
		(rail.match(/role="search"/g) || []).length,
		1,
		'one field, in the rail',
	)
})

test('a linked card ends in a chevron; the meta style puts the first part as a label under the summary', async () => {
	assert.deepEqual(metaLine(PROGRAMME), {
		pill: 'Niveau 4',
		rest: ['BOL of BBL', '4 jaar'],
	})
	const html = await render(
		{ cardStyle: 'meta' },
		{ items: [PROGRAMME], total: 1, page: 1, pages: 1, facets: [] },
	)
	assert.match(html, /<svg class="nl-catalogue__chevron"/)
	assert.doesNotMatch(html, /nl-catalogue__kind/, 'no kind label above the title')
	assert.match(
		html,
		/nl-catalogue__line--meta"><span class="nl-catalogue__pill">Niveau 4<\/span><span>BOL of BBL<\/span><span>4 jaar<\/span>/,
	)

	const plain = await render(
		{},
		{
			items: [{ ...PROGRAMME, href: '' }],
			total: 1,
			page: 1,
			pages: 1,
			facets: [],
		},
	)
	assert.doesNotMatch(plain, /nl-catalogue__chevron/, 'no link, no chevron')
	assert.match(plain, /nl-catalogue__kind">Opleiding</)
})

test('a dated card repeats no meta part above its title', async () => {
	const html = await render(
		{ display: 'dated' },
		{
			items: [
				{ ...PROGRAMME, date: '2026-10-15', meta: ['Donderdag', '1 dag'] },
			],
			total: 1,
			page: 1,
			pages: 1,
			facets: [],
		},
	)
	assert.equal((html.match(/>Donderdag</g) || []).length, 1)
})

test('the pages are boxes, the current one filled; the menus and checkboxes are styled', async () => {
	const html = await render(
		{},
		{ items: [NEWS], total: 12, page: 1, pages: 2, facets: [] },
	)
	assert.match(html, /nl-catalogue__page" aria-current="page"/)
	assert.match(html, /nl-catalogue__page nl-catalogue__page--next/)
	const { readFileSync } = await import('node:fs')
	const css = readFileSync(
		new URL(
			'../../src/site/widgets/nlCatalogue/NlCatalogue.vue',
			import.meta.url,
		),
		'utf8',
	).replace(/\s+/g, ' ')
	assert.match(
		css,
		/\.nl-catalogue__page\.utrecht-button\[aria-current='page'\] \{[^}]*background-color: var\(--nldesign-color-primary/,
	)
	assert.match(
		css,
		/\.nl-catalogue__option input \{[^}]*accent-color: var\(--nldesign-color-primary/,
	)
	assert.match(
		css,
		/\.nl-catalogue__control\.utrecht-select \{[^}]*border: 1px solid/,
	)
})
