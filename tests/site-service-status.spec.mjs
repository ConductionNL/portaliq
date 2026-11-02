#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-service-status.spec.mjs: the Zuiddrecht example site has a status page
// over opencatalogi's serviceStatus kind (board OcStatus), linked from the footer.
//
// Usage:
//   node --test tests/site-service-status.spec.mjs
//
// @spec openspec/specs/example-site/spec.md

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const site = JSON.parse(readFileSync(new URL('../lib/Settings/sites/zuiddrecht.json', import.meta.url), 'utf8'))
const page = site.pages.find((p) => p.route === '/status')

test('the site has a published status page that needs opencatalogi', () => {
	assert.ok(page, 'no /status page')
	assert.equal(page.status, 'published')
	assert.equal(page.requiresApp, 'opencatalogi')
})

test('the page shows opencatalogi serviceStatus as a table', () => {
	const table = page.body.widgets.find((w) => w.widgetKey === 'nlPublicTable')
	assert.ok(table, 'no public table')
	assert.deepEqual(table.props.source, { app: 'opencatalogi', kind: 'serviceStatus' })
	assert.deepEqual(table.props.columns.map((c) => c.key), ['service', 'message', 'state'])
	assert.ok(table.props.emptyLabel, 'the table needs an empty label')
})

test('the footer links to it', () => {
	const about = site.menus.find((m) => m.title === 'Over deze website')
	assert.ok(about.items.some((item) => item.link === '/status'))
})
