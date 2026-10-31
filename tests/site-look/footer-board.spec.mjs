#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// footer-board.spec.mjs: the designed footer reads like board Voet. The
// columns start 56px under the motif, not 56px under the band's top edge
// that the motif is drawn over; a column title stands 14px above its list
// and the lines are 10px apart.
//
// Usage:
//   node --test tests/site-look/footer-board.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const css = readFileSync(
	new URL('../../css/site-theme.css', import.meta.url),
	'utf8',
)
	.replace(/\/\*[\s\S]*?\*\//g, '')
	.replace(/\s+/g, ' ')

const DESIGNED =
	'.pq-site:has(.pq-site__header--designed) .ac-footer section.pq-footer__band--designed.pq-footer__band--content'

/**
 * The declarations of every rule whose selector list is exactly `selector`.
 *
 * @param {string} selector The selector, whitespace collapsed.
 * @return {string} The declarations, joined.
 */
function rule(selector) {
	const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
	return [...css.matchAll(new RegExp(`(?:^|\\}) ?${escaped} \\{([^}]*)\\}`, 'g'))]
		.map((match) => match[1])
		.join(';')
}

test('the columns start 56px under the motif', () => {
	assert.match(
		rule(DESIGNED),
		/padding-block: calc\(56px \+ var\(--cn-brand-stripe-height, 0px\)\) 44px/,
	)
})

test('a column title stands 14px above its list, over the vendored 12px', () => {
	assert.match(rule(`${DESIGNED} h3.ac-footer__menu-title`), /margin-block-end: 14px/)
})

test('the menu lines and the contact lines are 10px apart', () => {
	assert.match(
		rule(
			`${DESIGNED} ul li:not(:last-child), .pq-site:has(.pq-site__header--designed) .ac-footer .pq-footer__band--designed .pq-footer__contact p:not(:last-child)`,
		),
		/margin-block-end: 10px/,
	)
})
