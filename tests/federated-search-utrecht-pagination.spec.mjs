#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// federated-search-utrecht-pagination.spec.mjs: the public search's page row
// is NL Design System's Utrecht pagination, not Amsterdam's
// (site-nlds-widget-palette T8b).
//
// WHAT THIS CATCHES. The row is drawn by class names only: drop the
// stylesheet import, keep one `ams-pagination` class, or let the plain theme
// still aim at the old names, and the page renders a row of bare links with no
// visible current page. None of that throws; only reading the sources does.
// The test also pins the parts the swap must NOT touch: the row stays a nav of
// links with `aria-current`, and the e2e testids stay put.
//
// Usage:
//   node --test tests/federated-search-utrecht-pagination.spec.mjs
//
// @spec openspec/changes/site-nlds-widget-palette/tasks.md#T8b

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const block = readFileSync(
	new URL('../src/site/components/FederatedSearchBlock.vue', import.meta.url),
	'utf8',
)
const theme = readFileSync(new URL('../css/site-theme.css', import.meta.url), 'utf8')

/** The block's template, where the classes are written. */
const template = block.slice(block.indexOf('<template>'), block.indexOf('<script'))

/** The pagination nav, from its opening tag to its close. */
const nav = template.slice(
	template.lastIndexOf('<nav', template.indexOf('federated-search-pagination')),
	template.indexOf('</nav>', template.indexOf('federated-search-pagination')),
)

test('no Amsterdam pagination class is left in the block or the theme', () => {
	assert.doesNotMatch(block, /ams-pagination/)
	assert.doesNotMatch(theme, /ams-pagination/)
})

test('the row is a Utrecht pagination nav with page links and a next link', () => {
	assert.match(nav, /class="utrecht-pagination pq-search__pagination"/)
	assert.match(nav, /class="utrecht-pagination__page-link"/)
	assert.match(nav, /'utrecht-pagination__page-link--current':\s*entry === page/)
	assert.match(
		nav,
		/class="utrecht-pagination__relative-link utrecht-pagination__relative-link--next"/,
	)
})

test('the Utrecht stylesheet is imported, so the classes are styled', () => {
	assert.match(block, /import '@utrecht\/pagination-css\/dist\/index\.css'/)
})

test('the row stays links with aria-current and keeps its e2e testids', () => {
	assert.doesNotMatch(nav, /<button/)
	assert.match(nav, /:aria-current="entry === page \? 'page' : undefined"/)
	assert.match(nav, /data-testid="federated-search-pagination"/)
	assert.match(nav, /:data-testid="`federated-search-page-\$\{entry\}`"/)
	assert.match(nav, /data-testid="federated-search-page-next"/)
})

test('the plain theme styles the new class names, current page included', () => {
	assert.match(theme, /\.pq-search--plain \.utrecht-pagination__page-link,/)
	assert.match(theme, /\.pq-search--plain \.utrecht-pagination__relative-link \{/)
	assert.match(
		theme,
		/\.pq-search--plain \.utrecht-pagination__page-link--current \{/,
	)
})

test("the block's own fallbacks are token-first, never a bare colour", () => {
	const style = block.slice(block.indexOf('<style'))
	const rules = style.slice(
		style.indexOf('.pq-search__page-list'),
		style.indexOf('.pq-search__page-gap'),
	)
	assert.match(rules, /var\(--utrecht-pagination-page-link-color,/)
	assert.doesNotMatch(rules, /#[0-9a-f]{3,8}\b|\brgba?\(/i)
})
