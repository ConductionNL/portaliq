#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-plain.spec.mjs: the plain version of a site page
// (site-honest-without-javascript REQ-SHJ-002), read as files.
//
// - both shells include the one stylesheet partial, so the plain page wears
//   the site's theme;
// - the plain template holds no script element at all;
// - every widget the site registers has a plain rendering or a name, and the
//   names the server copies agree with the site's own tables.
//
// Usage:
//   node --test tests/site-plain.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { widgetLabel } from '../src/lib/widgetLabels.js'
import { wooCategoryLabel } from '../src/site/lib/wooCategories.js'
import { metas } from '../src/site/widgets/index.js'

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8')

const SITE = read('templates/site.php')
const PLAIN = read('templates/site-plain.php')
const FORM = read('templates/parts/site-plain-search-form.php')
const VOCABULARY = read('lib/Service/Cms/PlainVocabulary.php')
const RENDERER = read('lib/Service/Cms/PlainPageRenderer.php')
const GRID = read('src/site/components/WidgetGrid.vue')

/**
 * One `const NAME = [ ... ];` map of the PHP vocabulary, as key to value text.
 *
 * @param {string} name The constant.
 * @return {Map<string, string>} Key to the raw value.
 */
function phpMap(name) {
	const start = VOCABULARY.indexOf(`const ${name} = [`)
	assert.notEqual(start, -1, name)
	const body = VOCABULARY.slice(start, VOCABULARY.indexOf('\n\t];', start))
	const map = new Map()
	for (const match of body.matchAll(/^\t\t'([A-Za-z0-9]+)'\s+=> (.+),$/gm)) {
		map.set(match[1], match[2])
	}
	return map
}

/**
 * A PHP single-quoted string's value.
 *
 * @param {string} literal The literal, quotes included.
 * @return {string} The value.
 */
function unquote(literal) {
	return literal.trim().slice(1, -1).replace(/\\'/g, "'")
}

/**
 * The keys of the site's public widget map: its own entries, the NL Design
 * System widgets and the shared site registry.
 *
 * @return {Set<string>} The keys.
 */
function siteWidgetKeys() {
	const start = GRID.indexOf('const PUBLIC_WIDGETS = {')
	const body = GRID.slice(start, GRID.indexOf('\n}', start))
	const keys = new Set(Object.keys(metas))
	for (const match of body.matchAll(/^\t([A-Za-z][A-Za-z0-9]*):/gm)) {
		keys.add(match[1])
	}
	const shared = read('node_modules/@conduction/nextcloud-vue/src/public/index.js')
	const registry = shared.slice(shared.indexOf('siteBlockRegistry'))
	for (const match of registry.matchAll(/^\t([A-Za-z][A-Za-z0-9]*):/gm)) {
		keys.add(match[1])
	}
	return keys
}

test('both templates include the same stylesheet partial', () => {
	const include = "include __DIR__ . '/parts/site-stylesheets.php';"
	assert.ok(SITE.includes(include), 'site.php includes the partial')
	assert.ok(PLAIN.includes(include), 'site-plain.php includes the partial')
	assert.doesNotMatch(SITE, /<link rel="stylesheet"/, 'site.php links no stylesheet of its own')
})

test('the plain template contains no script element', () => {
	for (const [name, source] of [['site-plain.php', PLAIN], ['site-plain-search-form.php', FORM]]) {
		assert.doesNotMatch(source, /<script/i, name)
		assert.doesNotMatch(source, /emit_script_tag/, name)
		assert.doesNotMatch(source, /\son[a-z]+=/i, `${name}: no inline handler`)
	}
})

test('the site shell carries the notice in a noscript main after the skip link', () => {
	const skip = SITE.indexOf('id="skip-link"')
	const noscript = SITE.indexOf('<noscript>')
	assert.ok(skip !== -1 && noscript > skip, 'noscript follows the skip link')
	assert.match(SITE.slice(noscript), /^<noscript>\s*<main id="pq-main"/)
	assert.match(SITE, /href="<\?php p\(\$plainUrl\); \?>"/)
})

test('every widget key the site registers has a plain rendering or a label', () => {
	const labels = phpMap('WIDGET_LABELS')
	const missing = [...siteWidgetKeys()].filter((key) => !labels.has(key))
	assert.deepEqual(missing, [])
	for (const key of ['markdown', 'nlHeading', 'nlParagraph', 'nlList', 'nlLinkList', 'federatedSearch', 'publicationDetail']) {
		assert.ok(RENDERER.includes(`'${key}'`), `${key} is rendered`)
	}
})

test('the widget names agree with the site tables', () => {
	const registry = Object.fromEntries(
		Object.values(metas).map((meta) => [meta.key, { displayName: meta.label }]),
	)
	for (const [key, literal] of phpMap('WIDGET_LABELS')) {
		assert.equal(unquote(literal), widgetLabel(key, registry), key)
	}
})

test('the Woo categories agree with the site table', () => {
	const categories = phpMap('WOO_CATEGORIES')
	assert.equal(categories.size, 17)
	for (const [code, literal] of categories) {
		const [nl, en] = [...literal.matchAll(/'((?:[^'\\]|\\')*)'/g)].map((m) => m[1].replace(/\\'/g, "'"))
		assert.equal(nl, wooCategoryLabel(code, 'nl'), code)
		assert.equal(en, wooCategoryLabel(code, 'en'), code)
	}
})
