#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-software-catalogue.spec.mjs: the Zuiddrecht example site places
// stackiq's public software catalogue (board SkPubliek), and only where stackiq
// is installed.
//
// Usage:
//   node --test tests/site-software-catalogue.spec.mjs
//
// @spec openspec/changes/site-software-catalogue-page/specs/example-site/spec.md

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const site = JSON.parse(readFileSync(new URL('../lib/Settings/sites/zuiddrecht.json', import.meta.url), 'utf8'))
const page = site.pages.find((p) => p.route === '/softwarecatalogus')

test('the site has a Softwarecatalogus page that needs stackiq', () => {
	assert.ok(page, 'no /softwarecatalogus page')
	assert.equal(page.status, 'published')
	assert.equal(page.requiresApp, 'stackiq')
})

test('the page places the public records block on stackiq softwareCatalogue', () => {
	const block = page.body.widgets.find((w) => w.widgetKey === 'publicRecords')
	assert.ok(block, 'no publicRecords block')
	assert.equal(block.props.app, 'stackiq')
	assert.equal(block.props.list, 'softwareCatalogue')
	assert.ok(block.props.searchLabel, 'the list needs a search label')
})
