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
	summarise,
	warnedSwitches,
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
		assert.equal(hasDimension({ traffic: { dimensions: ['region'] } }, 'region'), true)
		assert.equal(hasDimension({ traffic: { dimensions: ['region'] } }, 'browser'), false)
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
				{ date: '2026-09-03', visitors: 4, newVisitors: null, returningVisitors: null, accounts: null },
				{ date: '2026-09-04', visitors: 6, newVisitors: null, returningVisitors: null, accounts: null },
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
				{ date: '2026-09-03', visitors: 4, newVisitors: null, returningVisitors: null },
				{ date: '2026-09-04', visitors: 6, newVisitors: 2, returningVisitors: 3, accounts: 1 },
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
				{ date: '2026-09-03', devices: { desktop: 3, mobile: 1 }, regions: { NL: 2, GB: 1 }, languages: { nl: 4 } },
				{ date: '2026-09-04', devices: { mobile: 5 }, regions: { GB: 2 }, browsers: { Chrome: 1 }, os: {} },
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
