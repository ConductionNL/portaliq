#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// page-header-view-on-site.spec.mjs: a portal page's admin header carries a
// "View" button that opens the page on its portal's site in a new tab, as the
// PtPagina, PtPaginaVerkeer and PtPaginaZoekmachines boards draw it.
//
// Usage:
//   node --test tests/page-header-view-on-site.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8')
const manifest = JSON.parse(read('src/manifest.json'))
const config = manifest.pages.find((p) => p.id === 'PageDetail').config

test('the page header links to the page on its site', () => {
	const view = config.headerActions.find((a) => a.id === 'view-page')
	assert.ok(view, 'a view-page header action')
	assert.equal(view.href, '/apps/portaliq/site?route=@object.route&portal=@object.portal')
	assert.equal(view.target, '_blank')
	assert.deepEqual(config.quickActions, ['view-page'])
})

test('its icon is registered and its label translated', () => {
	assert.match(read('src/icons.js'), /\n\tEyeOutline,\n/)
	assert.equal(JSON.parse(read('l10n/nl.json')).translations.View, 'Bekijken')
})
