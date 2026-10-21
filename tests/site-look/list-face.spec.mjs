#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// list-face.spec.mjs: a bulleted list on a designed site is set in the set's
// face. The vendored `nlds-app.css` names its own face on
// `.utrecht-unordered-list` with !important, which put the lists of the
// Zuiddrecht cookie page in Roboto between paragraphs in Fira Sans.
//
// Usage:
//   node --test tests/site-look/list-face.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

/**
 * A stylesheet with its comments removed and its whitespace collapsed.
 *
 * @param {string} path The file, from the app root.
 * @return {string} The text.
 */
function read(path) {
	return readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8')
		.replace(/\/\*[\s\S]*?\*\//g, '')
		.replace(/\s+/g, ' ')
}

test('the vendored sheet forces its face on a bulleted list', () => {
	assert.match(
		read('css/nlds/nlds-app.css'),
		/\.utrecht-unordered-list\{font-family:[^}]*!important\}/,
	)
})

test('a designed site puts the list back in the set face, with the same weight', () => {
	assert.match(
		read('css/site-theme.css'),
		/\.pq-site:has\(\.pq-site__header--designed\) \.utrecht-unordered-list \{ font-family: var\(--utrecht-document-font-family, inherit\) !important; \}/,
	)
})
