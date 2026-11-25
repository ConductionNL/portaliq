#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// records-list.spec.mjs: the list of public records (the council members on
// Zuiddrecht's "Hoe stemden de raadsleden") reads as a list: one record per
// line, the name as a link and what the record is under it in the muted ink.
// It ran together as "Bart ReijndersLid, Zuiddrecht Lokaal" under bullets.
//
// Usage:
//   node --test tests/site-look/records-list.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const source = readFileSync(
	new URL('../../src/site/components/PublicRecordsBlock.vue', import.meta.url),
	'utf8',
)
const css = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\s+/g, ' ')

test('the list and its link carry their own classes', () => {
	assert.match(source, /<ul class="pq-records__list">/)
	assert.match(source, /<a\s+class="utrecht-link pq-records__link"\s+:href="hrefFor\(entry\)"\s*>/)
})

test('one record per line, the subtitle under the name in the muted ink', () => {
	assert.match(css, /\.pq-records__list \{ display: flex; flex-direction: column; gap: 0\.75rem; margin: 1rem 0 0; padding: 0; list-style: none; \}/)
	assert.match(css, /\.pq-records__list li \{ display: flex; flex-direction: column;/)
	assert.match(css, /\.pq-records__subtitle \{ color: var\(--nldesign-color-text-muted, currentcolor\);/)
})
