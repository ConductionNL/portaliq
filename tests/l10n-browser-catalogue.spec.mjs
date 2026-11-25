#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
//
// l10n-browser-catalogue.spec.mjs: the generated browser catalogue for the
// source locale (en) carries only the entries that differ from their key;
// every other locale, and l10n/en.json itself, stays complete.
//
// @spec PERF-HANDOFF.md (all apps: drop identity entries from the English catalogue at build time)
//
// Usage:
//   node --test tests/l10n-browser-catalogue.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const require = createRequire(import.meta.url)
const { browserEntries, renderJs, SOURCE_LOCALE } = require('../scripts/build-l10n-js.js')

test('the source locale drops entries equal to their key', () => {
	const out = browserEntries(SOURCE_LOCALE, {
		Save: 'Save',
		'Import failed:': 'Import failed: ',
		'reload now': 'Reload now',
	})
	assert.deepEqual(out, {
		'Import failed:': 'Import failed: ',
		'reload now': 'Reload now',
	})
})

test('plural entries are arrays and are always kept', () => {
	const plural = { '_%n card_::_%n cards_': ['%n card', '%n cards'] }
	assert.deepEqual(browserEntries(SOURCE_LOCALE, plural), plural)
})

test('any other locale keeps every entry, identity ones included', () => {
	const nl = { Save: 'Opslaan', OK: 'OK' }
	assert.deepEqual(browserEntries('nl', nl), nl)
	const enUs = { Save: 'Save' }
	assert.deepEqual(browserEntries('en_US', enUs), enUs)
})

test('an all-identity source catalogue renders as an empty registration', () => {
	const entries = browserEntries(SOURCE_LOCALE, { A: 'A', B: 'B' })
	assert.deepEqual(entries, {})
	const js = renderJs('portaliq', { translations: entries })
	assert.match(js, /^OC\.L10N\.register\(\n {4}"portaliq",/)
	assert.match(js, /"nplurals=2; plural=\(n != 1\);"/)
})

test('the committed l10n/en.js holds exactly the non-identity entries of l10n/en.json', () => {
	const json = JSON.parse(readFileSync(join(ROOT, 'l10n', 'en.json'), 'utf8'))
	const js = readFileSync(join(ROOT, 'l10n', 'en.js'), 'utf8')
	const body = js.match(/OC\.L10N\.register\(\s*"[^"]+"\s*,\s*(\{.*\})\s*,\s*"nplurals/s)
	assert.notEqual(body, null)
	const served = JSON.parse(body[1])
	const expected = Object.fromEntries(
		Object.entries(json.translations).filter(([key, value]) => key !== value),
	)
	assert.deepEqual(served, expected)
	assert.ok(Object.keys(json.translations).length > Object.keys(served).length)
})
