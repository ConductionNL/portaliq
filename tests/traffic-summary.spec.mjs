#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// traffic-summary.spec.mjs — the Traffic page's arithmetic.
//
// Usage:
//   node --test tests/traffic-summary.spec.mjs
//
// The case that matters most is the negative one: a portal that is not
// measured must be told apart from a measured portal with nothing yet,
// because both are "no numbers" and only one of them is a zero.

import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
	daysBetween,
	hasDimension,
	isMeasured,
	lastDays,
	rollupOf,
	segmentsOf,
	summarise,
	verdict,
	warnedSwitches,
	zTest,
} from '../src/lib/trafficSummary.js'

describe('lastDays', () => {
	it('ends today in UTC and counts back', () => {
		const days = lastDays(3, new Date('2026-09-04T23:30:00Z'))
		assert.deepEqual(days, ['2026-09-02', '2026-09-03', '2026-09-04'])
	})
})

describe('daysBetween', () => {
	it('is inclusive, in order, and empty when reversed or malformed', () => {
		assert.deepEqual(daysBetween('2026-09-02', '2026-09-04'), [
			'2026-09-02',
			'2026-09-03',
			'2026-09-04',
		])
		assert.deepEqual(daysBetween('2026-09-04', '2026-09-04'), ['2026-09-04'])
		assert.deepEqual(daysBetween('2026-09-04', '2026-09-02'), [])
		assert.deepEqual(daysBetween('', '2026-09-02'), [])
		assert.deepEqual(daysBetween('yesterday', 'today'), [])
	})

	it('caps a custom range at a year from its start', () => {
		assert.equal(daysBetween('2020-01-01', '2026-01-01').length, 366)
	})
})

describe('hasDimension', () => {
	it('is true only for a listed dimension; a portal that named none has the defaults', () => {
		assert.equal(
			hasDimension({ traffic: { dimensions: ['region'] } }, 'region'),
			true,
		)
		assert.equal(
			hasDimension({ traffic: { dimensions: ['region'] } }, 'browser'),
			false,
		)
		assert.equal(hasDimension({ traffic: { enabled: true } }, 'region'), false)
		assert.equal(hasDimension(null, 'region'), false)
	})
})

describe('isMeasured and warnedSwitches', () => {
	it('is measured only when enabled is literally true', () => {
		assert.equal(isMeasured({ traffic: { enabled: true } }), true)
		assert.equal(isMeasured({ traffic: { enabled: 'true' } }), false)
		assert.equal(isMeasured({ traffic: {} }), false)
		assert.equal(isMeasured({}), false)
		assert.equal(isMeasured(null), false)
	})

	it('lists the sensitive switches that are literally true, in contract order', () => {
		const portal = {
			traffic: {
				sensitive: {
					sessionRecording: true,
					persistClientId: true,
					heatmaps: 'true',
				},
			},
		}
		assert.deepEqual(warnedSwitches(portal), [
			'persistClientId',
			'sessionRecording',
		])
		assert.deepEqual(warnedSwitches({ traffic: {} }), [])
	})
})

describe('summarise', () => {
	const dates = ['2026-09-02', '2026-09-03', '2026-09-04']
	const records = [
		{
			date: '2026-09-04',
			pageViews: 5,
			sessions: 3,
			visitors: 2,
			engagedSessions: 1,
			pages: [
				{ path: '/', views: 3, entrances: 2, exits: 1 },
				{ path: '/woo', views: 2, entrances: 1, exits: 2 },
			],
			transitions: [{ from: '/', to: '/woo', count: 2 }],
			referrers: [
				{ host: 'www.google.nl', channel: 'organic search', count: 2 },
				{ host: '', channel: 'direct', count: 1 },
			],
		},
		{
			date: '2026-09-02',
			pageViews: 1,
			sessions: 1,
			visitors: 1,
			engagedSessions: 0,
			pages: [{ path: '/', views: 1, entrances: 1, exits: 1 }],
			transitions: [],
			referrers: [
				{ host: 'www.bing.com', channel: 'organic search', count: 1 },
			],
		},
		// Outside the range: ignored.
		{ date: '2026-08-01', pageViews: 999, sessions: 999, visitors: 999 },
	]

	it('sums the totals over the range only', () => {
		const summary = summarise(records, dates)
		assert.deepEqual(summary.totals, {
			pageViews: 6,
			sessions: 4,
			visitors: 3,
			engagedSessions: 1,
		})
		assert.equal(summary.hasData, true)
	})

	it('draws a zero for a day without a record, so the axis stays a calendar', () => {
		const summary = summarise(records, dates)
		assert.deepEqual(summary.series.dates, dates)
		assert.deepEqual(summary.series.pageViews, [1, 0, 5])
		assert.deepEqual(summary.series.sessions, [1, 0, 3])
		assert.deepEqual(summary.series.visitors, [1, 0, 2])
	})

	it('merges pages and transitions across days and ranks them', () => {
		const summary = summarise(records, dates)
		assert.deepEqual(summary.pages, [
			{ path: '/', views: 4, entrances: 3, exits: 2 },
			{ path: '/woo', views: 2, entrances: 1, exits: 2 },
		])
		assert.deepEqual(summary.transitions, [{ from: '/', to: '/woo', count: 2 }])
	})

	it('groups referrers by channel with the busiest hosts', () => {
		const summary = summarise(records, dates)
		assert.deepEqual(summary.sources, [
			{
				channel: 'organic search',
				count: 3,
				hosts: ['www.google.nl', 'www.bing.com'],
			},
			{ channel: 'direct', count: 1, hosts: [] },
		])
	})

	it('reports no data for an empty range, with zeros rather than NaN', () => {
		const summary = summarise([], dates)
		assert.equal(summary.hasData, false)
		assert.deepEqual(summary.totals, {
			pageViews: 0,
			sessions: 0,
			visitors: 0,
			engagedSessions: 0,
		})
		assert.deepEqual(summary.pages, [])
	})
})

