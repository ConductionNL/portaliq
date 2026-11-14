#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// federated-search-sort-by-date.spec.mjs — results can be sorted by date,
// newest first, next to relevance, and the order travels in the page address
// (capability-rows-said-and-planned REQ-RSP-008, row sib-opencatalogi-srch-sort).
//
// Usage:
//   node --test tests/federated-search-sort-by-date.spec.mjs

import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
	buildRequestUrl,
	readSearchState,
	RELEVANCE_SORT,
	sortOptionsFor,
	writeSearchState,
} from '../src/site/lib/federatedSearch.js'

const NEWEST_FIRST = 'publicationDate:DESC'

describe('newest first (REQ-RSP-008)', () => {
	it('is offered next to relevance when a visitor searches', () => {
		const values = sortOptionsFor('subsidie', false).map((option) => option.value)
		assert.equal(values[0], RELEVANCE_SORT)
		assert.ok(values.includes(NEWEST_FIRST))
		const label = sortOptionsFor('subsidie', false).find((option) => option.value === NEWEST_FIRST).label
		assert.equal(label, 'Datum - nieuw naar oud')
	})

	it('is offered without a term too', () => {
		assert.ok(sortOptionsFor('', false).some((option) => option.value === NEWEST_FIRST))
	})

	it('travels in the page address and comes back from it', () => {
		const state = { query: 'subsidie', page: 1, sort: NEWEST_FIRST, facets: {} }
		const written = writeSearchState(new URL('https://portaal.example/zoeken'), state, [])
		assert.equal(new URL(written).searchParams.get('_sort'), NEWEST_FIRST)
		assert.equal(readSearchState(new URL(written).search, []).sort, NEWEST_FIRST)
	})

	it('asks the catalogue for publication date, newest first', () => {
		const url = new URL(buildRequestUrl({
			endpoint: '/index.php/apps/opencatalogi/api/federation/publications',
			origin: 'https://portaal.example',
			pageSize: 10,
			page: 1,
			query: 'subsidie',
			facetField: 'categories',
			selectedFacets: [],
			sort: NEWEST_FIRST,
		}))
		assert.equal(url.searchParams.get('_order[publicationDate]'), 'DESC')
	})
})
