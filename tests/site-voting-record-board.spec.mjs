#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-voting-record-board.spec.mjs: a public record reads as the PtStemgedrag
// board (no side box but a 4px accent rule, the figures as a label and bold
// number list, a tinted header row and 12px 16px cells), and the example site
// gives the voting record page its help.
//
// Usage:
//   node --test tests/site-voting-record-board.spec.mjs
//
// @spec openspec/changes/site-voting-record-board/specs/site-public-records/spec.md#requirement-a-public-record-reads-as-the-voting-record-board

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const BLOCK = read('src/site/components/PublicRecordsBlock.vue')
const SITE = JSON.parse(read('lib/Settings/sites/zuiddrecht.json'))

/**
 * The declarations of the first rule with this exact selector.
 *
 * @param {string} selector The selector.
 * @return {string} The declarations.
 */
function rule(selector) {
	const at = BLOCK.indexOf(`${selector} {`)
	assert.ok(at > -1, selector)
	return BLOCK.slice(at, BLOCK.indexOf('}', at))
}

test('the side column has a 4px accent rule and no box', () => {
	const side = rule('.pq-records__side')
	assert.match(side, /border-block-start: 4px solid var\(--nldesign-color-accent/)
	assert.doesNotMatch(side, /border: 1px/)
})

test('the figures are the shared figure cards in their list variant', () => {
	assert.match(BLOCK, /<KpiCards\s+v-if="record\.summary\.length"\s+variant="list"/)
	const KPI = read('src/site/components/collections/KpiCards.vue')
	assert.match(KPI, /:class="\{ 'pq-kpi--list': variant === 'list' \}"/)
	assert.match(KPI, /\.pq-kpi--list \.pq-kpi__card \{\n\tdisplay: grid;\n\tgrid-template-columns: minmax\(0, 1fr\) max-content;/)
})

test('the table has a tinted header row and 12px 16px cells', () => {
	assert.match(rule('.pq-records__main :deep(th)'), /padding: 12px 16px;/)
	assert.match(rule('.pq-records__main :deep(td)'), /border-block-start: 1px solid/)
})

// @spec openspec/changes/site-voting-record-board/specs/site-public-records/spec.md#requirement-the-voting-record-page-carries-its-help
test('the voting record page carries its help', () => {
	let page = null
	const walk = (node) => {
		if (Array.isArray(node)) {
			node.forEach(walk)
		} else if (node && typeof node === 'object') {
			if (node.route === '/bestuur/hoe-stemden-de-raadsleden') {
				page = node
			}
			Object.values(node).forEach(walk)
		}
	}
	walk(SITE)
	assert.match(page.helpText, /Afwezig/)
})
