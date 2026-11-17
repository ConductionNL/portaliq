#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-plain.spec.mjs: the plain version of a site page, for a browser
// without JavaScript (site-honest-without-javascript REQ-SHJ-002).
//
// The templates and the PHP tables cannot run outside Nextcloud, so this reads
// their source: both site templates include one stylesheet partial, the plain
// template holds no script element, every widget the site registers renders
// on the plain page or has a label for its "needs JavaScript" sentence, and the
// two tables the server copies from the browser code still agree with it.
//
// Usage:
//   node --test tests/site-plain.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { VALUE_LISTS } from '../src/site/config/valueLists.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const read = (...parts) => readFileSync(join(ROOT, ...parts), 'utf8')

const site = read('templates', 'site.php')
const plain = read('templates', 'site-plain.php')
const renderer = read('lib', 'Service', 'Cms', 'PlainPageRenderer.php')
const labelsPhp = read('lib', 'Service', 'Cms', 'PlainWidgetLabels.php')
const categoriesPhp = read('lib', 'Service', 'Cms', 'PlainCategoryNames.php')

const PARTIAL = "<?php include __DIR__ . '/parts/site-stylesheets.php'; ?>"

/**
 * The text between an opening line and the first line that closes it.
 *
 * @param {string} source The file.
 * @param {string} open The opening text.
 * @param {string} close The closing text.
 * @return {string} The block.
 */
function block(source, open, close) {
	const start = source.indexOf(open)
	assert.notEqual(start, -1, `found ${open}`)
	const end = source.indexOf(close, start)
	assert.notEqual(end, -1, `found the end of ${open}`)
	return source.slice(start + open.length, end)
}

/**
 * A PHP string literal's value.
 *
 * @param {string} literal The literal, quotes included.
 * @return {string} The value.
 */
function phpString(literal) {
	return literal.slice(1, -1).replace(/\\(['\\])/g, '$1')
}

/**
 * The keys of a JS object literal written one `key: value,` per line.
 *
 * @param {string} body The literal's body.
 * @return {Array<string>} The keys.
 */
function objectKeys(body) {
	return [...body.matchAll(/^\t(\w+):/gm)].map((match) => match[1])
}

test('both templates include the same stylesheet partial', () => {
	assert.equal(site.split(PARTIAL).length - 1, 1, 'site.php includes it once')
	assert.equal(plain.split(PARTIAL).length - 1, 1, 'site-plain.php includes it once')
	for (const template of [site, plain]) {
		assert.doesNotMatch(template, /rel="stylesheet"/, 'no stylesheet of its own')
		assert.doesNotMatch(template, /themeTokenCss/, 'no theme style of its own')
	}
	const partial = read('templates', 'parts', 'site-stylesheets.php')
	assert.match(partial, /<link rel="stylesheet" href="<\?php p\(\$href\); \?>">/)
	assert.match(partial, /print_unescaped\(\$themeTokenCss\)/)
})

test('the plain template contains no script element', () => {
	assert.doesNotMatch(plain, /<script/i)
	assert.doesNotMatch(plain, /emit_script_tag|addScript/)
	assert.doesNotMatch(read('templates', 'parts', 'site-stylesheets.php'), /<script|emit_script_tag/i)
})

test('every widget key the site registers has a plain rendering or a label', () => {
	const grid = read('src', 'site', 'components', 'WidgetGrid.vue')
	const gridKeys = objectKeys(block(grid, 'const PUBLIC_WIDGETS = {', '\n}'))
	const metaKeys = objectKeys(block(read('src', 'site', 'widgets', 'index.js'), 'export const metas = {', '\n}'))
	assert.ok(gridKeys.includes('federatedSearch'), 'read the grid map')
	assert.ok(metaKeys.includes('nlHeading'), 'read the widget registry')

	const rendered = [
		...[...block(renderer, 'public const TEXT_WIDGETS = [', '];').matchAll(/'(\w+)' =>/g)].map((m) => m[1]),
		...[...block(renderer, 'public const PUBLICATION_WIDGETS = [', '];').matchAll(/'(\w+)'/g)].map((m) => m[1]),
	]
	const labelled =
		block(labelsPhp, 'public const LABELS = [', '];')
		+ block(labelsPhp, 'public const SITE_ONLY_LABELS = [', '];')
	const labelledKeys = [...labelled.matchAll(/'(\w+)'\s*=>/g)].map((m) => m[1])

	for (const key of [...new Set([...gridKeys, ...metaKeys])]) {
		assert.ok(rendered.includes(key) || labelledKeys.includes(key), `${key} renders on the plain page or has a label`)
	}
	for (const key of ['markdown', 'nlHeading', 'nlParagraph', 'nlList', 'nlLinkList', 'federatedSearch', 'publicationDetail']) {
		assert.ok(rendered.includes(key), `${key} renders on the plain page`)
	}
})

test('the server copy of the widget labels agrees with src/lib/widgetLabels.js', () => {
	const js = block(read('src', 'lib', 'widgetLabels.js'), 'const PUBLIC_LABELS = {', '\n}')
	const browser = Object.fromEntries([...js.matchAll(/^\t(\w+): '([^']*)',/gm)].map((m) => [m[1], m[2]]))
	const server = Object.fromEntries(
		[...block(labelsPhp, 'public const LABELS = [', '];').matchAll(/'(\w+)'\s*=> ('(?:[^'\\]|\\.)*'),/g)].map((m) => [m[1], phpString(m[2])]),
	)
	assert.ok(Object.keys(browser).length > 50, 'read the browser table')
	assert.deepEqual(server, browser)
})

test('the server copy of the information categories agrees with src/site/config/valueLists.js', () => {
	const server = Object.fromEntries(
		[...block(categoriesPhp, 'public const WOO_CATEGORIES = [', '];').matchAll(/'(infocat\d+)' => \['nl' => ('(?:[^'\\]|\\.)*'), 'en' => ('(?:[^'\\]|\\.)*')\],/g)]
			.map((m) => [m[1], { nl: phpString(m[2]), en: phpString(m[3]) }]),
	)
	assert.deepEqual(server, VALUE_LISTS.wooCategory)
})