describe('summarise: visitors and breakdowns', () => {
	const dates = ['2026-09-03', '2026-09-04']

	it('reports new versus returning as not available when every day is null, never as zero', () => {
		const summary = summarise(
			[
				{
					date: '2026-09-03',
					visitors: 4,
					newVisitors: null,
					returningVisitors: null,
					accounts: null,
				},
				{
					date: '2026-09-04',
					visitors: 6,
					newVisitors: null,
					returningVisitors: null,
					accounts: null,
				},
			],
			dates,
		)
		assert.equal(summary.days, 2)
		assert.equal(summary.visitors.visitors, 10)
		assert.equal(summary.visitors.newReturningAvailable, false)
		assert.equal(summary.visitors.accountsAvailable, false)
	})

	it('sums new, returning and accounts once any day carries a number', () => {
		const summary = summarise(
			[
				{
					date: '2026-09-03',
					visitors: 4,
					newVisitors: null,
					returningVisitors: null,
				},
				{
					date: '2026-09-04',
					visitors: 6,
					newVisitors: 2,
					returningVisitors: 3,
					accounts: 1,
				},
			],
			dates,
		)
		assert.equal(summary.visitors.newReturningAvailable, true)
		assert.equal(summary.visitors.newVisitors, 2)
		assert.equal(summary.visitors.returningVisitors, 3)
		assert.equal(summary.visitors.accountsAvailable, true)
		assert.equal(summary.visitors.accounts, 1)
	})

	it('merges the five breakdown maps across days and ranks them', () => {
		const summary = summarise(
			[
				{
					date: '2026-09-03',
					devices: { desktop: 3, mobile: 1 },
					regions: { NL: 2, GB: 1 },
					languages: { nl: 4 },
				},
				{
					date: '2026-09-04',
					devices: { mobile: 5 },
					regions: { GB: 2 },
					browsers: { Chrome: 1 },
					os: {},
				},
			],
			dates,
		)
		assert.deepEqual(summary.breakdowns.deviceType, [
			{ value: 'mobile', count: 6 },
			{ value: 'desktop', count: 3 },
		])
		assert.deepEqual(summary.breakdowns.region, [
			{ value: 'GB', count: 3 },
			{ value: 'NL', count: 2 },
		])
		assert.deepEqual(summary.breakdowns.language, [{ value: 'nl', count: 4 }])
		assert.deepEqual(summary.breakdowns.browser, [{ value: 'Chrome', count: 1 }])
		assert.deepEqual(summary.breakdowns.os, [])
	})
})

