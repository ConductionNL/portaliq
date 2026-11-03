#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-notices-tab-as-a-list.spec.mjs: a portal's Mededelingen tab is the
// notices list as board PtMededelingenActies draws it (quick filters with
// counts, Filter, search, selection, a level tile and a "where it shows" line,
// derived status pills, six rows a page), and the portal header carries the
// board's meta line and its Website bekijken and Nieuwe mededeling buttons.
//
// Usage:
//   node --test tests/portal-notices-tab-as-a-list.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const manifest = JSON.parse(readFileSync(new URL('../src/manifest.json', import.meta.url), 'utf8'))
const nl = JSON.parse(readFileSync(new URL('../l10n/nl.json', import.meta.url), 'utf8')).translations
const portal = manifest.pages.find((p) => p.id === 'PortalDetail').config
const notices = portal.widgets.find((w) => w.id === 'portal-notices')
const column = (key) => notices.content.columns.find((c) => c.key === key)

test('the notices tab is a list page\'s list of this portal\'s notices', () => {
	assert.equal(notices.type, 'index-list')
	assert.deepEqual(notices.content.filter, { portal: '@object.slug' })
	assert.equal(notices.content.selectable, true)
	assert.equal(notices.content.filterMenu, true)
	assert.equal(notices.content.pagination.limit, 6)
	assert.equal(notices.content.rowRoute, 'NoticeDetail')
	assert.deepEqual(notices.content.quickFilters.map((q) => q.label), ['Alles', 'Nu zichtbaar', 'Komend', 'Concept'])
	assert.ok(notices.content.quickFilters.every((q) => q.showCount === true))
})

test('a row leads with its level and names where it shows', () => {
	const icons = readFileSync(new URL('../src/icons.js', import.meta.url), 'utf8')
	for (const name of Object.values(notices.content.rowIcon.icons)) {
		assert.match(icons, new RegExp(`\\t${name},`), `${name} is registered`)
	}
	assert.equal(notices.content.rowIcon.field, 'level')
	assert.equal(notices.content.rowIcon.tones.warning, 'warning')
	assert.equal(column('message').secondary, '{level} · shows on {surfaces}')
	assert.equal(nl['{level} · shows on {surfaces}'], '{level} · toont op {surfaces}')
	assert.deepEqual(Object.keys(column('message').secondaryLabels), ['level', 'surfaces'])
})

test('the status reads visible now while the window runs and expired after it', () => {
	const status = column('status')
	assert.deepEqual(status.valueWhen.map((r) => r.value), ['visible', 'expired'])
	assert.equal(status.widgetProps.colorMap.visible, 'success')
	assert.equal(status.widgetProps.colorMap.published, 'info')
	assert.equal(nl[status.enumLabels.visible], 'Nu zichtbaar')
})

test('only the board\'s sortable column sorts, and no column filters in its head', () => {
	assert.deepEqual(notices.content.columns.filter((c) => c.sortable).map((c) => c.key), ['startsAt'])
	assert.ok(notices.content.columns.every((c) => c.filterable === false))
})

test('the header names the domain, theme and pages, and offers the website and a new notice', () => {
	assert.match(portal.headerMeta, /\{count:pages\}/)
	assert.deepEqual(portal.headerMetaCounts.pages.filter, { portal: '@object.slug' })
	assert.deepEqual(portal.quickActions, ['view-website'])
	for (const stage of ['published', 'draft']) {
		assert.equal(portal.primaryActionByStage[stage].id, 'new-notice')
		assert.deepEqual(portal.primaryActionByStage[stage].tabs, ['portal-notices'])
	}
	const ids = portal.headerActions.map((a) => a.id)
	assert.deepEqual(ids, ['view-website', 'new-notice'])
})

test('every new label reads in Dutch', () => {
	for (const key of ['Announcement', 'Information', 'Warning', 'Signed-in portal', 'Visible now', 'only published and within the period visible', portal.headerMeta]) {
		assert.ok(nl[key], `nl has ${key}`)
	}
})
