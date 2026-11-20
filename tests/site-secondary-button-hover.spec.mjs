#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-secondary-button-hover.spec.mjs: an outlined (secondary) button takes
// the secondary hover tokens on hover.
//
// The site theme colours every secondary button with !important, to beat the
// vendored nlds-app.css. Without a hover rule of the same weight that also
// beats the Utrecht hover rule, and the button keeps its rest colour on hover
// (thematiq token-set-coverage-next-waves 7.2).
//
// Usage:
//   node --test tests/site-secondary-button-hover.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const THEME_CSS = new URL('../css/site-theme.css', import.meta.url)

/**
 * The stylesheet without comments.
 *
 * @param {string} css The stylesheet.
 * @return {string} The same, without comments.
 */
function withoutComments(css) {
	return css.replace(/\/\*[\s\S]*?\*\//g, ' ')
}

/**
 * The body of the first rule whose selector list contains `selector`.
 *
 * @param {string} css The stylesheet, without comments.
 * @param {string} selector One selector of the list.
 * @return {string} The rule's body, whitespace collapsed, or ''.
 */
function ruleWith(css, selector) {
	for (const match of css.matchAll(/([^{}]+){([^}]*)}/g)) {
		const selectors = match[1].split(/,(?![^(]*\))/).map((s) => s.replace(/\s+/g, ' ').trim())
		if (selectors.includes(selector)) {
			return match[2].replace(/\s+/g, ' ').trim()
		}
	}
	return ''
}

const css = withoutComments(readFileSync(THEME_CSS, 'utf8'))

test('at rest a secondary button reads its own rest colours', () => {
	const body = ruleWith(css, '.pq-site .utrecht-button--secondary-action')
	assert.match(body, /border-color: var\(--_utrecht-button-border-color\) !important/)
	assert.match(body, /(^|; )color: var\(--_utrecht-button-color\) !important/)
})

test('on hover a secondary button reads the hover colours, with the same weight', () => {
	for (const selector of [
		".pq-site .utrecht-button--secondary-action:hover:not(:disabled, [aria-disabled='true'], .utrecht-button--disabled)",
		'.pq-site .utrecht-button--secondary-action.utrecht-button--hover:not(:disabled, .utrecht-button--disabled)',
	]) {
		const body = ruleWith(css, selector)
		assert.match(body, /border-color: var\(--_utrecht-button-hover-border-color\) !important/, selector)
		assert.match(body, /(^|; )color: var\(--_utrecht-button-hover-color\) !important/, selector)
	}
})