describe('summarise: outcomes (portal-traffic-outcomes)', () => {
	const dates = ['2026-09-03', '2026-09-04']
	const records = [
		{
			date: '2026-09-03',
			sessions: 4,
			conversionRate: 0.5,
			searches: [{ term: 'woo', count: 2 }],
			goals: [
				{
					id: 'contact',
					name: 'Contact',
					conversions: 2,
					completions: 3,
					value: 20,
				},
			],
			funnels: [
				{
					id: 'signup',
					name: 'Sign up',
					steps: [
						{ name: 'Campaign', sessions: 4, dropOff: 0 },
						{ name: 'Form', sessions: 2, dropOff: 0.5 },
					],
				},
			],
			forms: [
				{
					formId: 'aanmelden',
					starts: 2,
					submits: 1,
					abandons: 1,
					completionRate: 0.5,
					fields: [
						{ fieldId: 'email', avgMs: 3000, abandonedHere: 1 },
						{ fieldId: 'name', avgMs: 1000, abandonedHere: 0 },
					],
				},
			],
			notFound: [{ path: '/oud', hits: 2 }],
			customDimensions: { audience: { inwoner: 3, ondernemer: 1 } },
		},
		{
			date: '2026-09-04',
			sessions: 1,
			conversionRate: 0,
			searches: [
				{ term: 'woo', count: 1 },
				{ term: 'parkeren', count: 1 },
			],
			goals: [
				{
					id: 'contact',
					name: 'Contact page',
					conversions: 0,
					completions: 0,
					value: 0,
				},
			],
			funnels: [
				{
					id: 'signup',
					name: 'Sign up',
					steps: [
						{ name: 'Campaign', sessions: 1, dropOff: 0 },
						{ name: 'Form', sessions: 0, dropOff: 1 },
					],
				},
			],
			forms: [
				{
					formId: 'aanmelden',
					starts: 1,
					submits: 0,
					abandons: 0,
					fields: [],
				},
			],
			notFound: [
				{ path: '/oud', hits: 1 },
				{ path: '/weg', hits: 1 },
			],
			customDimensions: { audience: { inwoner: 1 } },
		},
	]

	it('merges goals by id, keeps the latest name and re-derives the conversion rate from sessions', () => {
		const summary = summarise(records, dates)
		assert.deepEqual(summary.goals, [
			{
				id: 'contact',
				name: 'Contact page',
				conversions: 2,
				completions: 3,
				value: 20,
			},
		])
		assert.equal(summary.conversionRate, 0.4)
	})

	it('sums funnel steps by position and recomputes the drop-off', () => {
		const summary = summarise(records, dates)
		assert.deepEqual(summary.funnels, [
			{
				id: 'signup',
				name: 'Sign up',
				steps: [
					{ name: 'Campaign', sessions: 5, dropOff: 0 },
					{ name: 'Form', sessions: 2, dropOff: 0.6 },
				],
			},
		])
	})

	it('sums forms, ranks the fields by where people left and names the worst', () => {
		const summary = summarise(records, dates)
		assert.equal(summary.forms.length, 1)
		const form = summary.forms[0]
		assert.equal(form.starts, 3)
		assert.equal(form.submits, 1)
		assert.equal(form.abandons, 1)
		assert.equal(form.completionRate, 0.333)
		assert.equal(form.leaveField, 'email')
		assert.equal(form.fields[0].fieldId, 'email')
		assert.equal(form.fields[0].avgMs, 3000)
	})

	it('ranks searches, missing pages and dimension values', () => {
		const summary = summarise(records, dates)
		assert.deepEqual(summary.searches, [
			{ term: 'woo', count: 3 },
			{ term: 'parkeren', count: 1 },
		])
		assert.deepEqual(summary.notFound, [
			{ path: '/oud', hits: 3 },
			{ path: '/weg', hits: 1 },
		])
		assert.deepEqual(summary.customDimensions, {
			audience: [
				{ value: 'inwoner', count: 4 },
				{ value: 'ondernemer', count: 1 },
			],
		})
	})

	it('is empty, not absent, for records without outcomes', () => {
		const summary = summarise([{ date: '2026-09-03', sessions: 1 }], dates)
		assert.deepEqual(summary.goals, [])
		assert.equal(summary.conversionRate, 0)
		assert.deepEqual(summary.funnels, [])
		assert.deepEqual(summary.forms, [])
		assert.deepEqual(summary.notFound, [])
		assert.deepEqual(summary.customDimensions, {})
		assert.deepEqual(summary.searches, [])
	})
})

