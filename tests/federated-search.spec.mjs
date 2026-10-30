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

import { readFileSync } from 'node:fs'
import { kindBuckets, kindLabel, labelBuckets } from '../src/site/lib/facetLabels.js'
import {
	buildRequestUrl,
	facetFieldsOf,
	foreignDirectory,
	formatDutchDate,
	KIND_FIELD,
	lockedFiltersOf,
	matchDescription,
	pageWindow,
	paginationItems,
	readSearchState,
	RELEVANCE_SORT,
	relevanceApplied,
	relevanceOf,
	resultKind,
	searchQuery,
	sortForNewSearch,
	sortOptionsFor,
	subjectSlug,
	toBuckets,
	toResult,
	validDate,
	withKindField,
	withoutLocked,
	writeSearchState,
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
		kind: 'publication',
		publication: '',
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

// A ROW OF THIS PORTAL NAMES NO SOURCE (search-names-only-a-foreign-source
// REQ-SNF-001). No directory, or the API's own word `local`, is this
// instance; the card drew "Bron: local" for it.
assertEqual(
	'gives a row with no directory no source, rather than the word local',
	toResult({ name: 'x', '@self': { id: '1' } }).directory,
	'',
)

assertEqual(
	'gives a row the API calls local no source either',
	toResult({ name: 'x', '@self': { id: '1', directory: 'local' } }).directory,
	'',
)

assertEqual(
	'keeps the directory of a row from another catalogue',
	[foreignDirectory('opencatalogi.nl'), foreignDirectory(' LOCAL '), foreignDirectory(17)],
	['opencatalogi.nl', '', ''],
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
		kind: 'publication',
		publication: '',
		key: '',
		title: 'Zonder titel',
		summary: '',
		href: '',
		directory: '',
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

// woo-search-and-detail: facets per field, the period, the address and the
// saved-search query (REQ-WSD-001 to REQ-WSD-004).

console.log('facets per field')

{
	const url = new URL(
		buildRequestUrl({
			...BASE,
			facetField: undefined,
			facetFields: ['wooCategory', 'organization'],
			facets: { wooCategory: ['infocat014'], organization: [] },
		}),
	)
	assertEqual(
		'asks a facet for the first field',
		url.searchParams.get('_facets[wooCategory][type]'),
		'terms',
	)
	assertEqual(
		'asks a facet for the second field in the same request',
		url.searchParams.get('_facets[organization][type]'),
		'terms',
	)
	assertEqual(
		'sends the ticked category as a filter',
		url.searchParams.getAll('wooCategory'),
		['infocat014'],
	)
	assertTrue(
		'sends no filter for a field without a selection',
		url.searchParams.has('organization') === false,
	)
	assertEqual(
		'a state without facetFields keeps its single field',
		facetFieldsOf({ facetField: 'themes' }),
		['themes'],
	)
}

console.log('period range')

{
	const url = new URL(
		buildRequestUrl({
			...BASE,
			periodFrom: '2026-01-01',
			periodTo: '2026-12-31',
		}),
	)
	assertEqual(
		'sends the from date as a gte range',
		url.searchParams.get('publicationDate[gte]'),
		'2026-01-01',
	)
	assertEqual(
		'sends the to date through the end of that day',
		url.searchParams.get('publicationDate[lte]'),
		'2026-12-31T23:59:59Z',
	)
	const bad = new URL(buildRequestUrl({ ...BASE, periodFrom: 'gisteren' }))
	assertTrue(
		'does not send a from date that is not a date',
		bad.searchParams.has('publicationDate[gte]') === false,
	)
	assertEqual('refuses a date that rolls over', validDate('2026-02-31'), '')
	const own = new URL(
		buildRequestUrl({
			...BASE,
			periodField: 'period.from',
			periodFrom: '2026-01-01',
		}),
	)
	assertEqual(
		'uses another period field when the block names one',
		own.searchParams.get('period.from[gte]'),
		'2026-01-01',
	)
}

console.log('address round trip')

{
	const fields = ['wooCategory', 'organization']
	const state = {
		query: 'fietspad',
		page: 1,
		sort: '',
		facets: { wooCategory: ['infocat014'], organization: [] },
		periodFrom: '2026-01-01',
		periodTo: '',
	}
	const url = writeSearchState(
		new URL('https://portal.example.org/site?route=/zoeken'),
		state,
		fields,
	)
	assertEqual('keeps the route', url.searchParams.get('route'), '/zoeken')
	assertEqual(
		'writes the category per field',
		url.searchParams.get('f.wooCategory'),
		'infocat014',
	)
	assertEqual(
		'writes the from date',
		url.searchParams.get('periodFrom'),
		'2026-01-01',
	)
	const back = readSearchState(url.search, fields)
	assertEqual('reads the text back', back.query, 'fietspad')
	assertEqual('reads the facets back', back.facets, state.facets)
	assertEqual('reads the from date back', back.periodFrom, '2026-01-01')
}

console.log('old _facets parameter')

{
	const back = readSearchState('?_facets=parkeren', ['themes', 'organization'])
	assertEqual(
		'an old link selects its value in the first field',
		back.facets.themes,
		['parkeren'],
	)
	assertEqual('and nothing in the second', back.facets.organization, [])
}

console.log('search query object')

assertEqual(
	'describes the search in the C2 shape, organization as organisation',
	searchQuery({
		query: 'fietspad',
		facets: { wooCategory: ['infocat014'], organization: ['org-1'] },
		periodFrom: '',
		periodTo: '',
	}),
	{
		text: 'fietspad',
		filters: {
			informatiecategorie: ['infocat014'],
			organisation: ['org-1'],
			periodFrom: '',
			periodTo: '',
		},
		catalog: '',
	},
)

// REQ-PFS-CONTENT-001: the search reaches the text inside public documents.
{
	const base = {
		endpoint: '/api/federation/publications',
		origin: 'https://portaal.example',
		pageSize: 12,
		page: 1,
		facetFields: ['themes'],
		facets: {},
	}
	const flag = (state) =>
		new URL(buildRequestUrl({ ...base, ...state })).searchParams.get('_content')

	assertEqual(
		'a term sends the content flag',
		flag({ query: 'geluidsscherm' }),
		'true',
	)
	assertEqual('no term sends no content flag', flag({ query: '' }), null)
	assertEqual(
		'a portal that switched it off sends no content flag',
		flag({ query: 'geluidsscherm', searchInsideDocuments: false }),
		null,
	)
	assertEqual(
		'a portal that never said sends the content flag',
		flag({ query: 'a', searchInsideDocuments: undefined }),
		'true',
	)

	assertEqual(
		'a row with resultType document is a document',
		resultKind({ resultType: 'document' }),
		'document',
	)
	assertEqual(
		'the schema word on @self marks a document too',
		resultKind({ '@self': { schema: 'Document' } }),
		'document',
	)
	assertEqual(
		'a row with no type is a publication',
		resultKind({ name: 'x' }),
		'publication',
	)
	assertEqual(
		'a numeric schema id is not a type',
		resultKind({ '@self': { schema: 17 } }),
		'publication',
	)

	const document = toResult({
		resultType: 'document',
		name: 'Rapport geluid.pdf',
		'@self': { id: 'd-42' },
		publication: { title: 'Besluit Stationsweg' },
	})
	assertEqual(
		'a document row names its publication',
		document.publication,
		'Besluit Stationsweg',
	)
	assertEqual('a document row keeps its own id', document.id, 'd-42')
	assertEqual('a document row is marked a document', document.kind, 'document')
	assertEqual(
		'a publication row names no publication',
		toResult({ name: 'x', '@self': { id: 'p-1' }, publication: 'y' })
			.publication,
		'',
	)

	// search-filter-by-kind (REQ-SFK-001, REQ-SFK-002).
	// The facet fixture is REQ-SUB-004's response shape: opencatalogi counts
	// `resultType` as a terms facet beside the others.
	const kindFacet = {
		resultType: {
			data: {
				buckets: [
					{ value: 'subject', count: 1 },
					{ value: 'publication', count: 14 },
					{ value: 'document', count: 3 },
				],
			},
		},
	}
	assertEqual(
		'the kind facet reads as Publicatie, Document, Onderwerp with their counts',
		labelBuckets(toBuckets(kindFacet, KIND_FIELD), KIND_FIELD, 'nl').map(
			(b) => [b.value, b.label, b.count],
		),
		[
			['publication', 'Publicatie', 14],
			['document', 'Document', 3],
			['subject', 'Onderwerp', 1],
		],
	)
	assertEqual('the kinds read in English', kindLabel('subject', 'en'), 'Subject')
	assertEqual('an unknown kind reads as itself', kindLabel('zaak', 'nl'), 'zaak')
	assertEqual(
		'no resultType facet offers no filter',
		toBuckets({ wooCategory: { buckets: [{ value: 'a', count: 1 }] } }, KIND_FIELD),
		[],
	)
	assertEqual('the kind is asked first', withKindField(['wooCategory']), [
		'resultType',
		'wooCategory',
	])
	assertEqual(
		'a placement can leave the kind out',
		withKindField(['resultType', 'wooCategory'], true),
		['wooCategory'],
	)
	assertEqual(
		'the kind is not asked twice',
		withKindField(['resultType', 'organization']),
		['resultType', 'organization'],
	)

	const fields = withKindField(['wooCategory'])
	const chosen = new URL(
		buildRequestUrl({
			...{ endpoint: '/api/search', origin: 'https://x.example', pageSize: 10, page: 1 },
			query: 'parkeren',
			facetFields: fields,
			facets: { resultType: ['subject'], wooCategory: [] },
		}),
	)
	assertEqual('a chosen kind is sent', chosen.searchParams.getAll('resultType'), [
		'subject',
	])
	assertEqual(
		'the kind facet is asked for',
		chosen.searchParams.get('_facets[resultType][type]'),
		'terms',
	)
	const none = new URL(
		buildRequestUrl({
			...{ endpoint: '/api/search', origin: 'https://x.example', pageSize: 10, page: 1 },
			query: 'parkeren',
			facetFields: fields,
			facets: { resultType: [], wooCategory: [] },
		}),
	)
	assertEqual('no kind sends no resultType', none.searchParams.has('resultType'), false)

	const written = writeSearchState(
		new URL('https://x.example/zoeken'),
		{ query: 'parkeren', page: 1, facets: { resultType: ['subject'] } },
		fields,
	)
	assertEqual('the chosen kind is written to the address', written.searchParams.get('f.resultType'), 'subject')
	assertEqual(
		'and read back from it',
		readSearchState(written.search, fields).facets.resultType,
		['subject'],
	)

	const subject = toResult({
		resultType: 'subject',
		slug: 'parkeren',
		name: 'Parkeren',
		publicationCount: 14,
		'@self': { id: 's-1' },
	})
	assertEqual('a subject hit keeps its slug and its count', [subject.kind, subject.slug, subject.publicationCount], ['subject', 'parkeren', 14])
	assertEqual('a slug that is not a path segment is dropped', subjectSlug({ slug: '../x' }), '')
	assertEqual('a publication carries no slug', 'slug' in toResult({ name: 'x', slug: 'y', '@self': { id: 'p' } }), false)
	assertEqual(
		'an unknown kind renders as a publication',
		toResult({ resultType: 'zaak', name: 'x', '@self': { id: 'p' } }).kind,
		'publication',
	)
	assertEqual('the buckets keep their order', kindBuckets([{ value: 'zaak', label: '', count: 1 }, { value: 'document', label: '', count: 1 }], 'nl').map((b) => b.value), ['document', 'zaak'])

	// The block links a subject to its landing page and says how many
	// publications it holds, and asks for the kind facet.
	const block = readFileSync(
		new URL('../src/site/components/FederatedSearchBlock.vue', import.meta.url),
		'utf8',
	)
	assertTrue('the block asks for the kind facet', /withKindField\(own, this\.hideKind\)/.test(block))
	assertTrue(
		'a subject links to /onderwerp/{slug}',
		/result\.kind === 'subject' && result\.slug\) \{\s*return `\$\{this\.subjectRoute\}\/\$\{result\.slug\}`/.test(block),
	)
	assertTrue('the block says how many publications a subject holds', /federated-search-publication-count/.test(block))
}

console.log('a locked filter (home-and-theme-landing-pages REQ-HTL-001)')
{
	const base = {
		endpoint: '/index.php/apps/opencatalogi/api/federation/publications',
		origin: 'https://portal.example',
		pageSize: 20,
		page: 1,
		query: '',
		facetFields: ['themes', 'organization'],
		facets: { themes: ['visitor-picked'], organization: ['org-1'] },
		lockedFilters: { themes: 'subject-7' },
	}
	const url = new URL(buildRequestUrl(base))
	assertEqual('a locked filter is always sent', url.searchParams.getAll('themes'), ['subject-7'])
	assertEqual('a locked field is not asked for as a facet', url.searchParams.has('_facets[themes][type]'), false)
	assertEqual('the other facets still are', url.searchParams.get('_facets[organization][type]'), 'terms')
	assertEqual('a value the visitor picked for the locked field is not sent', url.searchParams.getAll('themes').includes('visitor-picked'), false)
	assertEqual('the other filters the visitor picked are sent', url.searchParams.getAll('organization'), ['org-1'])
	assertEqual('several values of one lock are all sent', new URL(buildRequestUrl({ ...base, lockedFilters: { themes: ['a', 'b'] } })).searchParams.getAll('themes'), ['a', 'b'])
	assertEqual('without a lock the request is what it was', new URL(buildRequestUrl({ ...base, lockedFilters: undefined })).searchParams.getAll('themes'), ['visitor-picked'])
	assertEqual('a lock that names nothing locks nothing', lockedFiltersOf({ lockedFilters: { themes: '', '': 'x', other: [] } }), {})
	assertEqual('a lock list or string is no lock', [lockedFiltersOf({ lockedFilters: ['themes'] }), lockedFiltersOf({ lockedFilters: 'themes' })], [{}, {}])
	assertEqual('the facet fields lose the locked field', withoutLocked(['themes', 'organization'], { themes: ['s'] }), ['organization'])

	const locked = lockedFiltersOf(base)
	const offered = withoutLocked(['themes', 'organization'], locked)
	const written = writeSearchState(new URL('https://portal.example/zoeken'), { query: '', page: 1, sort: '', facets: base.facets }, offered)
	assertEqual('a locked filter is not written to the address', [written.searchParams.has('f.themes'), written.searchParams.get('f.organization')], [false, 'org-1'])
	assertEqual('a locked field is not read back from the address', Object.keys(readSearchState('?f.themes=x&f.organization=y', offered).facets), ['organization'])

	const block = readFileSync(new URL('../src/site/components/FederatedSearchBlock.vue', import.meta.url), 'utf8')
	assertTrue('the block takes the lock and shows a fixed chip without a remove control', /lockedFilters: \{/.test(block) && /federated-search-locked-chip/.test(block) && !/locked-chip[^>]*@click/.test(block))
	assertTrue('the block sends the lock and offers no facet for it', /lockedFilters: this\.locked/.test(block) && /withoutLocked\(withKindField\(own, this\.hideKind\), this\.locked\)/.test(block))
}

console.log('a link with q (search-reads-q-from-the-address, REQ-SRQ-001)')

{
	const fields = ['wooCategory']
	assertEqual('?q= opens the search for that term', readSearchState('?route=/zoeken&q=Lindelaan', fields).query, 'Lindelaan')
	assertEqual('_search wins when both are there', readSearchState('?q=oud&_search=nieuw', fields).query, 'nieuw')
	assertEqual('no term is no term', readSearchState('?route=/zoeken', fields).query, '')

	const state = { query: 'fietspad', page: 1, sort: '', facets: { wooCategory: [] } }
	const fromQ = writeSearchState(new URL('https://portal.example/site?route=/zoeken&q=Lindelaan'), state, fields)
	assertEqual('a visitor who came with q keeps q, now with the new term', [fromQ.searchParams.get('q'), fromQ.searchParams.has('_search')], ['fietspad', false])
	assertEqual('and that address opens the new search again', readSearchState(fromQ.search, fields).query, 'fietspad')

	const plain = writeSearchState(new URL('https://portal.example/site?route=/zoeken'), state, fields)
	assertEqual('without q the term stays _search', [plain.searchParams.get('_search'), plain.searchParams.has('q')], ['fietspad', false])

	const cleared = writeSearchState(new URL('https://portal.example/site?route=/zoeken&q=Lindelaan'), { ...state, query: '' }, fields)
	assertEqual('clearing the term removes q', cleared.searchParams.has('q'), false)
}

console.log('relevance (search-sort-by-relevance REQ-SSR-001 to REQ-SSR-004)')

{
	const fuzzy = new URL(buildRequestUrl({ ...BASE, query: 'parkeervergunnig' })).searchParams
	assertEqual('a term always asks for fuzzy matching', fuzzy.get('_fuzzy'), 'true')
	assertEqual('no term sends no fuzzy flag', new URL(buildRequestUrl(BASE)).searchParams.has('_fuzzy'), false)
	const ranked = new URL(buildRequestUrl({ ...BASE, query: 'parkeren', sort: RELEVANCE_SORT })).searchParams
	assertEqual('the relevance order asks for _order[_relevance]=DESC with _fuzzy', [ranked.get('_order[_relevance]'), ranked.get('_fuzzy')], ['DESC', 'true'])

	assertEqual('"Meest relevant" comes first with a term', sortOptionsFor('parkeren', false)[0], { value: RELEVANCE_SORT, label: 'Meest relevant' })
	assertTrue('no relevance without a term', !sortOptionsFor('  ', false).some((o) => o.value === RELEVANCE_SORT))
	assertTrue('no relevance once the backend showed it does not rank', !sortOptionsFor('parkeren', true).some((o) => o.value === RELEVANCE_SORT))

	assertEqual('a new search with a term starts on relevance', sortForNewSearch('parkeren', '', false), RELEVANCE_SORT)
	assertEqual('a chosen date order is kept', sortForNewSearch('parkeren', 'publicationDate:DESC', false), 'publicationDate:DESC')
	assertEqual('clearing the term leaves relevance', sortForNewSearch('', RELEVANCE_SORT, false), '')
	assertEqual('an unranking backend gets the default order', sortForNewSearch('parkeren', '', true), '')

	assertEqual('a score is read from @self.relevance', relevanceOf({ '@self': { relevance: 87.5 } }), 87.5)
	assertEqual('no score is null', relevanceOf({ '@self': {} }), null)
	assertEqual('a ranked first row proves the order was applied', relevanceApplied([{ '@self': { relevance: 40 } }, {}]), true)
	assertEqual('a response lacking @self.relevance did not rank', relevanceApplied([{ '@self': { id: 'a' } }]), false)
	assertEqual('an empty answer proves nothing', relevanceApplied([]), true)
	assertEqual('the match reads in whole percent', matchDescription(87.5), 'Overeenkomst: 88 procent')
	assertEqual('no score, no words', matchDescription(null), '')
	assertEqual('the result carries its score', toResult({ name: 'A', '@self': { relevance: 12 } }).relevance, 12)
	assertEqual('a result without one has no key for it', 'relevance' in toResult({ name: 'A' }), false)

	const { loadSfc } = await import('./support/render-sfc.mjs')
	const block = await loadSfc('src/site/components/FederatedSearchBlock.vue', {
		'@conduction/nextcloud-vue/public': 'export const CnSiteSearch = {}',
	})
	const requested = []
	globalThis.window = { location: { origin: 'https://portal.example.org', search: '' } }
	globalThis.fetch = async (url) => {
		requested.push(url)
		return { ok: true, json: async () => ({ results: [{ name: 'Oud', '@self': { id: 'a' } }], total: 1, pages: 1 }) }
	}
	const vm = {
		...BASE,
		fields: [],
		facets: {},
		locked: {},
		facetLabels: {},
		facetNames: {},
		sort: RELEVANCE_SORT,
		query: 'parkeren',
		sequence: 0,
		relevanceUnavailable: false,
		relevanceNotice: '',
		written: 0,
		writeLocation() {
			this.written++
		},
		reportSearch() {},
		requestUrl: block.methods.requestUrl,
	}
	vm.search = block.methods.search
	await vm.search()
	await new Promise((resolve) => setTimeout(resolve, 0))
	assertEqual('an unranked answer drops relevance and searches again in the default order', [vm.relevanceUnavailable, vm.sort, requested.length, vm.written], [true, '', 2, 1])
	assertTrue('the second request asks no relevance order', !requested[1].includes('_relevance'))
	assertEqual('and says so once in the live region', block.computed.status.call({ ...vm, loading: false, error: false, total: 1, countSentence: 'Zoekresultaten geladen. 1 resultaat gevonden.' }), 'Sorteren op relevantie kan hier niet. Zoekresultaten geladen. 1 resultaat gevonden.')
}

if (failures > 0) {
	console.error(`\n${failures} assertion(s) failed`)
	process.exit(1)
}

console.log('\nall federated-search assertions held')
