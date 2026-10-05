#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// action-summary-sentence.spec.mjs: one sentence from the resident's own
// answers, shown above the send button and on the confirmation.
//
// Usage:
//   node --test tests/action-summary-sentence.spec.mjs
//
// @spec openspec/changes/action-summary-sentence/specs/portal-contribution-contract/spec.md#requirement-an-action-may-sum-up-the-answers-in-one-sentence

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { summarySentence } from '../src/site/components/forms/summary.js'

const SUMMARY = {
	label: 'U meldt',
	template: '{learner} is {when} {reason}.',
	phrases: {
		when: { today: 'vandaag de hele dag', tomorrow: 'morgen de hele dag' },
		reason: { sick: 'ziek' },
	},
}
const OPTIONS = {
	learner: [
		{ value: 'vera', label: 'Vera' },
		{ value: 'sami', label: 'Sami' },
	],
	reason: [
		{ value: 'sick', label: 'Ziek' },
		{ value: 'doctor', label: 'Dokter of tandarts' },
	],
}

test('the answers read as one sentence, by phrase, then option label', () => {
	assert.equal(
		summarySentence(
			SUMMARY,
			{ learner: 'sami', when: 'today', reason: 'sick' },
			OPTIONS,
		),
		'Sami is vandaag de hele dag ziek.',
	)
	assert.equal(
		summarySentence(
			SUMMARY,
			{ learner: 'vera', when: 'tomorrow', reason: 'doctor' },
			OPTIONS,
		),
		'Vera is morgen de hele dag Dokter of tandarts.',
		'without a phrase, the option label stands in',
	)
})

test('no sentence until every answer it names is given', () => {
	assert.equal(
		summarySentence(SUMMARY, { learner: 'sami', when: 'today' }, OPTIONS),
		'',
	)
	assert.equal(
		summarySentence(
			SUMMARY,
			{ learner: 'sami', when: 'today', reason: '  ' },
			OPTIONS,
		),
		'',
	)
	assert.equal(summarySentence(null, { learner: 'sami' }), '')
	assert.equal(summarySentence({ template: '' }, {}), '')
})

test('a typed answer stands as typed, a list joins, and the sentence opens with a capital', () => {
	assert.equal(
		summarySentence({ template: '{note}.' }, { note: 'buikgriep' }),
		'Buikgriep.',
	)
	assert.equal(
		summarySentence(
			{ template: 'Op {days}.' },
			{ days: ['ma', 'di'] },
			{
				days: [
					{ value: 'ma', label: 'maandag' },
					{ value: 'di', label: 'dinsdag' },
				],
			},
		),
		'Op maandag, dinsdag.',
	)
	assert.equal(
		summarySentence({ template: '{note}' }, { note: { a: 1 } }),
		'',
		'an object is no answer',
	)
})

test('the sentence is text, never markup', () => {
	assert.equal(
		summarySentence({ template: '{note}' }, { note: '<b>x</b>' }),
		'<b>x</b>',
	)
	const form = readFileSync(
		new URL('../src/site/components/c/SchemaForm.vue', import.meta.url),
		'utf8',
	)
	assert.doesNotMatch(
		form,
		/v-html=/,
		'the form renders no authored or answered HTML',
	)
	assert.match(
		form,
		/summary: this\.summaryText/,
		'the confirmation keeps the sentence as it was sent',
	)
	assert.match(form, /aria-live="polite"[\s\S]*data-testid="schema-form-summary"/)
})
