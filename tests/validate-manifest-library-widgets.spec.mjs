#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// validate-manifest-library-widgets.spec.mjs: the manifest's renderer lint
// accepts every widget type nextcloud-vue registers for a dashboard, read off
// the library source, and still refuses a type nobody registers
// (case-actions-row-inputs-and-conditions T07).
//
// Usage:
//   node --test tests/validate-manifest-library-widgets.spec.mjs

import assert from 'node:assert/strict'
import { mkdirSync, mkdtempSync, writeFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { test } from 'node:test'

const require = createRequire(import.meta.url)
const { libraryWidgetKeys, rendererContractLint } = require('./validate-manifest.js')

/**
 * A fake nextcloud-vue package with one inline and one imported registration.
 *
 * @return {string} The package directory.
 */
function fakeLibrary() {
	const root = mkdtempSync(join(tmpdir(), 'cn-lib-'))
	const grid = join(root, 'src', 'components', 'CnWidgetGrid')
	const table = join(root, 'src', 'components', 'CnTable')
	mkdirSync(grid, { recursive: true })
	mkdirSync(table, { recursive: true })
	writeFileSync(join(grid, 'registerDashboardWidgets.js'), [
		"import { registerDashboardWidget } from './dashboardWidgetRegistry.js'",
		"import '../CnTable/dashboardRegistration.js'",
		"import '../CnMissing/index.js'",
		"registerDashboardWidget('inline-chart', {})",
		'',
	].join('\n'))
	writeFileSync(join(table, 'dashboardRegistration.js'), "registerDashboardWidget('imported-table', {})\n")
	return root
}

/**
 * A dashboard page with one placed widget of the given type.
 *
 * @param {string} type The widget type.
 * @return {object} A manifest.
 */
function manifestWith(type) {
	return {
		pages: [{
			id: 'Board',
			type: 'dashboard',
			config: {
				widgets: [{ id: 'w1', type, title: 'W' }],
				layout: [{ id: 1, widgetId: 'w1', gridX: 0, gridY: 0, gridWidth: 4, gridHeight: 2 }],
			},
		}],
	}
}

test('reads the inline and the imported registrations', () => {
	const keys = libraryWidgetKeys(fakeLibrary())
	assert.deepEqual(keys.sort(), ['imported-table', 'inline-chart'])
})

test('adds no keys when the library is not installed', () => {
	assert.deepEqual(libraryWidgetKeys(join(tmpdir(), 'no-such-library')), [])
})

test('the installed library registers object-table', () => {
	assert.ok(libraryWidgetKeys().includes('object-table'))
})

test('an object-table dashboard widget passes the renderer lint', () => {
	assert.deepEqual(rendererContractLint(manifestWith('object-table')), [])
})

test('a type nobody registers still fails the renderer lint', () => {
	const errors = rendererContractLint(manifestWith('no-such-widget'))
	assert.equal(errors.length, 1)
	assert.match(errors[0], /"no-such-widget", which CnDashboardPage cannot mount/)
})
