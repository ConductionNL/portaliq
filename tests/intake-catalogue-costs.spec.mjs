#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// intake-catalogue-costs.spec.mjs — a catalogue entry shows what it costs and
// what it asks of you (catalogue-entry-shows-costs-and-conditions, REQ-CSC-001).
//
// Usage:
//   node --test tests/intake-catalogue-costs.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	catalogueWords,
	costLine,
	entryConditions,
} from '../src/site/components/catalogueCosts.js'

const block = readFileSync(
	fileURLToPath(new URL('../src/site/components/IntakeCatalogueBlock.vue', import.meta.url)),
	'utf8',
)

/**
 * @param {string} text A formatted line.
 * @return {string} The line with non-breaking spaces as plain spaces.
 */
function plain(text) {
	return text.replace(/[\u00a0\u202f]/g, ' ')
}

describe('the cost line', () => {
	it('words the fee the server read, in the page language', () => {
		const entry = { title: 'Parkeervergunning bewoners', fee: { amount: '38.50', currency: 'EUR' } }
		assert.equal(plain(costLine(entry, 'nl')), 'Kosten: € 38,50')
		assert.equal(plain(costLine(entry, 'en-GB')), 'Costs: €38.50')
	})

	it('is absent without a usable fee', () => {
		assert.equal(costLine({ fee: null }, 'nl'), '')
		assert.equal(costLine({}, 'nl'), '')
		assert.equal(costLine({ fee: { amount: '0.00', currency: 'EUR' } }, 'nl'), '')
		assert.equal(costLine({ fee: { amount: 'gratis', currency: 'EUR' } }, 'nl'), '')
		assert.equal(costLine({ fee: { amount: '12.00', currency: 'euro' } }, 'nl'), '')
	})
})

describe('the conditions', () => {
	it('lists the publication\'s lines, trimmed', () => {
		assert.deepEqual(
			entryConditions({ conditions: [' U woont in de gemeente ', 'U heeft een auto op uw naam'] }),
			['U woont in de gemeente', 'U heeft een auto op uw naam'],
		)
	})

	it('ignores any other shape as a whole', () => {
		assert.deepEqual(entryConditions({}), [])
		assert.deepEqual(entryConditions({ conditions: 'U woont in de gemeente' }), [])
		assert.deepEqual(entryConditions({ conditions: ['U woont in de gemeente', ''] }), [])
		assert.deepEqual(entryConditions({ conditions: ['U woont in de gemeente', 3] }), [])
		assert.deepEqual(entryConditions({ conditions: { a: 'b' } }), [])
	})
})

describe('the block', () => {
	it('has words in Dutch and English', () => {
		assert.equal(catalogueWords('nl').conditions, 'Voorwaarden')
		assert.equal(catalogueWords('en').conditions, 'Conditions')
		assert.equal(catalogueWords('').cost, 'Kosten: {amount}')
	})

	it('draws the cost line and the conditions under the summary, and the link is unchanged', () => {
		const summary = block.indexOf('pq-intake-catalogue__summary')
		const cost = block.indexOf('data-testid="intake-entry-cost"')
		const conditions = block.indexOf('data-testid="intake-entry-conditions"')
		assert.ok(summary > -1 && cost > summary && conditions > cost)
		assert.match(block, /v-if="costLine\(entry, locale\)"/)
		assert.match(block, /v-for="line in entryConditions\(entry\)"/)
		assert.match(block, /:href="hrefFor\(entry\)"/)
	})
})
