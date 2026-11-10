#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// federated-search-request.spec.mjs: every query parameter the federated
// search request carries and every fallback of the row it draws, pinned
// before the request building and the row flattening were split into steps.
//
// Usage:
//   node --test tests/federated-search-request.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { buildRequestUrl, toResult } from '../src/site/lib/federatedSearch.js'

const base = { endpoint: '/api/search', origin: 'https://portal.example', pageSize: 20, page: 3, query: '' }

/**
 * The query parameters of one built request, in order.
 *
 * @param {object} state The search state.
 * @return {Array<Array<string>>} The name and value pairs.
 */
function paramsOf(state) {
	return [...new URL(buildRequestUrl(state)).searchParams.entries()]
}

test('an empty term sends only the page, the limit and the facet', () => {
	assert.deepEqual(paramsOf({ ...base, facetField: 'kind' }), [
		['_limit', '20'],
		['_page', '3'],
		['_facets[kind][type]', 'terms'],
	])
})

test('without facet fields the themes facet is asked for; a term asks for content and fuzzy matching unless content is switched off', () => {
	assert.deepEqual(paramsOf({ ...base, query: 'gas', facetFields: [] }), [
		['_limit', '20'],
		['_page', '3'],
		['_search', 'gas'],
		['_content', 'true'],
		['_fuzzy', 'true'],
		['_facets[themes][type]', 'terms'],
	])
	assert.deepEqual(paramsOf({ ...base, query: 'gas', facetFields: [], searchInsideDocuments: false }), [
		['_limit', '20'],
		['_page', '3'],
		['_search', 'gas'],
		['_fuzzy', 'true'],
		['_facets[themes][type]', 'terms'],
	])
})

test('facet fields, ticked values and locked filters go along in order', () => {
	const state = {
		...base,
		facetFields: ['kind', 'organisation', 'theme'],
		facets: { kind: ['document', 'publication'], theme: ['water'] },
		lockedFilters: { organisation: 'gemeente', status: ['open', 'closed'] },
	}
	assert.deepEqual(paramsOf(state), [
		['_limit', '20'],
		['_page', '3'],
		['_facets[kind][type]', 'terms'],
		['_facets[theme][type]', 'terms'],
		['kind', 'document'],
		['kind', 'publication'],
		['theme', 'water'],
		['organisation', 'gemeente'],
		['status', 'open'],
		['status', 'closed'],
	])
})

test('the single facet field takes the selected facets', () => {
	assert.deepEqual(paramsOf({ ...base, facetField: 'kind', selectedFacets: ['document'] }), [
		['_limit', '20'],
		['_page', '3'],
		['_facets[kind][type]', 'terms'],
		['kind', 'document'],
	])
})

test('the period is a range on its field, an unreadable date is left out', () => {
	assert.deepEqual(paramsOf({ ...base, facetFields: [], periodFrom: '2026-01-02', periodTo: '2026-03-04' }), [
		['_limit', '20'],
		['_page', '3'],
		['_facets[themes][type]', 'terms'],
		['publicationDate[gte]', '2026-01-02'],
		['publicationDate[lte]', '2026-03-04T23:59:59Z'],
	])
	assert.deepEqual(paramsOf({ ...base, facetFields: [], periodField: 'created', periodFrom: 'nope', periodTo: '2026-03-04' }), [
		['_limit', '20'],
		['_page', '3'],
		['_facets[themes][type]', 'terms'],
		['created[lte]', '2026-03-04T23:59:59Z'],
	])
	assert.deepEqual(paramsOf({ ...base, facetFields: [], periodFrom: '2026-01-02' }), [
		['_limit', '20'],
		['_page', '3'],
		['_facets[themes][type]', 'terms'],
		['publicationDate[gte]', '2026-01-02'],
	])
})

test('a sort needs both a field and a direction', () => {
	assert.deepEqual(paramsOf({ ...base, facetFields: [], sort: 'title:ASC' }), [
		['_limit', '20'],
		['_page', '3'],
		['_facets[themes][type]', 'terms'],
		['_order[title]', 'ASC'],
	])
	assert.deepEqual(paramsOf({ ...base, facetFields: [], sort: 'title' }), [['_limit', '20'], ['_page', '3'], ['_facets[themes][type]', 'terms']])
	assert.deepEqual(paramsOf({ ...base, facetFields: [], sort: ':DESC' }), [['_limit', '20'], ['_page', '3'], ['_facets[themes][type]', 'terms']])
})

test('an empty row degrades to an untitled publication', () => {
	const result = toResult(null)
	assert.equal(result.title, 'Zonder titel')
	assert.equal(result.key, '')
	assert.equal(result.id, '')
	assert.equal(result.summary, '')
	assert.equal(result.href, '')
	assert.equal(result.directory, '')
	assert.equal(result.type, '')
	assert.equal(result.publication, '')
	assert.equal(result.date, '')
	assert.equal(result.relevance, undefined)
})

test('the row reads @self first where it names the same thing', () => {
	const result = toResult({
		'@self': { id: 'self-id', name: 'Self name', summary: 'Kort', uri: 'https://u', schemaTitle: 'Besluit', directory: 'https://other' },
		id: 'row-id',
		name: 'Row name',
		description: 'Lang',
		url: 'https://r',
	})
	assert.equal(result.key, 'self-id')
	assert.equal(result.id, 'self-id')
	assert.equal(result.title, 'Row name')
	assert.equal(result.summary, 'Kort')
	assert.equal(result.href, 'https://r')
	assert.equal(result.type, 'Besluit')
})

test('each fallback of the key, title, summary and link is taken in turn', () => {
	assert.equal(toResult({ sha: 's', name: 'n' }).key, 's')
	assert.equal(toResult({ name: 'n' }).key, 'n')
	assert.equal(toResult({ id: 'i', sha: 's' }).key, 'i')
	assert.equal(toResult({ '@self': { name: 'Self' } }).title, 'Self')
	assert.equal(toResult({ '@self': { title: 'Titel' } }).title, 'Titel')
	assert.equal(toResult({ description: 'Lang' }).summary, 'Lang')
	assert.equal(toResult({ description: { nl: 'x' } }).summary, '')
	assert.equal(toResult({ description: 'a'.repeat(300) }).summary.length, 280)
	assert.equal(toResult({ landingUrl: 'https://l', url: 'https://u' }).href, 'https://l')
	assert.equal(toResult({ '@self': { uri: 'https://s' } }).href, 'https://s')
	assert.equal(toResult({ id: 'row' }).id, 'row')
})

test('the date reads the publication date before the published stamp', () => {
	const fromRow = toResult({ publicationDate: '2026-05-01', '@self': { published: '2025-01-01' } }).date
	const fromSelf = toResult({ '@self': { published: '2026-05-01' } }).date
	assert.equal(fromRow, fromSelf)
	assert.notEqual(fromRow, '')
})
