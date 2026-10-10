#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// card-status.spec.mjs: a child's card says where the child is today, from
// the guardian's own absence reports (card-status-today).
//
// Usage:
//   node --test tests/site-look/card-status.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { cardStatus } from '../../src/site/components/mijn/cardStatus.js'
import { collectionIdsFor } from '../../src/site/pages/collections/collectionLoader.js'
import { renderSfc } from '../support/render-sfc.mjs'

const STATUS = {
	collection: 'parentExcuseRequests',
	matchField: 'learnerRef',
	fromField: 'dateFrom',
	toField: 'dateTo',
	only: { field: 'lifecycle', in: ['submitted', 'approved'] },
	label: 'Ziek gemeld',
	tone: 'warning',
	otherLabel: 'Op school',
	otherTone: 'success',
	schoolDaysOnly: true,
}
const TUESDAY = new Date(2026, 9, 6, 10, 30)
const SATURDAY = new Date(2026, 9, 10, 10, 30)
const SAM = { id: 'sam', givenName: 'Sam' }
function report(fields) {
	return {
		learnerRef: 'sam',
		dateFrom: '2026-10-06',
		dateTo: '2026-10-06',
		lifecycle: 'submitted',
		...fields,
	}
}
const loaded = (objects) => ({ loading: false, objects })

test('a report that covers today reads "Ziek gemeld"', () => {
	assert.deepEqual(cardStatus(SAM, STATUS, loaded([report({})]), TUESDAY), {
		text: 'Ziek gemeld',
		tone: 'warning',
	})
	// Over more days, and a date-time.
	assert.equal(
		cardStatus(
			SAM,
			STATUS,
			loaded([
				report({
					dateFrom: '2026-10-05T08:00:00+02:00',
					dateTo: '2026-10-07',
				}),
			]),
			TUESDAY,
		).text,
		'Ziek gemeld',
	)
	// One day without an end.
	assert.equal(
		cardStatus(SAM, STATUS, loaded([report({ dateTo: '' })]), TUESDAY).text,
		'Ziek gemeld',
	)
})

test('any other school day reads "Op school"', () => {
	for (const rows of [
		[],
		[report({ dateFrom: '2026-10-01', dateTo: '2026-10-02' })],
		[report({ learnerRef: 'noor' })],
		[report({ lifecycle: 'rejected' })],
	]) {
		assert.deepEqual(cardStatus(SAM, STATUS, loaded(rows), TUESDAY), {
			text: 'Op school',
			tone: 'success',
		})
	}
})

test('a weekend, rows still loading or a failed read show no chip', () => {
	assert.equal(cardStatus(SAM, STATUS, loaded([report({})]), SATURDAY), null)
	assert.equal(
		cardStatus(SAM, STATUS, { loading: true, objects: [] }, TUESDAY),
		null,
	)
	assert.equal(
		cardStatus(SAM, STATUS, { failed: true, objects: [] }, TUESDAY),
		null,
	)
	assert.equal(cardStatus(SAM, null, loaded([]), TUESDAY), null)
	assert.equal(
		cardStatus(SAM, { ...STATUS, otherLabel: undefined }, loaded([]), TUESDAY),
		null,
	)
})

test('the page loads the status collection', () => {
	assert.ok(
		collectionIdsFor({
			blocks: [
				{ type: 'collection', collection: 'parentChildren', status: STATUS },
			],
		}).includes('parentExcuseRequests'),
	)
})

test('the card shows the chip it derives, not a stored status', async () => {
	const html = await renderSfc('src/site/components/mijn/ProgressCards.vue', {
		rows: [{ ...SAM, state: 'stored' }],
		block: {
			display: 'cards',
			titleFields: ['givenName'],
			statusField: 'state',
			status: STATUS,
		},
		statusRows: loaded([report({})]),
		today: TUESDAY,
	})
	assert.match(html, /Ziek gemeld/)
	assert.doesNotMatch(html, /stored/)
})
