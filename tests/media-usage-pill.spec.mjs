#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// media-usage-pill.spec.mjs: the media library shows on how many records an
// item is used and has the pill "Nergens gebruikt" (portaliq/PtMedia). Both
// read the item's `usedOn`, which MediaUsageJob keeps current; the job must
// be registered, or the pill filters on a field nothing fills.
//
// Usage:
//   node --test tests/media-usage-pill.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const MEDIA = JSON.parse(read('lib/Settings/portaliq_register.json')).components.schemas.media.properties
const NL = JSON.parse(read('l10n/nl.json')).translations
const INFO = read('appinfo/info.xml')
const CONFIG = MANIFEST.pages.find((page) => page.id === 'Media').config

test('"Nergens gebruikt" filters on a count of zero', () => {
	const pill = CONFIG.quickFilters.find((entry) => entry.label === 'Nergens gebruikt')
	assert.ok(pill, 'no pill')
	assert.deepEqual(pill.filter, { usedOn: 0 })
	assert.equal(pill.showCount, true)
	assert.equal(MEDIA.usedOn.type, 'integer')
	assert.ok(NL[pill.label])
})

test('the list shows on how many records an item is used', () => {
	const column = CONFIG.columns.find((entry) => (entry.key || entry) === 'usedOn')
	assert.ok(column, 'no usedOn column')
	assert.ok(NL[column.label], `"${column.label}" is not in the Dutch catalogue`)
})

test('the job that fills the count is registered', () => {
	assert.match(INFO, /<job>OCA\\Portaliq\\BackgroundJob\\MediaUsageJob<\/job>/)
})
