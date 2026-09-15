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
// The two formatters are @conduction/nextcloud-vue built-ins since 3.2.0, so
// their names are checked against the installed library, not a local copy.
//
// @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-004-an-admin-reads-the-connections-on-an-integrations-page

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	createConnectionHandlers,
	INTEGRIQ_CONNECTIONS_PATH,
} from '../src/lib/connectionRegistry.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const read = (...parts) => readFileSync(join(ROOT, ...parts), 'utf8')
const manifest = JSON.parse(read('src', 'manifest.json'))
const page = manifest.pages.find((p) => p.id === 'Integrations')
const menu = manifest.menu.find((m) => m.id === 'IntegrationsMenu')

/**
 * The formatter names the installed @conduction/nextcloud-vue registers as
 * built-ins, read from its BUILT_IN_FORMATTERS map.
 *
 * @return {Set<string>} The registry keys.
 */
function libraryBuiltInFormatters() {
	const source = read('node_modules', '@conduction', 'nextcloud-vue', 'src', 'utils', 'builtInFormatters.js')
	const block = source.slice(source.indexOf('export const BUILT_IN_FORMATTERS'))
	const body = block.slice(block.indexOf('{') + 1, block.indexOf('}'))
	return new Set([...body.matchAll(/^\t'?([\w-]+)'?:/gm)].map((match) => match[1]))
}

describe('connection strings', () => {
	it('ships an English and a Dutch catalogue entry for every label the page declares', () => {
		const en = JSON.parse(read('l10n', 'en.json')).translations
		const nl = JSON.parse(read('l10n', 'nl.json')).translations
		const labels = [
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

	it('names only formatters the library ships and handlers that exist, and wires the handler into the app', () => {
		const builtIns = libraryBuiltInFormatters()
		const handlers = createConnectionHandlers({ generateUrl: (p) => p, assign: () => {} })

		assert.ok(builtIns.has('date'), 'the built-in formatter map was read')
		const named = page.config.columns.filter((c) => c.formatter).map((c) => c.formatter)
		assert.deepEqual(named.sort(), ['connectionSettingsLabel', 'connectionStatus'])
		for (const formatter of named) {
			assert.ok(builtIns.has(formatter), `@conduction/nextcloud-vue ships ${formatter}`)
		}
		for (const action of page.config.headerActions) {
			assert.equal(typeof handlers[action.handler], 'function', action.handler)
		}

		assert.match(read('src', 'customComponents.js'), /^\t\.\.\.createConnectionHandlers\(\{$/m)
	})

	// The library labels all seven statuses. A copy of the formatter passed to
	// CnAppRoot would win over the built-in and could predate `disabled`.
	it('lets the library label the statuses, disabled included', () => {
		const builtIn = read('node_modules', '@conduction', 'nextcloud-vue', 'src', 'utils', 'builtInFormatters.js')
		assert.match(builtIn, /^\tdisabled: 'Switched off',$/m)
		assert.ok(!read('src', 'App.vue').includes(':formatters='), 'App.vue passes no formatters that would shadow the built-ins')
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
