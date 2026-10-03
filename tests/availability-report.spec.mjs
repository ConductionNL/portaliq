#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// availability-report.spec.mjs: the Availability report in the Reports hub
// (operate-availability-report T06) reads the admin route, says "Not
// measured" for a month without intervals, names each outage cause in words,
// and is reachable from the Reports hub.
//
// Usage:
//   node --test tests/availability-report.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	availabilityUrl,
	causeLabel,
	durationLabel,
	exportUrl,
	percentLabel,
	portalsFrom,
} from '../src/lib/availabilityReport.js'

function t(app, text, vars = {}) {
	return text.replace(/\{(\w+)\}/g, (m, key) =>
		key in vars ? String(vars[key]) : m,
	)
}
const url = (path) => `/index.php${path}`

test('the routes name the portal', () => {
	assert.equal(
		availabilityUrl('open tilburg', url),
		'/index.php/apps/portaliq/api/availability/open%20tilburg?months=12',
	)
	assert.equal(
		exportUrl('open-tilburg', url),
		'/index.php/apps/portaliq/api/availability/open-tilburg/export?months=12',
	)
})

test('a month is a percentage to two decimals, or not measured', () => {
	assert.equal(percentLabel(99.65, t), '99.65%')
	assert.equal(percentLabel(100, t), '100.00%')
	assert.equal(percentLabel(null, t), 'Not measured')
})

test('each cause and duration reads as words', () => {
	assert.equal(causeLabel('site-error', t), 'The portal answered with an error')
	assert.equal(
		causeLabel('timeout', t),
		'The portal did not answer within five seconds',
	)
	assert.equal(
		causeLabel('health-degraded', t),
		'The portal answered, but its health check did not say ok',
	)
	assert.equal(causeLabel('no-check', t), 'No check ran')
	assert.equal(durationLabel(60, t), '60 minutes')
	assert.equal(durationLabel(0, t), 'Less than a minute')
})

test('the portal picker lists published portals by title', () => {
	assert.deepEqual(
		portalsFrom({
			results: [
				{ slug: 'b', title: 'Beta' },
				{ slug: 'a', title: 'Alpha' },
				{ title: 'no slug' },
			],
		}),
		[
			{ id: 'a', label: 'Alpha' },
			{ id: 'b', label: 'Beta' },
		],
	)
	assert.deepEqual(portalsFrom([{ slug: 'x' }]), [{ id: 'x', label: 'x' }])
})

test('the Reports hub offers the report and the app registers its widget', () => {
	const manifest = JSON.parse(
		readFileSync(new URL('../src/manifest.json', import.meta.url), 'utf8'),
	)
	const hub = manifest.pages.find((page) => page.id === 'Reports')
	const card = hub.config.cards.find(
		(candidate) => candidate.id === 'Availability',
	)
	assert.equal(card.route, 'Availability')
	const page = manifest.pages.find((candidate) => candidate.id === 'Availability')
	assert.equal(page.type, 'dashboard')
	assert.ok(
		page.config.widgets.some((widget) => widget.type === 'AvailabilityReport'),
	)
	const registry = readFileSync(
		new URL('../src/registry.js', import.meta.url),
		'utf8',
	)
	assert.match(
		registry,
		/AvailabilityReport: \{[^}]*kind: 'widget',\s*component: AvailabilityReport,/,
	)
})
