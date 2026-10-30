#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-admin-groups.spec.mjs: the Administrator groups card on a portal's
// page (operate-portals-per-organisation T12): its address, the ids a picker
// selection saves, whether a selection changes anything, and the widget's
// place in the portal's settings.
//
// Usage:
//   node --test tests/portal-admin-groups.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	adminGroupsUrl,
	groupIds,
	groupsChanged,
	limitPortalsPage,
} from '../src/lib/portalAdminGroups.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const MANIFEST = JSON.parse(readFileSync(join(ROOT, 'src', 'manifest.json'), 'utf8'))
const WIDGET = readFileSync(join(ROOT, 'src', 'widgets', 'PortalAdminGroups.vue'), 'utf8')

test('the card reads and writes one portal by its slug', () => {
	assert.equal(
		adminGroupsUrl('zeist en co', (path) => `/index.php${path}`),
		'/index.php/apps/portaliq/api/portals/zeist%20en%20co/admin-groups',
	)
})

test('a selection saves unique ids in picked order', () => {
	assert.deepEqual(
		groupIds([{ id: 'zeist-beheer', label: 'Zeist' }, 'zeist-web', { id: ' zeist-beheer ' }, { label: 'x' }, '', null]),
		['zeist-beheer', 'zeist-web'],
	)
	assert.deepEqual(groupIds(undefined), [])
})

test('the same groups in another order are no change', () => {
	assert.equal(groupsChanged([{ id: 'a' }, { id: 'b' }], ['b', 'a']), false)
	assert.equal(groupsChanged([{ id: 'a' }], [{ id: 'a' }, { id: 'b' }]), true)
	assert.equal(groupsChanged([{ id: 'a' }], []), true)
})

test('the Portals page is limited only when a list of slugs is given', () => {
	const page = { id: 'Portals', config: { schema: 'portal', filter: { kind: 'site' } } }
	assert.equal(limitPortalsPage(page, null), page)
	assert.deepEqual(limitPortalsPage(page, ['zeist']).config.filter, { kind: 'site', slug: ['zeist'] })
	assert.deepEqual(page.config.filter, { kind: 'site' }, 'the bundled page is not changed')
})

test('the card sits in the portal settings, after Administrators', () => {
	const detail = MANIFEST.pages.find((p) => p.id === 'PortalDetail')
	const widget = detail.config.widgets.find((w) => w.id === 'portal-settings-admin-groups')
	assert.equal(widget.type, 'PortalAdminGroups')
	const sections = detail.config.widgets.find((w) => w.id === 'portal-settings').content.sections
	const keys = sections.map((s) => s.key)
	assert.equal(keys.indexOf('admin-groups'), keys.indexOf('editors') + 1)
})

test('the picker is labelled and shows only when the server allows a change', () => {
	assert.match(WIDGET, /<NcSelect[\s\S]*:inputLabel="t\('portaliq', 'Administrator groups'\)"/)
	assert.match(WIDGET, /v-else-if="canChange"/)
})
