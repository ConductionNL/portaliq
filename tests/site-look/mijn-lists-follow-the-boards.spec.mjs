#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// mijn-lists-follow-the-boards.spec.mjs: the lists of the school portals as
// their boards draw them: grades grouped per subject with an average, rows
// and cards that open their own page with a chevron, tabs over a list, the
// "Nieuw" mark, and dates in words (mijn-lists-follow-the-boards).
//
// Usage:
//   node --test tests/site-look/mijn-lists-follow-the-boards.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { formatMoment } from '../../src/site/components/collections/cells.js'
import {
	chipSummary,
	dayWords,
	groupedChipRows,
	isNew,
	rowRoute,
	rowsForTab,
} from '../../src/site/components/mijn/lists.js'
import { renderSfc } from '../support/render-sfc.mjs'

const MONDAY = new Date(2026, 9, 5, 9, 0)
function tr(key) {
	return (
		{ Today: 'Vandaag', Tomorrow: 'Morgen', Yesterday: 'Gisteren' }[key] || key
	)
}

/** Noor's grades, one row per mark, as learniq's studentGrades lists them. */
const GRADES = [
	{
		id: 'g1',
		subjectId: 'ne',
		subject: 'Nederlands',
		teacher: 'Mevrouw Kramer',
		grade: 7.1,
		gradedAt: '2026-09-03',
		weight: 1,
	},
	{
		id: 'g2',
		subjectId: 'ne',
		subject: 'Nederlands',
		teacher: 'Mevrouw Kramer',
		grade: 6.8,
		gradedAt: '2026-09-22',
		weight: 1,
	},
	{
		id: 'g3',
		subjectId: 'wi',
		subject: 'Wiskunde A',
		teacher: 'Meneer Demir',
		grade: 4.7,
		gradedAt: '2026-09-24',
		weight: 1,
	},
	{
		id: 'g4',
		subjectId: 'wi',
		subject: 'Wiskunde A',
		teacher: 'Meneer Demir',
		grade: 5.1,
		gradedAt: '2026-09-10',
		weight: 1,
	},
	{
		id: 'g5',
		subjectId: 'wi',
		subject: 'Wiskunde A',
		teacher: 'Meneer Demir',
		grade: 5.8,
		gradedAt: '2026-10-01',
		weight: 1,
	},
	{
		id: 'g6',
		subjectId: 'en',
		subject: 'Engels',
		teacher: 'Mevrouw Jansen',
		grade: '6,9',
		gradedAt: '2026-10-02',
		weight: 2,
	},
	{
		id: 'g7',
		subjectId: 'en',
		subject: 'Engels',
		teacher: 'Mevrouw Jansen',
		grade: 7.4,
		gradedAt: '2026-09-16',
		weight: 1,
	},
	{
		id: 'g8',
		subjectId: 'ckv',
		subject: 'Culturele en kunstzinnige vorming',
		teacher: 'Meneer Dijkstra',
		grade: 'voldoende',
		gradedAt: '2026-09-21',
	},
]

const CHIPS = {
	type: 'collection',
	display: 'chips',
	groupField: 'subject',
	valueField: 'grade',
	dateField: 'gradedAt',
	weightField: 'weight',
	subtitleField: 'teacher',
	newField: 'gradedAt',
	lowBelow: 5.5,
	rowPage: 'subject',
	rowIdField: 'subjectId',
}

test('marks are grouped per subject in date order, with a weighted average and the low mark', () => {
	const entries = groupedChipRows(GRADES, CHIPS, { locale: 'nl', today: MONDAY })
	const wiskunde = entries.find((entry) => entry.label === 'Wiskunde A')
	assert.deepEqual(
		wiskunde.marks.map((mark) => [mark.text, mark.low]),
		[
			['5,1', true],
			['4,7', true],
			['5,8', false],
		],
	)
	assert.equal(wiskunde.average, '5,2')
	assert.equal(wiskunde.averageLow, true)
	assert.equal(wiskunde.subtitle, 'Meneer Demir')
	// Engels: (6,9 x 2 + 7,4) / 3 = 7,07, and its 2 October mark is new.
	const engels = entries.find((entry) => entry.label === 'Engels')
	assert.equal(engels.average, '7,1')
	assert.equal(engels.isNew, true)
	assert.equal(entries.find((entry) => entry.label === 'Nederlands').isNew, false)
	// A subject with a word: the word as its chip, its first letter as the figure.
	const ckv = entries.find((entry) => entry.label.startsWith('Culturele'))
	assert.deepEqual(ckv.marks, [{ text: 'voldoende', low: false }])
	assert.equal(ckv.average, 'V')
	assert.equal(
		rowRoute(wiskunde.row, CHIPS, '/mijn/learniq/subject'),
		'/mijn/learniq/subject/wi',
	)
})

