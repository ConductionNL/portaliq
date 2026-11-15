#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// plain-search-board.spec.mjs: the plain search reads like board Zoeken. A
// filter is a 22px box beside its words and its count, the words and the
// count wrapping as one; the filter and result headings stand at 24px and
// 26px; a result card has only its own 20px and 24px of room, its line
// drawn from the card line token.
//
// Usage:
//   node --test tests/site-look/plain-search-board.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

/**
 * A source file with its comments removed and its whitespace collapsed.
 *
 * @param {string} path The file, from the app root.
 * @return {string} The text.
 */
function read(path) {
	return readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8')
		.replace(/\/\*[\s\S]*?\*\//g, '')
		.replace(/<!--[\s\S]*?-->/g, '')
		.replace(/\s+/g, ' ')
}

const css = read('css/site-theme.css')
const block = read('src/site/components/FederatedSearchBlock.vue')
const PLAIN = '.pq-site .pq-search--plain'

/**
 * The declarations of every rule whose selector is `selector`.
 *
 * @param {string} selector The selector.
 * @return {string} Its declarations.
 */
function rule(selector) {
	const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
	const matches = [...css.matchAll(new RegExp(`(?:^|\\}) ?${escaped} \\{([^}]*)\\}`, 'g'))]
	assert.ok(matches.length > 0, `${selector} has a rule`)
	return matches.map((match) => match[1]).join(';')
}

test('a filter is a 22px box in the primary colour beside 17px words', () => {
	assert.match(rule(`${PLAIN} .pq-search__facet-list .utrecht-checkbox`), /inline-size: 1\.375rem; block-size: 1\.375rem;.*accent-color: var\(--nldesign-color-primary/)
	assert.match(rule(`${PLAIN} .utrecht-form-label--checkbox`), /display: flex;.*gap: 0\.625rem; font-size: 1\.0625rem;/)
	assert.match(rule(`${PLAIN} .pq-search__facet-list`), /flex-direction: column; gap: 0\.625rem;/)
})

test('the words of a filter and its count wrap as one', () => {
	assert.match(
		block,
		/class="utrecht-checkbox"[^>]*\/> <span >\{\{ bucket\.label \}\} <span class="pq-search__facet-count" >\(\{\{ bucket\.count \}\}\)<\/span ><\/span >/,
	)
})

test('the filter and result headings stand at 24px and 26px', () => {
	assert.match(rule(`${PLAIN} .pq-search__facets h2`), /font-size: 1\.5rem;/)
	assert.match(rule(`${PLAIN} .pq-search__count`), /font-size: 1\.625rem;/)
})

test('a result card has only its own room and the card line', () => {
	assert.match(rule(`${PLAIN} .pq-search__result .ac-card__content`), /gap: 0\.375rem; padding: 0;/)
	assert.match(rule(`${PLAIN} .pq-search__result.ac-card`), /border: 1px solid var\( --nldesign-website-card-border-color,/)
})
