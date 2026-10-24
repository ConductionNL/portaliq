#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-i18n-bundles.spec.mjs: the site ships an empty English bundle, and that
// is only correct while en.json maps every key to itself.
//
// The site's translator falls back to the key, and the keys are the English
// source strings, so an identity en.json in the entry changed no text and cost
// about 28 KB of the site budget. If en.json ever gains a value that differs
// from its key, the bundle has to carry English again: this spec fails first.
//
// Usage:
//   node --test tests/site-i18n-bundles.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const read = (path) => readFileSync(join(ROOT, path), 'utf8')

test('en.json maps every key to itself', () => {
	const en = JSON.parse(read('src/shared/i18n/en.json'))
	const differing = Object.entries(en).filter(([key, value]) => key !== value)
	assert.deepEqual(
		differing,
		[],
		'en.json gained a value that differs from its key; bundle English again in src/shared/i18n/index.js and IdleWarningDialog.vue',
	)
})

test('nl.json translates the same keys as en.json', () => {
	const en = JSON.parse(read('src/shared/i18n/en.json'))
	const nl = JSON.parse(read('src/shared/i18n/nl.json'))
	assert.deepEqual(Object.keys(nl).sort(), Object.keys(en).sort())
})

test('the site translators do not bundle en.json', () => {
	for (const file of [
		'src/shared/i18n/index.js',
		'src/site/components/IdleWarningDialog.vue',
	]) {
		const source = read(file)
		assert.doesNotMatch(source, /import\s+\w+\s+from\s+'[^']*en\.json'/, file)
		assert.match(source, /en: \{\}/, `${file} keeps an empty English bundle`)
	}
})

test('an English or unknown locale reads the key itself', async () => {
	// index.js imports nl.json, which node does not load without an import
	// attribute, so the module is evaluated with the JSON inlined.
	const source = read('src/shared/i18n/index.js').replace(
		"import nl from './nl.json'",
		`const nl = ${read('src/shared/i18n/nl.json')}`,
	)
	const { createTranslator } = await import(
		'data:text/javascript;base64,' + Buffer.from(source).toString('base64')
	)
	const en = JSON.parse(read('src/shared/i18n/en.json'))
	const nl = JSON.parse(read('src/shared/i18n/nl.json'))
	const tEn = createTranslator('en')
	const tXx = createTranslator('xx')
	const tNl = createTranslator('nl')
	for (const key of Object.keys(en)) {
		assert.equal(tEn(key), en[key])
		assert.equal(tXx(key), en[key])
		assert.equal(tNl(key), nl[key] || key)
	}
	assert.equal(
		tEn('Logged in as {subjectRef}', { subjectRef: 'x' }),
		'Logged in as x',
	)
})
