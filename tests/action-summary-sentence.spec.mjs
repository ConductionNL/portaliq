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
import { dayWords, summarySentence } from '../src/site/components/forms/summary.js'

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

test('a date answer reads as a day in words, and a phrase still wins', () => {
	const now = new Date(2026, 9, 5, 9, 0)
	assert.equal(dayWords('2026-10-05', { now }), 'vandaag')
	assert.equal(dayWords('2026-10-06', { now }), 'morgen')
	assert.equal(dayWords('2026-10-04', { now }), 'gisteren')
	assert.equal(dayWords('2026-10-12', { now }), 'maandag 12 oktober')
	assert.equal(
		summarySentence(
			{ template: '{day}.' },
			{ day: '2026-10-06' },
			{},
			{ now: new Date(2026, 9, 6) },
		),
		'Vandaag.',
		'the context decides what today is',
	)
	assert.equal(dayWords('2027-01-04', { now }), 'maandag 4 januari 2027')
	assert.equal(dayWords('2026-10-06', { now, locale: 'en' }), 'tomorrow')
	assert.equal(dayWords('not a day', { now }), '')
	assert.equal(
		summarySentence(
			{ template: '{learner} is {day} ziek.' },
			{ learner: 'sami', day: '2026-10-05' },
			OPTIONS,
			{ now },
		),
		'Sami is vandaag ziek.',
	)
	assert.equal(
		summarySentence(
			{
				template: '{day}.',
				phrases: { day: { '2026-10-05': 'op de studiedag' } },
			},
			{ day: '2026-10-05' },
			{},
			{ now },
		),
		'Op de studiedag.',
	)
})

test('the form reads the sentence from answerSummary, never from the tile summary (decision 127)', () => {
	const source = readFileSync(
		new URL('../src/site/components/c/SchemaForm.vue', import.meta.url),
		'utf8',
	)
	assert.match(source, /summarySentence\(\s*this\.action\?\.answerSummary/)
	assert.match(source, /action\.answerSummary\.label/)
	assert.doesNotMatch(source, /action\??\.summary\b/, 'action.summary is the start tile string now')
	// A tile sentence handed over by mistake yields no answer sentence.
	assert.equal(summarySentence('Maak bezwaar.', { learner: 'sami' }), '')
})
