#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// federated-search.spec.mjs — the pure logic behind the public federated
// publication search.
//
// Usage:
//   node tests/federated-search.spec.mjs
//
// Run as a plain node script to match tests/site-auth.spec.mjs and
// tests/registry.spec.js — this app has no JS test runner.
//
// WHAT IS WORTH ASSERTING HERE
// ----------------------------
// The search box, the results list and the pagination are visible: if any of
// them breaks, it breaks in front of somebody. The three things that fail
// SILENTLY are the ones covered below.
//
//   1. The facet envelope arrives in TWO different shapes on the SAME
//      endpoint. Reading one of them leaves an empty facet column, which on
//      screen is indistinguishable from "this field has no values" — the bug
//      presents itself as data.
//   2. A federated row can arrive from a peer running an older schema. One
//      such row throwing would blank the whole list, including every row that
//      was fine.
//   3. An empty search term must be OMITTED, not sent as `_search=`. Those
//      are different requests and only one of them means "everything".

import {
	buildRequestUrl,
	formatDutchDate,
	pageWindow,
	paginationItems,
	toBuckets,
	toResult,
} from '../src/site/lib/federatedSearch.js'

let failures = 0

/**
 * Assert deep equality, reporting the difference rather than a bare boolean.
 *
 * @param {string} what     What is being asserted.
 * @param {*}      actual   The value produced.
 * @param {*}      expected The value wanted.
 * @return {void}
 */
function assertEqual(what, actual, expected) {
	const a = JSON.stringify(actual)
	const e = JSON.stringify(expected)
	if (a === e) {
		console.log(`  ok   ${what}`)
		return
	}
	console.error(`  FAIL ${what}\n       expected ${e}\n       actual   ${a}`)
	failures += 1
}

/**
 * Assert a condition holds.
 *
 * @param {string}  what      What is being asserted.
 * @param {boolean} condition The condition.
 * @return {void}
 */
function assertTrue(what, condition) {
	if (condition === true) {
		console.log(`  ok   ${what}`)
		return
	}
	console.error(`  FAIL ${what}`)
	failures += 1
}

const BASE = {
	endpoint: '/index.php/apps/opencatalogi/api/federation/publications',
	origin: 'https://portal.example.org',
	pageSize: 10,
	page: 1,
	query: '',
	facetField: 'categories',
	selectedFacets: [],
}

console.log('buildRequestUrl')

{
	const url = new URL(buildRequestUrl(BASE))
	assertEqual(
		'resolves a relative endpoint against the origin',
		url.origin,
		'https://portal.example.org',
	)
	assertEqual(
		'keeps the OpenCatalogi path',
		url.pathname,
		'/index.php/apps/opencatalogi/api/federation/publications',
	)
	assertEqual('sends the page size', url.searchParams.get('_limit'), '10')
	assertEqual('sends the page', url.searchParams.get('_page'), '1')

	// THE NEGATIVE CASE. `_search=` and no `_search` are different requests.
	assertTrue(
		'omits an empty term entirely',
		url.searchParams.has('_search') === false,
	)

	assertEqual(
		'always asks for facet buckets in the same request',
		url.searchParams.get('_facets[categories][type]'),
		'terms',
	)
}

assertEqual(
	'sends a non-empty term',
	new URL(buildRequestUrl({ ...BASE, query: 'zorg' })).searchParams.get('_search'),
	'zorg',
)

assertEqual(
	'repeats the facet field once per selected value',
	new URL(
		buildRequestUrl({ ...BASE, selectedFacets: ['data-collection', 'office'] }),
	).searchParams.getAll('categories'),
	['data-collection', 'office'],
)

assertEqual(
	'accepts an absolute endpoint on another instance',
	new URL(
		buildRequestUrl({ ...BASE, endpoint: 'https://catalog.example.net/api/x' }),
	).origin,
	'https://catalog.example.net',
)

console.log('toBuckets — both dialects')

// OpenRegister's object-field dialect, as `_facets[categories][type]=terms`
// answers on /api/federation/publications.
assertEqual(
	'reads the object-field dialect (data.buckets, value/count)',
	toBuckets(
		{
			categories: {
				name: 'categories',
				data: {
					type: 'terms',
					buckets: [
						{
							value: 'data-collection',
							count: 99,
							label: 'data-collection',
						},
						{ value: 'office', count: 12, label: 'office' },
					],
				},
			},
		},
		'categories',
	),
	[
		{ value: 'data-collection', label: 'data-collection', count: 99 },
		{ value: 'office', label: 'office', count: 12 },
	],
)

// OpenCatalogi's virtual-facet dialect, as
// `_facets[@self][directory][type]=terms` answers on the SAME endpoint.
assertEqual(
	'reads the virtual dialect (buckets, key/results)',
	toBuckets(
		{
			directory: {
				type: 'terms',
				buckets: [
					{ key: 'opencatalogi.nl', label: 'opencatalogi.nl', results: 1 },
				],
			},
		},
		'directory',
	),
	[{ value: 'opencatalogi.nl', label: 'opencatalogi.nl', count: 1 }],
)

assertEqual(
	'survives a missing facet envelope',
	toBuckets(undefined, 'categories'),
	[],
)
assertEqual(
	'survives a facet field that is absent',
	toBuckets({ other: {} }, 'categories'),
	[],
)
assertEqual(
	'drops a bucket with no value rather than rendering a nameless filter',
	toBuckets({ categories: { data: { buckets: [{ count: 3 }] } } }, 'categories'),
	[],
)

