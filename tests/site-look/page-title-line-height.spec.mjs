#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// page-title-line-height.spec.mjs: a page that opens with its own h1 (the
// sign-in page, a publication, the accessibility statement) sets its title
// at the page title's line height, as the content pages do. The boards draw
// every 44px title at 1.15; these titles stood at the heading's 1.25.
//
// Usage:
//   node --test tests/site-look/page-title-line-height.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

function read (path) {
  return readFileSync(new URL(`../../${path}`, import.meta.url), 'utf8')
}
const css = read('css/site-theme.css')
	.replace(/\/\*[\s\S]*?\*\//g, '')
	.replace(/\s+/g, ' ')

test('a site h1 takes the page title line height, the heading one without it', () => {
	assert.match(
		css,
		/\.pq-site h1\.utrecht-heading-1 \{ line-height: var\( --nldesign-website-page-title-line-height, var\(--utrecht-heading-1-line-height, revert\) \); \}/,
	)
})

test('the pages the boards measured open with that h1', () => {
	for (const file of [
		'src/site/components/AccessibilityStatementPage.vue',
		'src/site/components/PublicationDetailBlock.vue',
		'src/site/components/chrome/SignInPage.vue',
	]) {
		assert.match(read(file), /<h1[^>]*class="utrecht-heading-1/, file)
	}
})
