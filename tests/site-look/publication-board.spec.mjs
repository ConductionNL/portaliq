#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// publication-board.spec.mjs: the cards variant of a publication reads like
// board Publicatie. The date is written out ("4 oktober 2026", as the search
// results write it), the labels stand in their own column without a colon,
// the pill sits 12px over the title and the summary 20px under it, and the
// documents heading is 28px.
//
// Usage:
//   node --test tests/site-look/publication-board.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { visitorRows } from '../../src/site/lib/publicationDetail.js'

const BLOCK = 'src/site/components/PublicationDetailBlock.vue'
const source = readFileSync(new URL(`../../${BLOCK}`, import.meta.url), 'utf8')

test('the date is written out, as the search results write it', () => {
	const [date] = visitorRows(
		{ title: 'Verlichting fietspad Lindelaan', publicationDate: '2026-10-04T09:00:00+02:00' },
		{ t: (key) => key, locale: 'nl' },
	)
	assert.equal(date.value, '4 oktober 2026')
})

test('the cards variant names its labels without a colon; the plain one keeps it', () => {
	assert.match(
		source,
		/<dt class="pq-detail__label">\s*<strong>\{\{\s*cards \? field\.label : `\$\{field\.label\}:`\s*\}\}<\/strong>/,
	)
})

test('the pill, title and summary stand 12px and 20px apart; the documents heading is 28px', () => {
	const css = source
		.replace(/\/\*[\s\S]*?\*\//g, '')
		.replace(/\s+/g, ' ')
	assert.match(css, /\.pq-detail__body--cards \.pq-detail__pill \+ h1 \{ margin-block-start: -1rem; \}/)
	assert.match(css, /\.pq-detail__body--cards h1 \+ \.pq-detail__summary \{ margin-block-start: -0\.5rem; \}/)
	assert.match(css, /\.pq-detail__body--cards \.pq-detail__documents h2 \{ margin: 0; font-size: 1\.75rem;/)
})