describe('segments, roll-ups and errors (portal-traffic-reporting)', () => {
	it('lists the usable segments once each and the roll-up members without the portal itself', () => {
		const portal = {
			slug: 'rollup',
			traffic: {
				segments: [
					{
						id: 'desktop',
						name: 'Desktop',
						conditions: [
							{
								dimension: 'deviceType',
								operator: 'is',
								value: 'desktop',
							},
						],
					},
					{
						id: 'desktop',
						conditions: [
							{ dimension: 'os', operator: 'is', value: 'x' },
						],
					},
					{ id: 'empty', conditions: [] },
					{
						id: 'bad id',
						conditions: [
							{ dimension: 'os', operator: 'is', value: 'x' },
						],
					},
					{
						id: 'unnamed',
						conditions: [
							{ dimension: 'os', operator: 'is', value: 'x' },
						],
					},
				],
				rollupOf: ['open-tilburg', 'rollup', '', 7, 'open-venray'],
			},
		}
		assert.deepEqual(segmentsOf(portal), [
			{ id: 'desktop', name: 'Desktop' },
			{ id: 'unnamed', name: 'unnamed' },
		])
		assert.deepEqual(rollupOf(portal), ['open-tilburg', 'open-venray'])
		assert.deepEqual(segmentsOf(null), [])
		assert.deepEqual(rollupOf({ traffic: { enabled: true } }), [])
	})

	it('merges script errors by message and source across the days', () => {
		const summary = summarise(
			[
				{
					date: '2026-09-03',
					errors: [
						{
							message: 'boom',
							source: 'x/app.js',
							hits: 2,
							pages: ['/'],
						},
					],
				},
				{
					date: '2026-09-04',
					errors: [
						{
							message: 'boom',
							source: 'x/app.js',
							hits: 3,
							pages: ['/', '/a'],
						},
						{ message: 'other', hits: 1, pages: [] },
					],
				},
			],
			['2026-09-03', '2026-09-04'],
		)
		assert.deepEqual(summary.errors, [
			{ message: 'boom', source: 'x/app.js', hits: 5, pages: ['/', '/a'] },
			{ message: 'other', source: '', hits: 1, pages: [] },
		])
	})
})

describe('experiments and heatmaps (portal-traffic-experiments)', () => {
	const day = (date, a, b, clicks) => ({
		portal: 'p',
		date,
		sessions: 60,
		experiments: [
			{
				id: 'hero',
				name: 'Hero',
				status: 'running',
				variants: [
					{ id: 'a', name: 'A', sessions: 30, conversions: a, rate: 0 },
					{ id: 'b', name: 'B', sessions: 30, conversions: b, rate: 0 },
				],
				winner: '',
				confidence: 0,
			},
		],
		heatmaps: [
			{
				path: '/',
				samples: clicks,
				clicks: [{ x: 1, y: 2, count: clicks }],
				scroll: [0, 0, 0, 0, 0, 0, 0, 0, 0, 1],
			},
		],
	})

	it('sums variants across days and re-derives the verdict from the sums', () => {
		const summary = summarise(
			[day('2026-09-01', 1, 6, 3), day('2026-09-02', 1, 6, 2)],
			['2026-09-01', '2026-09-02'],
		)
		assert.equal(summary.experiments.length, 1)
		const experiment = summary.experiments[0]
		assert.deepEqual(experiment.variants[0], {
			id: 'a',
			name: 'A',
			sessions: 60,
			conversions: 2,
			rate: 0.033,
		})
		assert.deepEqual(experiment.variants[1], {
			id: 'b',
			name: 'B',
			sessions: 60,
			conversions: 12,
			rate: 0.2,
		})
		assert.equal(experiment.winner, 'b')
		assert.equal(experiment.enough, true)
		assert.ok(experiment.confidence > 0.95)
	})

	it('names no winner under thirty sessions per variant, and says so', () => {
		// 1 of 30 against 5 of 30 is z = 1.72, a confidence of 0.915: enough
		// sessions, not enough difference.
		const one = summarise([day('2026-09-01', 1, 5, 1)], ['2026-09-01'])
		assert.equal(one.experiments[0].winner, '')
		assert.equal(one.experiments[0].enough, true)
		assert.equal(one.experiments[0].confidence, 0.915)
		const few = verdict([
			{ id: 'a', sessions: 10, conversions: 0 },
			{ id: 'b', sessions: 40, conversions: 20 },
		])
		assert.equal(few.winner, '')
		assert.equal(few.enough, false)
		assert.deepEqual(verdict([]), { winner: '', confidence: 0, enough: false })
	})

	it('matches the aggregation on a known table', () => {
		assert.equal(zTest(50, 1000, 80, 1000), 0.993)
		assert.equal(zTest(10, 100, 11, 100), 0.182)
		assert.equal(zTest(5, 100, 5, 100), 0)
		assert.equal(zTest(0, 0, 5, 100), 0)
	})

	it('sums a page heatmap across days', () => {
		const summary = summarise(
			[day('2026-09-01', 0, 0, 3), day('2026-09-02', 0, 0, 2)],
			['2026-09-01', '2026-09-02'],
		)
		assert.deepEqual(summary.heatmaps, [
			{
				path: '/',
				samples: 5,
				clicks: [{ x: 1, y: 2, count: 5 }],
				scroll: [0, 0, 0, 0, 0, 0, 0, 0, 0, 2],
			},
		])
		assert.deepEqual(summarise([], ['2026-09-01']).heatmaps, [])
		assert.deepEqual(summarise([], ['2026-09-01']).experiments, [])
	})
})
