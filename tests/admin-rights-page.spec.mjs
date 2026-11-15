#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// admin-rights-page.spec.mjs: the Beheerdersrechten page (portaliq/
// PtBeheerdersrechten) lists, per group, its members and the rights it holds.
// It reads the admin-only per-group route, sits under Geavanceerd for
// administrators only, and every row key it shows is one the route answers.
//
// Usage:
//   node --test tests/admin-rights-page.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const NL = JSON.parse(read('l10n/nl.json')).translations
const ROUTES = read('appinfo/routes.php')
const CONTROLLER = read('lib/Controller/ActionSettingsController.php')
const PAGE = MANIFEST.pages.find((page) => page.id === 'AdminRights')

test('the route answers at the admin-only controller', () => {
	assert.match(ROUTES, /'actionSettings#byGroup', 'url' => '\/api\/settings\/action-groups', 'verb' => 'GET'/)
})

test('the page lists the groups with their members and rights', () => {
	assert.ok(PAGE, 'no AdminRights page')
	assert.equal(PAGE.route, '/admin-rights')
	const list = PAGE.config.widgets.find((w) => w.id === 'admin-rights-groups')
	assert.equal(list.type, 'record-list')
	assert.deepEqual(list.content.endpointSource, { url: '/apps/portaliq/api/settings/action-groups', responsePath: 'results' })
	for (const template of [list.content.title, list.content.meta, list.content.trailing]) {
		for (const [, key] of template.matchAll(/\{(\w+)\}/g)) {
			assert.match(CONTROLLER, new RegExp(`'${key}' =>`), `the route answers no "${key}"`)
		}
	}
	assert.ok(PAGE.config.layout.some((cell) => cell.widgetId === 'admin-rights-groups'))
	for (const text of [PAGE.title, list.title, list.content.emptyText, PAGE.config.description]) {
		assert.ok(NL[text], `"${text}" is not in the Dutch catalogue`)
	}
})

test('the menu shows it under Advanced, to administrators only', () => {
	const item = MANIFEST.menu.find((entry) => entry.route === 'AdminRights')
	assert.ok(item, 'no menu item')
	assert.equal(item.section, 'settings')
	assert.deepEqual(item.visibleIf, { 'access.admin': true })
	assert.ok(NL[item.label])
})