test('the summary averages the subjects and counts the ones below the pass mark', () => {
	const low = { ...CHIPS }
	const summary = chipSummary(
		groupedChipRows(GRADES, low, { locale: 'nl', today: MONDAY }),
		'nl',
	)
	assert.deepEqual(summary, { average: '6,4', count: 3, pass: 2, fail: 1 })
})

test('the grades page renders as the board: link, teacher, chips, Nieuw, Onder 5,5, figure, chevron', async () => {
	const html = await renderSfc('src/site/components/mijn/MarkChips.vue', {
		rows: GRADES,
		block: {
			...CHIPS,
			summary: true,
			summaryText: 'Je staat {pass} vakken voldoende en {fail} onvoldoende.',
		},
		rowPageRoute: '/mijn/learniq/subject',
		today: MONDAY,
		locale: 'nl',
	})
	assert.match(
		html,
		/data-testid="mijn-chips-summary"[\s\S]*6,4[\s\S]*gemiddeld over 3 vakken/,
	)
	assert.match(html, /Je staat 2 vakken voldoende en 1 onvoldoende\./)
	assert.match(
		html,
		/href="[^"]*\/mijn\/learniq\/subject\/wi[^"]*"[^>]*>Wiskunde A<\/a>/,
	)
	assert.match(html, /Meneer Demir/)
	assert.match(html, /Onder 5,5/)
	assert.match(html, /Nieuw/)
	assert.match(html, /pq-chips__chevron/)
})

test('rows open their own page with a chevron, show the big figure, the eyebrow and the day in words', async () => {
	const html = await renderSfc('src/site/components/mijn/DateRows.vue', {
		rows: [
			{
				id: 'g6',
				subject: 'Engels',
				test: 'Leestoets',
				grade: 6.9,
				gradedAt: '2026-10-02',
			},
			{
				id: 'h1',
				subject: 'Nederlands',
				title: 'Leesverslag inleveren',
				dueAt: '2026-10-05',
				status: 'open',
			},
		],
		block: {
			display: 'rows',
			titleFields: ['subject'],
			subtitleField: 'test',
			valueField: 'grade',
			dateField: 'gradedAt',
			dateDisplay: 'line',
			newField: 'gradedAt',
			rowStyle: 'lines',
			rowPage: 'grade',
		},
		rowPageRoute: '/mijn/learniq/grade',
		today: MONDAY,
		locale: 'nl',
	})
	assert.match(html, /pq-date-rows__list--lines/)
	assert.match(
		html,
		/href="[^"]*\/mijn\/learniq\/grade\/g6[^"]*"[^>]*>Engels<\/a>/,
	)
	assert.match(html, /Leestoets · vrijdag 2 oktober/)
	assert.match(html, /data-testid="mijn-date-row-value">6,9</)
	assert.match(html, /Nieuw/)
	assert.match(html, /pq-date-rows__chevron/)
	assert.equal(
		html.includes('pq-date-tile'),
		false,
		'no tile when the date is in the line',
	)

	const homework = await renderSfc('src/site/components/mijn/DateRows.vue', {
		rows: [
			{
				id: 'h1',
				subject: 'Nederlands',
				title: 'Leesverslag inleveren',
				dueAt: '2026-10-05',
				status: 'open',
			},
		],
		block: {
			display: 'rows',
			titleFields: ['title'],
			eyebrowField: 'subject',
			dateField: 'dueAt',
			dateDisplay: 'eyebrow',
			statusField: 'status',
			statusTones: { open: 'warning' },
		},
		today: MONDAY,
		locale: 'nl',
		t: (key) => ({ Today: 'Vandaag' })[key] || key,
	})
	assert.match(homework, /pq-date-rows__eyebrow">\s*Vandaag · Nederlands/)
	assert.equal(
		homework.includes('pq-date-rows__chevron'),
		false,
		'no page, no chevron',
	)

	const certificates = await renderSfc('src/site/components/mijn/DateRows.vue', {
		rows: [{ id: 'c1', name: 'F-gassen categorie 1', validUntil: '2026-11-30' }],
		block: {
			display: 'rows',
			titleFields: ['name'],
			dateField: 'validUntil',
			dateDisplay: 'end',
			dateLabel: 'Geldig tot',
		},
		today: MONDAY,
		locale: 'nl',
	})
	assert.match(
		certificates,
		/Geldig tot (<!--\]-->)?<strong>30 november 2026<\/strong>/,
	)
})

