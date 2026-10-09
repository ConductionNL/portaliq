#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// search-relevance.spec.mjs: the public search sorts by relevance only when
// it is applied, tolerates a misspelt title, offers a checked "Bedoelde u",
// and explains its ranking from one declaration (search-sort-by-relevance
// REQ-SSR-001 to REQ-SSR-006).
//
// Usage:
//   node --test tests/search-relevance.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import * as search from '../src/site/lib/federatedSearch.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const BLOCK = readFileSync(
	join(ROOT, 'src/site/components/FederatedSearchBlock.vue'),
	'utf8',
)

const STATE = {
	endpoint: '/index.php/apps/opencatalogi/api/federation/publications',
	origin: 'https://site.example',
	pageSize: 10,
	page: 1,
	facetFields: [],
}

test('a term always asks for fuzzy matching', () => {
	const url = new URL(
		search.buildRequestUrl({ ...STATE, query: 'parkeervergunnig' }),
	)
	assert.equal(url.searchParams.get('_fuzzy'), 'true')
	const dated = new URL(
		search.buildRequestUrl({
			...STATE,
			query: 'x',
			sort: 'publicationDate:DESC',
		}),
	)
	assert.equal(dated.searchParams.get('_fuzzy'), 'true')
})

test('no term sends no fuzzy flag', () => {
	const url = new URL(search.buildRequestUrl({ ...STATE, query: '' }))
	assert.equal(url.searchParams.has('_fuzzy'), false)
})

test('Meest relevant is offered with a term only, and a new search with a term defaults to it', () => {
	assert.equal(
		search.sortOptionsFor('parkeervergunning')[0].label,
		'Meest relevant',
	)
	assert.equal(
		search
			.sortOptionsFor('')
			.some((option) => option.label === 'Meest relevant'),
		false,
	)
	assert.equal(
		search
			.sortOptionsFor('x', true)
			.some((option) => option.label === 'Meest relevant'),
		false,
	)
	assert.equal(search.defaultSortFor('parkeervergunning'), '_relevance:DESC')
	assert.equal(search.defaultSortFor(''), '')
	const url = new URL(
		search.buildRequestUrl({ ...STATE, query: 'x', sort: search.RELEVANCE }),
	)
	assert.equal(url.searchParams.get('_order[_relevance]'), 'DESC')
	assert.match(
		BLOCK,
		/this\.sort = defaultSortFor\(this\.query, this\.relevanceOff\)/,
	)
})

test('a response without a score means relevance was not applied', () => {
	assert.equal(
		search.relevanceApplied({ results: [{ '@self': { relevance: 0.82 } }] }),
		true,
	)
	assert.equal(search.relevanceApplied({ results: [{ '@self': {} }] }), false)
	assert.equal(search.relevanceApplied({ results: [] }), null)
	// The block drops the option, says so once and searches again.
	assert.match(
		BLOCK,
		/relevanceApplied\(body\) === false\) \{\s*this\.relevanceOff = true\s*this\.relevanceNotice =\s*'Sorteren op relevantie is hier niet beschikbaar\.'\s*this\.sort = ''/,
	)
})

test('the match is a whole percent for assistive technology only', () => {
	assert.equal(search.matchPercent({ '@self': { relevance: 0.823 } }), 82)
	assert.equal(search.matchPercent({ '@self': { relevance: 82 } }), 82)
	assert.equal(search.matchPercent({ '@self': {} }), null)
	assert.equal(
		search.toResult({ name: 'x', '@self': { relevance: 0.5 } }).match,
		50,
	)
	// The link is DESCRIBED by the match, never named or shown by it.
	assert.match(
		BLOCK,
		/data-testid="federated-search-result-link"\s*:aria-describedby="\s*hasMatch\(result\)\s*\? matchId\(resultIndex\)\s*: null\s*"/,
	)
	assert.match(
		BLOCK,
		/:id="matchId\(resultIndex\)"\s*class="sr-only"\s*data-testid="federated-search-result-match"\s*>Overeenkomst: \{\{ result\.match \}\} procent/,
	)
})

test('fewer than three results asks for a suggestion', () => {
	assert.equal(search.wantsSuggestion('hondenbelasing', 0), true)
	assert.equal(search.wantsSuggestion('hondenbelasing', 2), true)
	assert.equal(search.wantsSuggestion('hondenbelasing', 3), false)
	assert.equal(search.wantsSuggestion('', 0), false)
	const url = new URL(
		search.suggestUrl('https://site.example', 'ws', 'hondenbelasing'),
	)
	assert.equal(url.pathname, '/index.php/apps/portaliq/api/site/search/suggest')
	assert.equal(url.searchParams.get('q'), 'hondenbelasing')
	assert.equal(url.searchParams.get('portal'), 'ws')
	assert.match(BLOCK, /Bedoelde u: \$\{this\.suggestion\.term\}\?/)
	// Announced in the live region, shown once as the link.
	assert.match(
		BLOCK,
		/<span v-if="suggestionText" class="sr-only">\{\{ suggestionText \}\}<\/span>/,
	)
	assert.match(
		BLOCK,
		/data-testid="federated-search-did-you-mean"\s*@click\.prevent="followSuggestion"/,
	)
	assert.match(BLOCK, /this\.askSuggestion\(mine\)/)
})

test('the request follows the ranking declaration', () => {
	const before = search.RANKING.fuzzy
	try {
		search.RANKING.fuzzy = false
		const url = new URL(search.buildRequestUrl({ ...STATE, query: 'x' }))
		assert.equal(url.searchParams.has('_fuzzy'), false)
	} finally {
		search.RANKING.fuzzy = before
	}
})

test('the explanation is rendered from the declaration', () => {
	const t = (key) => key
	const lines = search.rankingExplanation(search.RANKING, t)
	assert.deepEqual(lines, [
		'A search with a term shows the best matches first.',
		'A misspelling is still found in the title.',
		'Only exact words are found in the summary, the text inside documents.',
		'Results from other catalogues without a score come after the scored results.',
	])
	const changed = search.rankingExplanation(
		{
			...search.RANKING,
			fuzzyFields: ['title', 'summary'],
			exactFields: ['documentText'],
		},
		t,
	)
	assert.equal(
		changed[1],
		'A misspelling is still found in the title, the summary.',
	)
	const widget = readFileSync(join(ROOT, 'src/widgets/SearchRanking.vue'), 'utf8')
	assert.match(widget, /rankingExplanation\(RANKING/)
})
