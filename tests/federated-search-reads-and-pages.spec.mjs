#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// federated-search-reads-and-pages.spec.mjs: every branch of how the search
// block (src/site/lib/federatedSearch.js) reads a row's kind, slug and
// publication, the facet buckets, the pagination row, the search state from
// an address, the saved-search query and the locked filters; pinned before
// these were split into named steps.
//
// Usage:
//   node --test tests/federated-search-reads-and-pages.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	lockedFiltersOf,
	paginationItems,
	readSearchState,
	resultKind,
	searchQuery,
	subjectSlug,
	toBuckets,
	toResult,
} from '../src/site/lib/federatedSearch.js'

test('a subject slug is a plain address part from the row or its envelope', () => {
	assert.equal(subjectSlug({ slug: 'afval-en-milieu' }), 'afval-en-milieu')
	assert.equal(subjectSlug({ '@self': { slug: 'Wonen_2' } }), 'Wonen_2')
	assert.equal(subjectSlug({ slug: '', '@self': { slug: 'x' } }), 'x')
	assert.equal(subjectSlug({ slug: 'a/b' }), '')
	assert.equal(subjectSlug({ slug: '-a' }), '')
	assert.equal(subjectSlug({ slug: 'a'.repeat(129) }), '')
	assert.equal(subjectSlug({ slug: 'a'.repeat(128) }), 'a'.repeat(128))
	assert.equal(subjectSlug({ slug: 12 }), '12')
	assert.equal(subjectSlug(null), '')
	assert.equal(subjectSlug({ '@self': null }), '')
})

test('a row is a document or a subject when it says so, else a publication', () => {
	assert.equal(resultKind({ resultType: ' Document ' }), 'document')
	assert.equal(resultKind({ '@self': { resultType: 'subject' } }), 'subject')
	assert.equal(resultKind({ '@self': { schema: 'SUBJECT' } }), 'subject')
	assert.equal(resultKind({ resultType: 'publication', '@self': { schema: 'document' } }), 'publication')
	assert.equal(resultKind({ resultType: '', '@self': { resultType: 'document', schema: 'subject' } }), 'document')
	assert.equal(resultKind({ '@self': { schema: 'besluit' } }), 'publication')
	assert.equal(resultKind({}), 'publication')
	assert.equal(resultKind(null), 'publication')
	assert.equal(resultKind({ '@self': null }), 'publication')
})

test('a document names the publication it belongs to', () => {
	const doc = (fields) => toResult({ id: 'd', title: 'Bijlage', resultType: 'document', ...fields }).publication
	assert.equal(doc({ publication: { title: 'Besluit', name: 'b' } }), 'Besluit')
	assert.equal(doc({ publication: { name: 'besluit-1' } }), 'besluit-1')
	assert.equal(doc({ publication: {} }), '')
	assert.equal(doc({ publication: 'Raadsbesluit' }), 'Raadsbesluit')
	assert.equal(doc({ publication: '', publicationTitle: 'Titel' }), 'Titel')
	assert.equal(doc({ publication: 7, publicationTitle: 'Titel' }), 'Titel')
	assert.equal(doc({ publication: null }), '')
	assert.equal(doc({}), '')
	assert.equal(toResult({ id: 'p', title: 'P', publication: 'X' }).publication, '', 'only a document names one')
})

test('facet buckets come in either shape and drop empty values', () => {
	assert.deepEqual(toBuckets({ f: { data: { buckets: [{ value: 'a', label: 'A', count: 2 }, { value: '', count: 1 }] } } }, 'f'), [
		{ value: 'a', label: 'A', count: 2 },
	])
	assert.deepEqual(toBuckets({ f: { buckets: [{ key: 'k', results: 3 }, { key: 0, label: null }, { value: null, key: 'v' }] } }, 'f'), [
		{ value: 'k', label: 'k', count: 3 },
		{ value: '0', label: '0', count: 0 },
		{ value: 'v', label: 'v', count: 0 },
	])
	assert.deepEqual(toBuckets({ f: { buckets: [{ value: 'x', label: '', count: '4' }] } }, 'f'), [{ value: 'x', label: '', count: 4 }])
	assert.deepEqual(toBuckets({ f: { buckets: [{}] } }, 'f'), [])
	assert.deepEqual(toBuckets(null, 'f'), [])
	assert.deepEqual(toBuckets({ f: { data: {} } }, 'f'), [])
})

