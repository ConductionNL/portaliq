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
	isMeasured,
	lastDays,
	summarise,
	warnedSwitches,
} from '../src/lib/trafficSummary.js'

describe('lastDays', () => {
	it('ends today in UTC and counts back', () => {
		const days = lastDays(3, new Date('2026-09-04T23:30:00Z'))
		assert.deepEqual(days, ['2026-09-02', '2026-09-03', '2026-09-04'])
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
