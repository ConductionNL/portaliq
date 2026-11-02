#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// designed-site-type.spec.mjs: on a designed portal `body` reads the set's
// body face and a heading without a class of its own reads the set's
// heading face (designed-site-type).
//
// Usage:
//   node --test tests/site-look/designed-site-type.spec.mjs

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

/**
 * The declarations of the rule whose selector list contains this selector.
 *
 * @param {string} selector One selector of the list.
 * @return {string|null} The declarations.
 */
function ruleWith(selector) {
	for (const match of css.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
		const list = match[1].replace(/\s+/g, ' ').trim()
		// Split on the commas between selectors, not inside :where() or :has().
		const selectors = list.split(/,(?![^(]*\))/).map((s) => s.trim())
		if (list === selector || selectors.includes(selector)) {
			return match[2].replace(/\s+/g, ' ').trim()
		}
	}
	return null
}

test('body itself reads the set body face on a designed portal', () => {
	assert.match(
		ruleWith('body:has(.pq-site__header--designed)') || '',
		/font-family: var\(--utrecht-document-font-family, inherit\)/,
	)
})

test('a heading without a class reads the set heading face, at no specificity', () => {
	assert.match(
		ruleWith(
			':where(.pq-site:has(.pq-site__header--designed)) :where(h1, h2, h3, h4, h5, h6)',
		) || '',
		/font-family: var\(--utrecht-heading-font-family, inherit\)/,
	)
})
