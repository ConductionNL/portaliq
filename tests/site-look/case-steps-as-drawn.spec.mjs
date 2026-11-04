#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// case-steps-as-drawn.spec.mjs: on a designed portal a case's steps stand as
// the Zaak boards draw them (Zaak, ZaakAfgerond, ZaakWooVerzoek, MobielZaak):
// the step name, its words and a done step's date stacked at the font's own
// leading, 4px apart, the words 4px under the name.
//
// Usage:
//   node --test tests/site-look/case-steps-as-drawn.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const css = readFileSync(join(ROOT, 'css/site-theme.css'), 'utf8').replace(
	/\/\*[\s\S]*?\*\//g,
	'',
)
const STEPS = '.pq-site:has(.pq-site__header--designed) .pq-process-steps'

/**
 * The declarations of the first rule with exactly this selector.
 *
 * @param {string} selector The selector.
 * @return {string} The declarations, '' when there is no such rule.
 */
function ruleWith(selector) {
	for (const match of css.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
		if (match[1].replace(/\s+/g, ' ').trim() === selector) {
			return match[2].replace(/\s+/g, ' ').trim()
		}
	}
	return ''
}

test('the step name, its words and its date sit at the font leading', () => {
	assert.match(
		ruleWith(`${STEPS} .denhaag-process-steps__step-heading`),
		/line-height: normal;/,
	)
	assert.match(
		ruleWith(`${STEPS} .pq-process-steps__text`),
		/line-height: normal !important;/,
	)
	assert.match(
		ruleWith(`${STEPS} .denhaag-process-steps__step-meta`),
		/line-height: normal;/,
	)
})

test('the words start 4px under the step name', () => {
	// The header is the 36px marker high; the name sits 6px down at about
	// 23px, so the body pulls up 3px to leave 4px under the name.
	assert.match(
		ruleWith(`${STEPS} .denhaag-process-steps__step-body`),
		/gap: 4px; margin: -3px 0 0;/,
	)
})

test('the next step stands on a dashed chip 8px under the current step', () => {
	const rule = ruleWith(`${STEPS} .pq-process-steps__next`)
	assert.match(rule, /order: 3;/)
	assert.match(rule, /align-self: flex-start;/)
	assert.match(rule, /margin-block-start: 8px;/)
	assert.match(rule, /padding: 6px 12px;/)
	assert.match(rule, /border: 1px dashed color-mix\( in srgb, var\(--nldesign-color-border-dark, currentcolor\) 58%, var\(--utrecht-document-background-color/, 'an opaque grey, #b4bac3 on white')
	assert.match(rule, /background-color: var\(--nldesign-color-surface/)
	assert.match(rule, /color: var\(--nldesign-color-text-muted/)
	assert.match(rule, /font-size: 0\.9375rem;/)
})
