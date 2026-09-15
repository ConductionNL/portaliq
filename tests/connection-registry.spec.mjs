#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// connection-registry.spec.mjs — the Integrations page over integriq's
// connection registry (adopt-connection-registry, hydra connection-registry D8
// and D9).
//
// Usage:
//   node --test tests/connection-registry.spec.mjs
//
// The page is declared in JSON and resolves two formatters, one handler and
// one icon by NAME. A misspelled name renders a raw enum, no glyph, or an Add
// integration that does nothing, and none of them logs a thing. So this spec
// reads the real manifest and checks every name against what has to answer it.
//
// @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-004-an-admin-reads-the-connections-on-an-integrations-page

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	CONNECTION_STATUS_LABELS,
	createConnectionFormatters,
	createConnectionHandlers,
	INTEGRIQ_CONNECTIONS_PATH,
} from '../src/lib/connectionRegistry.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const read = (...parts) => readFileSync(join(ROOT, ...parts), 'utf8')
const manifest = JSON.parse(read('src', 'manifest.json'))
const page = manifest.pages.find((p) => p.id === 'Integrations')
const menu = manifest.menu.find((m) => m.id === 'IntegrationsMenu')

/**
 * A translator that marks what it translated, so a missing call shows.
 *
 * @param {string} source The English source string.
 * @return {string} The marked string.
 */
const translate = (source) => `t:${source}`

describe('connection formatters', () => {
	const formatters = createConnectionFormatters(translate)

	it('labels all six statuses, limited included', () => {
		assert.deepEqual(
			Object.keys(CONNECTION_STATUS_LABELS).sort(),
			['configured', 'error', 'limited', 'simulated', 'unavailable', 'unconfigured'],
		)
		assert.equal(formatters.connectionStatus('configured'), 't:Configured')
		assert.equal(formatters.connectionStatus('limited'), 't:Limited')
		assert.equal(formatters.connectionStatus('unconfigured'), 't:Not configured')
		assert.equal(formatters.connectionStatus('simulated'), 't:Simulated')
		assert.equal(formatters.connectionStatus('unavailable'), 't:Not available')
		assert.equal(formatters.connectionStatus('error'), 't:Error')
	})

	// A connection that works in part is neither working nor broken, so it must
	// not borrow either label.
	it('keeps limited apart from configured, not available and error', () => {
		const limited = formatters.connectionStatus('limited')
		assert.notEqual(limited, formatters.connectionStatus('configured'))
		assert.notEqual(limited, formatters.connectionStatus('unavailable'))
		assert.notEqual(limited, formatters.connectionStatus('error'))
	})

	it('renders an unknown status as itself and a missing one as empty', () => {
		assert.equal(formatters.connectionStatus('degraded'), 'degraded')
		assert.equal(formatters.connectionStatus('toString'), 'toString')
		assert.equal(formatters.connectionStatus(null), '')
		assert.equal(formatters.connectionStatus(undefined), '')
	})

	it('offers Open settings only when the row has a settings link', () => {
		assert.equal(formatters.connectionSettingsLabel('/settings/admin/portaliq'), 't:Open settings')
		assert.equal(formatters.connectionSettingsLabel(''), '')
		assert.equal(formatters.connectionSettingsLabel(undefined), '')
		assert.equal(formatters.connectionSettingsLabel(null), '')
	})

	it('ships an English and a Dutch catalogue entry for every label the page shows', () => {
		const en = JSON.parse(read('l10n', 'en.json')).translations
		const nl = JSON.parse(read('l10n', 'nl.json')).translations
		const labels = [
			...Object.values(CONNECTION_STATUS_LABELS),
			'Open settings',
			page.title,
			menu.label,
			page.config.folderSidebar.allLabel,
			...page.config.headerActions.map((a) => a.label),
			...page.config.columns.map((c) => c.label),
		]
		for (const label of labels) {
			assert.equal(en[label], label, `en: ${label}`)
			assert.ok(nl[label], `nl: ${label}`)
		}
		assert.equal(nl.Limited, 'Beperkt')
		// The browser reads the .js catalogue, never the .json one.
		assert.match(read('l10n', 'nl.js'), /"Limited": "Beperkt"/)
	})
})

describe('Add integration handler', () => {
	it('opens integriq on the link dialog, preset to portaliq', () => {
		const opened = []
		const handlers = createConnectionHandlers({
			generateUrl: (p) => `/index.php${p}`,
			assign: (url) => opened.push(url),
		})

		handlers.openIntegriqConnections()

		assert.equal(INTEGRIQ_CONNECTIONS_PATH, '/apps/integriq/connections?app=portaliq&link=1')
		assert.deepEqual(opened, ['/index.php/apps/integriq/connections?app=portaliq&link=1'])
	})
})

describe('the Integrations page declaration', () => {
	it('lists integriq app_connection rows, admin only, and requires integriq', () => {
		assert.equal(page.type, 'index')
		assert.equal(page.route, '/settings/integrations')
		assert.equal(page.permission, 'admin')
		assert.deepEqual(page.requiresApp, { id: 'integriq', name: 'Integriq' })
		assert.equal(page.config.register, 'integriq')
		assert.equal(page.config.schema, 'app_connection')
		assert.deepEqual(page.config.defaultSort, { field: 'order', direction: 'asc' })
	})

	// A row nothing declared has nothing to check (connection-registry D9).
	it('offers no generic Add button', () => {
		assert.equal(page.config.showAdd, false)
	})

	// THE PRESET. integriq's schema holds every app's rows. Without the query
	// the page lists them all as though they were this app's.
	it('scopes the rows to portaliq through the menu preset, in the gear', () => {
		assert.equal(menu.route, page.id)
		assert.deepEqual(menu.query, { app: 'portaliq' })
		assert.equal(menu.section, 'settings')
		assert.equal(menu.permission, 'admin')
		assert.deepEqual(menu.visibleIf, { appInstalled: 'integriq' })
	})

	it('names only formatters and handlers that exist, and wires both into the app', () => {
		const formatters = createConnectionFormatters(translate)
		const handlers = createConnectionHandlers({ generateUrl: (p) => p, assign: () => {} })

		for (const column of page.config.columns.filter((c) => c.formatter)) {
			assert.equal(typeof formatters[column.formatter], 'function', column.formatter)
		}
		for (const action of page.config.headerActions) {
			assert.equal(typeof handlers[action.handler], 'function', action.handler)
		}

		const app = read('src', 'App.vue')
		assert.ok(app.includes(':formatters="formatters"'), 'App.vue passes formatters to CnAppRoot')
		assert.ok(app.includes('formatters: createConnectionFormatters('), 'App.vue builds the formatters')
		assert.match(read('src', 'customComponents.js'), /^\t\.\.\.createConnectionHandlers\(\{$/m)
	})

	it('names an icon src/icons.js registers', () => {
		const icons = read('src', 'icons.js')
		for (const icon of [menu.icon, ...page.config.headerActions.map((a) => a.icon)]) {
			assert.ok(icons.includes(`\n\t${icon},`), `icons.js registers ${icon}`)
		}
	})

	it('keeps its id and route apart from every other page', () => {
		assert.equal(manifest.pages.filter((p) => p.id === page.id).length, 1)
		assert.equal(manifest.pages.filter((p) => p.route === page.route).length, 1)
		assert.equal(manifest.menu.filter((m) => m.id === menu.id).length, 1)
	})
})