console.log('toResult — a row from a federated peer')

assertEqual(
	'maps a complete row',
	toResult({
		name: 'GZAC',
		description: 'Een zaakgericht werken component',
		landingUrl: 'https://example.org/gzac',
		'@self': { id: 'abc', directory: 'opencatalogi.nl', summary: null },
	}),
	{
		key: 'abc',
		title: 'GZAC',
		summary: 'Een zaakgericht werken component',
		href: 'https://example.org/gzac',
		directory: 'opencatalogi.nl',
		id: 'abc',
		date: '',
		type: '',
	},
)

assertEqual(
	'names a row with no directory `local`, rather than leaving it blank',
	toResult({ name: 'x', '@self': { id: '1' } }).directory,
	'local',
)

assertEqual(
	'falls back through landingUrl → url → @self.uri for the link',
	toResult({ name: 'x', url: 'https://u', '@self': { uri: 'https://s' } }).href,
	'https://u',
)

assertEqual(
	'uses @self.uri when the row carries no link of its own',
	toResult({ name: 'x', '@self': { uri: 'https://s' } }).href,
	'https://s',
)

// THE ROW THAT WOULD BLANK THE LIST. A peer on an older schema sends almost
// nothing; this must still produce a renderable entry.
assertEqual(
	'degrades an almost-empty row to a titled entry instead of throwing',
	toResult({}),
	{
		key: '',
		title: 'Zonder titel',
		summary: '',
		href: '',
		directory: 'local',
		id: '',
		date: '',
		type: '',
	},
)

assertEqual(
	'ignores a non-string description rather than rendering [object Object]',
	toResult({ name: 'x', description: { nl: 'iets' } }).summary,
	'',
)

assertTrue(
	'truncates a long summary to 280 characters',
	toResult({ name: 'x', description: 'a'.repeat(400) }).summary.length === 280,
)

console.log('pageWindow')

assertEqual(
	'windows around the current page',
	pageWindow(10, 356),
	[8, 9, 10, 11, 12],
)
assertEqual('does not run below page 1', pageWindow(1, 356), [1, 2, 3])
assertEqual('does not run past the last page', pageWindow(356, 356), [354, 355, 356])
assertEqual('collapses to a single page', pageWindow(1, 1), [1])

console.log('sorting')

assertTrue(
	'omits _order entirely when no sort is chosen',
	new URL(buildRequestUrl(BASE)).search.includes('_order') === false,
)

assertEqual(
	'maps field:DIRECTION onto _order[field]',
	new URL(
		buildRequestUrl({ ...BASE, sort: 'publicationDate:DESC' }),
	).searchParams.get('_order[publicationDate]'),
	'DESC',
)

assertTrue(
	'ignores a malformed sort rather than sending half of it',
	new URL(buildRequestUrl({ ...BASE, sort: 'publicationDate' })).search.includes(
		'_order',
	) === false,
)

console.log('paginationItems')

// MEASURED on opencatalogi.nl at page 1 of 36: `1 2 3 4 5 … 36`.
assertEqual('matches the reference at page 1 of 36', paginationItems(1, 36), [
	1,
	2,
	3,
	4,
	5,
	'gap',
	36,
])
assertEqual(
	'windows around a middle page, with a gap either side',
	paginationItems(18, 36),
	[1, 'gap', 17, 18, 19, 'gap', 36],
)
assertEqual('reaches the last page', paginationItems(36, 36), [1, 'gap', 35, 36])
assertEqual('collapses to one page', paginationItems(1, 1), [1])

// A gap marker replacing ONE page would be longer than the page it hides.
assertEqual(
	'never emits a gap for a single skipped page',
	paginationItems(1, 6),
	[1, 2, 3, 4, 5, 6],
)

console.log('formatDutchDate')

assertEqual(
	'formats the way the reference does',
	formatDutchDate('2026-01-30T00:00:00+00:00'),
	'30 januari 2026',
)
assertEqual(
	'formats a February date',
	formatDutchDate('2026-02-18T12:00:00+00:00'),
	'18 februari 2026',
)
assertEqual('survives an empty value', formatDutchDate(''), '')
assertEqual('survives a nonsense value', formatDutchDate('not-a-date'), '')

console.log('toResult — detail id, date and type')

assertEqual(
	'carries the id the detail route addresses',
	toResult({ name: 'x', '@self': { id: 'abc-123' } }).id,
	'abc-123',
)
assertEqual(
	'formats publicationDate for the card',
	toResult({ name: 'x', publicationDate: '2026-01-30T00:00:00+00:00' }).date,
	'30 januari 2026',
)

// THE CASE THAT WOULD PUT "17" ON EVERY CARD. `@self.schema` is a numeric id
// and is NOT a type name; an empty type means the chip is omitted.
assertEqual(
	'does not mistake the numeric schema id for a type name',
	toResult({ name: 'x', '@self': { schema: 17 } }).type,
	'',
)
assertEqual(
	'uses a schema title when the instance supplies one',
	toResult({ name: 'x', '@self': { schemaTitle: 'Publiccode' } }).type,
	'Publiccode',
)

if (failures > 0) {
	console.error(`\n${failures} assertion(s) failed`)
	process.exit(1)
}

console.log('\nall federated-search assertions held')
