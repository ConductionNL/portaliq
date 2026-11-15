#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-editor-as-drawn.spec.mjs: the editing bar and the menu panel on the
// portal draw as the Editor and EditorMenu boards do. The bar is one tinted
// row with a 2px deep-primary edge and outlined 40px buttons; a menu item is a
// 57px row (40px name field, 8px above and below, a hairline under it).
//
// Usage:
//   node --test tests/site-editor-as-drawn.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8')
const style = (source) => source.slice(source.indexOf('<style scoped>'))
function rule (css, selector) {
	const at = css.indexOf(`\n${selector} {`)
	assert.ok(at >= 0, `${selector} has a rule`)
	return css.slice(at, css.indexOf('}', at))
}

const bar = style(read('src/editor/SiteEditMode.vue'))
const panel = style(read('src/editor/SiteMenuPanel.vue'))

test('the editing bar is one tinted row with the deep-primary edge', () => {
	const css = rule(bar, '.pq-site-editor__bar')
	assert.match(css, /border: 2px solid var\(--nldesign-color-primary-hover/)
	assert.match(css, /background: var\(--nldesign-color-primary-light/)
	assert.match(css, /padding: 12px 16px;/)
	assert.doesNotMatch(css, /justify-content: space-between/)
	assert.match(rule(bar, '.pq-site-editor__actions'), /display: contents;/)
})

test('the bar buttons are outlined, 40px high, square-cornered', () => {
	const css = rule(bar, '.pq-site-editor__bar .button-vue')
	assert.match(css, /height: 40px;/)
	assert.match(css, /border: 1px solid var\(--nldesign-color-primary,/)
	assert.match(css, /border-radius: 4px;/)
	assert.match(rule(bar, ".pq-site-editor__bar .button-vue[aria-pressed='true']"), /background: var\(--nldesign-color-primary-hover/)
	assert.match(rule(bar, ".pq-site-editor__bar .button-vue[data-testid='site-edit-leave']"), /margin-inline-start: auto;/)
})

test('the editor column has the site page insets', () => {
	assert.match(rule(bar, '.pq-site-editor'), /padding: 24px 24px 64px;/)
})

test('a menu item is a 57px row: a 40px field, 8px above and below, a hairline', () => {
	assert.match(rule(panel, '.pq-site-panel__row'), /padding: 8px 0;[\s\S]*border-bottom: 1px solid/)
	const field = rule(panel, '.pq-site-panel input,\n.pq-site-panel select')
	assert.match(field, /height: 40px;/)
	assert.match(field, /border-radius: 4px;/)
	assert.match(rule(panel, '.pq-site-panel__list'), /list-style: none;/)
})

test('the menu panel has the 2px deep-primary edge', () => {
	assert.match(rule(panel, '.pq-site-panel'), /border: 2px solid var\(--nldesign-color-primary-hover/)
})