test('cards open their row page', async () => {
	const html = await renderSfc('src/site/components/mijn/ProgressCards.vue', {
		rows: [{ id: 'vera', givenName: 'Vera' }],
		block: { display: 'cards', titleFields: ['givenName'], rowPage: 'child' },
		rowPageRoute: '/mijn/learniq/child',
		locale: 'nl',
	})
	assert.match(
		html,
		/href="[^"]*\/mijn\/learniq\/child\/vera[^"]*"[^>]*>Vera<\/a>/,
	)
	assert.match(html, /pq-progress-cards__chevron/)
})

test('tabs choose rows by a field; a tab without one shows them all', async () => {
	const rows = [
		{ id: 'b1', lifecycle: 'confirmed' },
		{ id: 'b2', lifecycle: 'completed' },
		{ id: 'b3', lifecycle: 'cancelled' },
	]
	const tabs = [
		{ label: 'Komend', field: 'lifecycle', values: ['confirmed', 'waiting'] },
		{ label: 'Afgerond', field: 'lifecycle', values: ['completed'] },
		{ label: 'Alles' },
	]
	assert.deepEqual(
		rowsForTab(rows, tabs[0]).map((row) => row.id),
		['b1'],
	)
	assert.deepEqual(
		rowsForTab(rows, tabs[1]).map((row) => row.id),
		['b2'],
	)
	assert.equal(rowsForTab(rows, tabs[2]).length, 3)
	const html = await renderSfc('src/site/components/mijn/ListTabs.vue', {
		tabs,
		chosen: 1,
	})
	assert.match(html, /role="tablist"/)
	assert.match(html, /aria-selected="true" tabindex="0"[^>]*>\s*Afgerond/)
	assert.equal(html.split('aria-selected="false"').length - 1, 2)
})

test('the near days and the "Nieuw" mark', () => {
	assert.equal(dayWords('2026-10-05', MONDAY, 'nl', tr), 'Vandaag')
	assert.equal(dayWords('2026-10-06', MONDAY, 'nl', tr), 'Morgen')
	assert.equal(dayWords('2026-10-08', MONDAY, 'nl', tr), 'donderdag 8 oktober')
	assert.equal(
		dayWords('2025-11-13', MONDAY, 'nl', tr),
		'donderdag 13 november 2025',
	)
	assert.equal(isNew({ at: '2026-10-02' }, 'at', MONDAY), true)
	assert.equal(isNew({ at: '2026-09-01' }, 'at', MONDAY), false)
	assert.equal(isNew({ fresh: true }, 'fresh', MONDAY), true)
	assert.equal(isNew({ at: '2026-10-02' }, '', MONDAY), false)
})

test('table dates read as words, never d-m-yyyy or a raw stamp', () => {
	assert.equal(formatMoment('2026-10-02', false, 'nl'), '2 oktober 2026')
	assert.equal(
		formatMoment('2025-11-13T08:40:00', true, 'nl'),
		'13 november 2025, 08.40 uur',
	)
	assert.equal(
		formatMoment('2025-11-13T08:40:00', true, 'en'),
		'13 November 2025, 08:40',
	)
	assert.equal(formatMoment('kapot', false, 'nl'), 'kapot')
})

test('the date tile reads the month case and weight from the set', async () => {
	const { readFileSync } = await import('node:fs')
	const source = readFileSync(
		new URL('../../src/site/components/mijn/DateTile.vue', import.meta.url),
		'utf8',
	)
	assert.match(
		source,
		/text-transform: var\(--nldesign-website-date-tile-month-text-transform, none\)/,
	)
	assert.match(
		source,
		/font-weight: var\(--nldesign-website-date-tile-month-font-weight, 400\)/,
	)
})
