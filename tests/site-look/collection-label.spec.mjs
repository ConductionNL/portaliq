#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// collection-label.spec.mjs: a collection block keeps its own label as its
// heading ("Laatste cijfers" over the grades table), before the
// collection's (collection-block-label).
//
// Usage:
//   node --test tests/site-look/collection-label.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { loadSfc } from '../support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')

test('the heading is the block label, else the collection label', async () => {
	const page = await loadSfc('src/site/pages/collections/ContributionPage.vue', {
		'@conduction/nextcloud-vue/public': 'export default {}\n',
	})
	const headingOf = page.methods.headingOf
	assert.equal(
		headingOf({
			block: { label: 'Laatste cijfers' },
			collection: { label: 'Mijn cijfers' },
		}),
		'Laatste cijfers',
	)
	assert.equal(
		headingOf({ block: {}, collection: { label: 'Mijn cijfers' } }),
		'Mijn cijfers',
	)
	assert.equal(headingOf({ block: {}, collection: {} }), '')
	// The page asks it both for whether to show a heading and for its text.
	const showsHeading = page.methods.showsHeading
	assert.equal(
		showsHeading.call(
			{ headingOf, entry: { label: 'Overzicht' } },
			{ block: { label: 'Laatste cijfers' }, collection: {} },
		),
		true,
	)
})

test('the table heading renders through it', () => {
	const source = readFileSync(
		join(ROOT, 'src/site/pages/collections/ContributionPage.vue'),
		'utf8',
	)
	assert.match(source, /class="utrecht-heading-3">\s*\{\{ headingOf\(item\) \}\}/)
})
