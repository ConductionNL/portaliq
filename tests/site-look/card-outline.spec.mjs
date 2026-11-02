#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// card-outline.spec.mjs: the site's outlined cards (a link list card, the
// outlined sign-in card, a sign-in page card) draw their line from one set
// token, `--nldesign-website-card-border-color`, with the line each drew
// before as the fallback. The Zuiddrecht boards draw every card line in one
// colour; the three cards drew two different ones. A card's heading stands
// 0.75rem above what follows.
//
// Usage:
//   node --test tests/site-look/card-outline.spec.mjs

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
		.replace(/\s+/g, ' ')
}

/**
 * The declarations of the one rule whose selector is `selector`.
 *
 * @param {string} text     The stylesheet.
 * @param {string} selector The selector.
 * @return {string} Its declarations.
 */
function rule(text, selector) {
	const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
	const match = text.match(new RegExp(`(?:^|[}>]) ?${escaped} \\{([^}]*)\\}`))
	assert.ok(match, `${selector} has a rule`)
	return match[1]
}

const TOKEN = '--nldesign-website-card-border-color'

test('every outlined site card reads the card line token, with its old line as fallback', () => {
	assert.match(
		rule(read('src/site/widgets/nlLinkList/NlLinkList.vue'), '.nl-link-list--card'),
		new RegExp(`border: 1px solid var\\( ?${TOKEN}, var\\( ?--nldesign-color-border-dark,`),
	)
	assert.match(
		rule(read('src/site/widgets/nlSignIn/NlSignIn.vue'), '.nl-signin-card--outline'),
		new RegExp(`border: 1px solid var\\( ?${TOKEN}, var\\( ?--nldesign-color-border-dark,`),
	)
	assert.match(
		rule(read('css/site-theme.css'), '.pq-signin__card'),
		new RegExp(`border: 1px solid var\\( ?${TOKEN}, var\\( ?--nldesign-color-border,`),
	)
})

test("a link list card's heading stands 0.75rem above what follows", () => {
	assert.match(
		rule(
			read('src/site/widgets/nlLinkList/NlLinkList.vue'),
			'.nl-link-list--card h2, .nl-link-list--tinted h2',
		),
		/margin-block-end: 0\.75rem/,
	)
})
