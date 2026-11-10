#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// traffic-summary-golden.spec.mjs: the whole summary of a range of daily
// traffic records (totals, series, visitors, breakdowns, pages, sources and
// every outcome list), pinned in a golden file before the folding was split
// into named steps.
//
// Usage:
//   node --test tests/traffic-summary-golden.spec.mjs
//   GOLDEN_RECORD=1 node --test tests/traffic-summary-golden.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { summarise } from '../src/lib/trafficSummary.js'
import { golden } from './support/golden.mjs'

const DATES = ['2026-03-01', '2026-03-02', '2026-03-03', '2026-03-04']

const DAY_ONE = {
	date: '2026-03-01',
	pageViews: 120,
	sessions: 40,
	visitors: 30,
	engagedSessions: 25,
	newVisitors: 20,
	returningVisitors: 10,
	accounts: null,
	conversionRate: 0.25,
	devices: { mobile: 25, desktop: 15, '': 3 },
	browsers: { firefox: 10 },
	os: ['not', 'a', 'map'],
	languages: null,
	regions: { 'NL-UT': 7 },
	pages: [
		{ path: '/', views: 80, entrances: 30, exits: 10 },
		{ path: '/zoeken', views: 40, entrances: 5, exits: 20 },
		{ path: '', views: 99 },
	],
	referrers: [
		{ channel: 'search', host: 'www.google.com', count: 12 },
		{ channel: 'search', host: 'duckduckgo.com', count: 3 },
		{ channel: 'social', host: 'mastodon.social', count: 2 },
		{ host: '', count: 9 },
	],
	searches: [{ term: 'paspoort', count: 5 }, { term: '', count: 9 }, { term: 'afval', count: 2 }],
	zeroResultSearches: [{ term: 'xyz', count: 2 }, { term: '' }],
	searchesWithoutCount: 3,
	goals: [
		{ id: 'g1', name: 'Aanvraag', conversions: 4, completions: 5, value: 10 },
		{ goal: 'g2', conversions: 1, value: -3 },
		{ conversions: 7 },
	],
	funnels: [
		{ id: 'f1', name: 'Aanvragen', steps: [{ name: 'Start', sessions: 40 }, { name: 'Gegevens', sessions: 20 }, { name: 'Klaar', sessions: 0 }] },
		{ steps: [] },
	],
	forms: [
		{
			formId: 'parkeren',
			starts: 10,
			submits: 4,
			abandons: 6,
			fields: [
				{ fieldId: 'kenteken', avgMs: 3000, abandonedHere: 4 },
				{ fieldId: 'naam', avgMs: 0, abandonedHere: 0 },
				{ fieldId: '' },
			],
		},
		{ formId: '' },
	],
	notFound: [{ path: '/oud', hits: 4 }, { path: '' }],
	errors: [
		{ message: 'x is undefined', source: 'app.js', hits: 3, pages: ['/a', '/b', '/a'] },
		{ message: '' },
	],
	experiments: [
		{
			id: 'e1',
			name: 'Knop',
			status: 'running',
			variants: [
				{ id: 'a', name: 'Groen', sessions: 400, conversions: 40 },
				{ id: 'b', name: 'Blauw', sessions: 400, conversions: 80 },
				{ id: '' },
			],
		},
		{ id: '' },
	],
	heatmaps: [
		{ path: '/', samples: 5, clicks: [{ x: 1, y: 2, count: 3 }, { x: 1, y: 2, count: 1 }], scroll: [5, 4, 3, 2, 1, 0, 0, 0, 0, 0, 9] },
		{ path: '' },
	],
	customDimensions: { school: { 'De Linde': 4, 'Het Kompas': 2 }, broken: 'nope' },
}

const DAY_TWO = {
	date: '2026-03-02',
	pageViews: 60,
	sessions: 20,
	visitors: 18,
	engagedSessions: 'x',
	accounts: 3,
	conversionRate: 2,
	devices: { mobile: 5 },
	pages: [{ path: '/', views: 10, entrances: 1, exits: 1 }],
	referrers: [{ channel: 'search', host: 'www.google.com', count: 4 }],
	searches: [{ term: 'paspoort', count: 1 }],
	goals: [{ id: 'g1', name: 'Aanvraag gedaan', conversions: 2, completions: 2, value: 5 }],
	funnels: [{ id: 'f1', steps: [{ sessions: 10 }, { name: 'Gegevens!', sessions: 5 }] }],
	forms: [{ formId: 'parkeren', starts: 2, submits: 2, fields: [{ fieldId: 'kenteken', avgMs: 1000, abandonedHere: 1 }] }, { formId: 'melding', starts: 0 }],
	errors: [{ message: 'x is undefined', source: 'app.js', hits: 1, pages: ['/c'] }, { message: 'x is undefined', source: 'other.js', hits: 2 }],
	experiments: [{ id: 'e1', variants: [{ id: 'a', sessions: 10, conversions: 1 }, { id: 'c', name: '', sessions: 5 }] }, { id: 'e2', name: '', status: '', variants: [] }],
	heatmaps: [{ path: '/', samples: 1, scroll: [1] }, { path: '/zoeken', samples: 9, clicks: [{ x: 0, y: 0, count: 2 }] }],
	customDimensions: { school: { 'De Linde': 1 }, empty: {} },
}

const DAY_OUTSIDE = { date: '2026-02-28', pageViews: 999, sessions: 999 }

test('the summary of a range of daily records', () => {
	const file = golden('traffic-summary')
	const summary = summarise([DAY_TWO, null, { date: 5 }, DAY_OUTSIDE, DAY_ONE], DATES)
	assert.deepEqual(Object.keys(summary), [
		'totals', 'series', 'days', 'visitors', 'breakdowns', 'pages', 'sources',
		'searches', 'zeroResultSearches', 'searchesWithoutCount', 'goals', 'conversionRate',
		'funnels', 'forms', 'notFound', 'errors', 'customDimensions', 'experiments', 'heatmaps', 'hasData',
	])
	file.check('summary', summary)
	file.check('summaryJson', JSON.stringify(summary))
	file.check('empty', summarise(null, DATES))
	file.check('none', summarise([], []))
	file.save()
})