test('the pagination row: five at the start, a window later, first and last always, gaps where it skips', () => {
	assert.deepEqual(paginationItems(1, 0), [1])
	assert.deepEqual(paginationItems(1, 1), [1])
	assert.deepEqual(paginationItems(1, 2), [1, 2])
	assert.deepEqual(paginationItems(1, 36), [1, 2, 3, 4, 5, 'gap', 36])
	assert.deepEqual(paginationItems(4, 36), [1, 2, 3, 4, 5, 'gap', 36])
	assert.deepEqual(paginationItems(5, 36), [1, 'gap', 4, 5, 6, 'gap', 36])
	assert.deepEqual(paginationItems(35, 36), [1, 'gap', 34, 35, 36])
	assert.deepEqual(paginationItems(36, 36), [1, 'gap', 35, 36])
	assert.deepEqual(paginationItems(3, 6), [1, 2, 3, 4, 5, 6])
	assert.deepEqual(paginationItems(2, 3), [1, 2, 3])
	assert.deepEqual(paginationItems(40, 36), [1, 'gap', 36])
	assert.deepEqual(paginationItems(0, 7), [1, 2, 3, 4, 5, 'gap', 7])
})

test('the search state reads every filter from the address, the old _facets as the first field', () => {
	assert.deepEqual(readSearchState('?_search=gft&_page=3&_sort=date&f.wooCategory=a,b,&f.organization=o&periodFrom=2026-01-01&periodTo=2026-02-31', ['wooCategory', 'organization']), {
		query: 'gft', page: 3, sort: 'date', facets: { wooCategory: ['a', 'b'], organization: ['o'] }, periodFrom: '2026-01-01', periodTo: '',
	})
	assert.deepEqual(readSearchState('?q=Lindelaan&_facets=x,y', ['wooCategory', 'organization']), {
		query: 'Lindelaan', page: 1, sort: '', facets: { wooCategory: ['x', 'y'], organization: [] }, periodFrom: '', periodTo: '',
	})
	assert.deepEqual(readSearchState('?_facets=x&f.wooCategory=z', ['wooCategory']).facets, { wooCategory: ['z'] }, 'the field wins over the old parameter')
	assert.deepEqual(readSearchState('?_facets=x', []).facets, {})
	assert.deepEqual(readSearchState('?_facets=', ['a']).facets, { a: [] })
	assert.deepEqual(readSearchState('?q=a&_search=b', []).query, 'b')
	assert.equal(readSearchState('?_page=-2', []).page, 1)
	assert.equal(readSearchState('?_page=x', []).page, 1)
	assert.deepEqual(readSearchState('', ['a']), { query: '', page: 1, sort: '', facets: { a: [] }, periodFrom: '', periodTo: '' })
	assert.deepEqual(readSearchState(null, []), { query: '', page: 1, sort: '', facets: {}, periodFrom: '', periodTo: '' })
})

test('the saved-search query names C2 filters from either field name', () => {
	assert.deepEqual(searchQuery({ query: 'gft', facets: { wooCategory: ['a'], organization: ['o'] }, periodFrom: '2026-01-01', periodTo: 'x' }, 'cat'), {
		text: 'gft', filters: { informatiecategorie: ['a'], organisation: ['o'], periodFrom: '2026-01-01', periodTo: '' }, catalog: 'cat',
	})
	assert.deepEqual(searchQuery({ facets: { informatiecategorie: ['i'], organisation: ['p'] } }), {
		text: '', filters: { informatiecategorie: ['i'], organisation: ['p'], periodFrom: '', periodTo: '' }, catalog: '',
	})
	const empty = { text: '', filters: { informatiecategorie: [], organisation: [], periodFrom: '', periodTo: '' }, catalog: '' }
	assert.deepEqual(searchQuery(null), empty)
	assert.deepEqual(searchQuery(undefined, null), empty)
	assert.deepEqual(searchQuery({ query: 7, facets: null }, 3), { ...empty, text: '7', catalog: '3' })
	const facets = { wooCategory: ['a'] }
	assert.notEqual(searchQuery({ facets }).filters.informatiecategorie, facets.wooCategory, 'the lists are copies')
})

test('locked filters keep only named fields with values', () => {
	assert.deepEqual(lockedFiltersOf({ lockedFilters: { subject: 'afval', tags: ['a', '', null, 3], leeg: [], '': ['x'], none: null, zero: 0 } }), {
		subject: ['afval'], tags: ['a', '3'], zero: ['0'],
	})
	assert.deepEqual(lockedFiltersOf({ lockedFilters: ['x'] }), {})
	assert.deepEqual(lockedFiltersOf({ lockedFilters: 'x' }), {})
	assert.deepEqual(lockedFiltersOf({ lockedFilters: null }), {})
	assert.deepEqual(lockedFiltersOf({}), {})
	assert.deepEqual(lockedFiltersOf(null), {})
})
