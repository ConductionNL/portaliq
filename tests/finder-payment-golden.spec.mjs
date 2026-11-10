#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// finder-payment-golden.spec.mjs: the product finder's plan for sets of
// answers, and a fee and a payment as the form shows them, pinned before
// each was split into named steps. A formatted fee is compared with the
// platform's own formatter, so the test holds on any ICU build.
//
// Usage:
//   node --test tests/finder-payment-golden.spec.mjs
//   GOLDEN_RECORD=1 node --test tests/finder-payment-golden.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { feeAmount, paymentView } from '../src/site/components/forms/payment.js'
import { planFinder, pruneAnswers } from '../src/site/lib/finderPlan.js'
import { golden } from './support/golden.mjs'

const FINDER = {
	products: [{ route: '/a', title: 'A' }, { route: '/b', title: 'B' }, { route: '/c', title: 'C' }],
	questions: [
		{ id: 'q1', excludesOnYes: ['/a'], excludesOnNo: ['/b', '/c'] },
		{ id: 'q2', excludesOnYes: ['/a'] },
		{ id: 'q3', excludesOnNo: ['/c'], excludesOnYes: 'x' },
		{ id: 'q4' },
	],
}

test('the finder plan for every set of answers', () => {
	const file = golden('finder-plan')
	const sets = [{}, { q1: 'yes' }, { q1: 'no' }, { q1: 'yes', q2: 'yes', q3: 'no' }, { q1: 'maybe', q3: 'yes' }, { q2: 'no' }]
	file.check('planFinder', sets.map((answers) => planFinder(FINDER, answers)))
	file.check('planFinder without a finder', [planFinder(null, {}), planFinder({ products: 'x', questions: 'y' }, {})])
	file.check('pruneAnswers', sets.map((answers) => pruneAnswers(FINDER, answers)))
	file.save()
})

test('a fee and a payment as the form shows them', () => {
	const nl = (amount, currency) => new Intl.NumberFormat('nl-NL', { style: 'currency', currency }).format(amount)
	const en = (amount, currency) => new Intl.NumberFormat('en-GB', { style: 'currency', currency }).format(amount)
	assert.equal(feeAmount({ amount: '45', currency: 'EUR' }, 'nl'), nl(45, 'EUR'))
	assert.equal(feeAmount({ amount: 12.5, currency: 'USD' }, 'en-US'), en(12.5, 'USD'))
	assert.equal(feeAmount({ amount: '3', currency: 'EUR' }, ''), nl(3, 'EUR'))
	for (const fee of [null, { amount: '0', currency: 'EUR' }, { amount: '-1', currency: 'EUR' }, { amount: 'x', currency: 'EUR' }, { amount: '5', currency: 'eur' }, { amount: '5' }]) {
		assert.equal(feeAmount(fee, 'nl'), '', JSON.stringify(fee))
	}
	assert.equal(feeAmount({ amount: '5', currency: 'XYZ' }, 'nl'), nl(5, 'XYZ'))
	assert.deepEqual(['paid', 'unpaid', 'failed', 'open'].map((state) => paymentView({ state })), [
		{ key: 'paid', again: false },
		{ key: 'unpaid', again: true },
		{ key: 'paymentFailed', again: true },
		{ key: 'paymentUnknown', again: false },
	])
	assert.equal(paymentView(null), null)
	assert.equal(paymentView({ state: 3 }), null)
	assert.deepEqual(paymentView({ state: 'toString' }), { key: 'paymentUnknown', again: false })
})
