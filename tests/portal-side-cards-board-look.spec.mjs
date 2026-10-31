#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-side-cards-board-look.spec.mjs: a portal page's side summary cards
// are the dossiq side card (PtPortal, DqZaak): 20px 22px padding, headed by a
// 15px muted label at weight 600.
//
// Usage:
//   node --test tests/portal-side-cards-board-look.spec.mjs
//
// @spec exclude styling only: the board PtPortal is the specification

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const SOURCE = readFileSync(new URL('../src/widgets/PortalSummaryCard.vue', import.meta.url), 'utf8')

/**
 * The declarations of the first rule with this exact selector.
 *
 * @param {string} selector The selector.
 * @return {string} The declarations.
 */
function rule(selector) {
	const at = SOURCE.indexOf(`${selector} {`)
	assert.ok(at > -1, selector)
	return SOURCE.slice(at, SOURCE.indexOf('}', at))
}

test('the card has the board card padding', () => {
	assert.match(rule('.portal-summary'), /padding: 20px 22px;/)
})

test('the heading is a 15px muted label at weight 600', () => {
	const title = rule('.portal-summary__title')
	assert.match(title, /font-size: 15px;/)
	assert.match(title, /font-weight: 600;/)
	assert.match(title, /color: var\(--color-text-maxcontrast\) !important;/)
})
