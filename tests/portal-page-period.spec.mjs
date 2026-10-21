#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-page-period.spec.mjs: the portal page declares one period filter
// (7, 30, 365 days; 30 by default) and its four traffic figures read it
// through `@page.period`, without a picker of their own (PtPortal).
//
// Usage:
//   node --test tests/portal-page-period.spec.mjs
//
// @spec openspec/changes/portal-page-period/specs/portal-page-period/spec.md#requirement-the-portal-pages-traffic-figures-follow-one-period-selector

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const CARD = read('src/widgets/PortalTrafficKpi.vue')
const PORTAL = MANIFEST.pages.find((page) => page.id === 'PortalDetail').config
const NL = MANIFEST.i18n.labels.nl

test('the portal page declares one period filter, 30 days by default', () => {
	assert.equal(PORTAL.pageFilters.length, 1)
	const [period] = PORTAL.pageFilters
	assert.equal(period.key, 'period')
	assert.equal(period.control, 'segmented')
	assert.deepEqual(period.options.map((o) => o.value), ['7', '30', '365'])
	assert.equal(period.default, '30')
	assert.equal(NL[period.label], 'Periode')
})

test('every traffic figure reads the page period and has no picker of its own', () => {
	const kpis = PORTAL.widgets.filter((widget) => widget.type === 'PortalTrafficKpi')
	assert.equal(kpis.length, 4)
	for (const kpi of kpis) {
		assert.equal(kpi.content.days, '@page.period')
		assert.equal(kpi.content.dateRange, undefined)
	}
})

test('a card with days drops its picker and asks for those days', () => {
	assert.match(CARD, /return \{ dateRange: undefined, days: this\.content\.days \}/)
	assert.match(CARD, /params: \{ portal: this\.slug, days: this\.period\.days \}/)
})
