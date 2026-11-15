#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// card-status-branches.spec.mjs: the edges of a card's status today (ids in
// other envelopes, Sundays, blocks without school days, missing or odd
// lookups, the `only` rule, date-times and default tones), pinned before
// cardStatus was split into named checks.
//
// Usage:
//   node --test tests/site-look/card-status-branches.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { cardStatus } from '../../src/site/components/mijn/cardStatus.js'

const STATUS = {
	matchField: 'learnerRef',
	fromField: 'dateFrom',
	toField: 'dateTo',
	label: 'Ziek gemeld',
	otherLabel: 'Op school',
}
const TUESDAY = new Date(2026, 9, 6, 23, 59)
const SATURDAY = new Date(2026, 9, 10, 10, 30)
const SUNDAY = new Date(2026, 9, 11, 10, 30)
const SICK = { learnerRef: 'sam', dateFrom: '2026-10-05T08:00:00', dateTo: '2026-10-07T17:00:00' }
const loaded = (objects) => ({ objects })

test('the card id is read from id, uuid or the envelope, and a card without one shows nothing', () => {
	for (const card of [{ id: 'sam' }, { uuid: 'sam' }, { '@self': { id: 'sam' } }]) {
		assert.deepEqual(cardStatus(card, STATUS, loaded([SICK]), TUESDAY), { text: 'Ziek gemeld', tone: 'neutral' })
	}
	assert.equal(cardStatus({}, STATUS, loaded([SICK]), TUESDAY), null)
	assert.equal(cardStatus(null, STATUS, loaded([SICK]), TUESDAY), null)
})

test('only school days, when the block says so', () => {
	const schoolDays = { ...STATUS, schoolDaysOnly: true }
	assert.equal(cardStatus({ id: 'sam' }, schoolDays, loaded([]), SUNDAY), null)
	assert.equal(cardStatus({ id: 'sam' }, schoolDays, loaded([]), SATURDAY), null)
	assert.deepEqual(cardStatus({ id: 'sam' }, STATUS, loaded([]), SUNDAY), { text: 'Op school', tone: 'neutral' })
})

test('a missing lookup or odd rows', () => {
	assert.equal(cardStatus({ id: 'sam' }, STATUS, null, TUESDAY), null)
	assert.deepEqual(cardStatus({ id: 'sam' }, STATUS, { objects: 'x' }, TUESDAY), { text: 'Op school', tone: 'neutral' })
	assert.deepEqual(cardStatus({ id: 'sam' }, { ...STATUS, otherLabel: '' }, loaded([null, {}]), TUESDAY), null)
})

test('the covering rule: the card, the only rule, from and to', () => {
	const card = { id: 'sam' }
	const only = { ...STATUS, only: { field: 'state', in: ['open'] }, tone: 'warning', otherTone: 'success' }
	assert.equal(cardStatus(card, only, loaded([SICK]), TUESDAY).text, 'Op school')
	assert.equal(cardStatus(card, only, loaded([{ ...SICK, state: 'open' }]), TUESDAY).tone, 'warning')
	assert.equal(cardStatus(card, only, loaded([{ ...SICK, state: 'closed' }]), TUESDAY).tone, 'success')
	const cases = [
		[{ learnerRef: 'max', dateFrom: '2026-10-06' }, 'Op school'],
		[{ learnerRef: 'sam', dateFrom: '2026-10-06' }, 'Ziek gemeld'],
		[{ learnerRef: 'sam', dateFrom: '2026-10-07' }, 'Op school'],
		[{ learnerRef: 'sam', dateFrom: '2026-10-01', dateTo: '2026-10-05' }, 'Op school'],
		[{ learnerRef: 'sam', dateTo: '2026-10-06' }, 'Op school'],
		[{ learnerRef: 'sam', dateFrom: 'soon' }, 'Op school'],
		[{ learnerRef: 'sam', dateFrom: ' 2026-10-06 ' }, 'Ziek gemeld'],
	]
	for (const [row, text] of cases) {
		assert.equal(cardStatus(card, STATUS, loaded([row]), TUESDAY).text, text, JSON.stringify(row))
	}
})
