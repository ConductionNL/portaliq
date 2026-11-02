#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// traffic-table-board-look.spec.mjs: the Traffic page's ranked tables are the
// compact table the PtVerkeer board draws in a card: on the card's 22px inset,
// 14px text, a tinted 600-weight header row at 10px 12px, cells at 11px 12px.
//
// Usage:
//   node --test tests/traffic-table-board-look.spec.mjs
//
// @spec exclude styling only: the board PtVerkeer is the specification

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const CSS = readFileSync(new URL('../src/widgets/trafficTable.css', import.meta.url), 'utf8')

/**
 * The declarations of the first rule with this exact selector.
 *
 * @param {string} selector The selector.
 * @return {string} The declarations.
 */
function rule (selector) {
	const at = CSS.indexOf(`${selector} {`)
	assert.ok(at > -1, selector)
	return CSS.slice(at, CSS.indexOf('}', at))
}

test('the table sits on the card inset at 14px', () => {
	assert.match(rule('.traffic-table'), /padding: 0 22px 20px;/)
	assert.match(rule('.traffic-table__table'), /font-size: 14px;/)
})

test('the header row is tinted, 600 and padded 10px 12px; cells 11px 12px', () => {
	const head = rule('.traffic-table__table th')
	assert.match(head, /padding: 10px 12px;/)
	assert.match(head, /font-weight: 600;/)
	assert.match(head, /background: color-mix/)
	assert.match(CSS, /\.traffic-table__table td \{\n\tpadding: 11px 12px;/)
})
