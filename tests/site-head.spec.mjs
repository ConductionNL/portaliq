#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-head.spec.mjs: the public site's served HTML carries the page's head
// (site-page-seo-history-and-media REQ-SPH-002). The template prints every
// tag from the `head` the controller passes, escaped with p(), and falls back
// to noindex when there is none; the renderer prefers the search title so the
// tab never contradicts the served head.
//
// Usage:
//   node --test tests/site-head.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const template = readFileSync(join(ROOT, 'templates', 'site.php'), 'utf8')

test('the template prints every head tag, escaped', () => {
	for (const tag of [
		/<title><\?php p\(\$headTitle\); \?><\/title>/,
		/<meta name="description" content="<\?php p\(\$head\['description'\]\); \?>">/,
		/<meta name="robots" content="<\?php p\(/,
		/<link rel="canonical" href="<\?php p\(\$head\['canonical'\]\); \?>">/,
		/<meta property="og:title" content="<\?php p\(\$headTitle\); \?>">/,
		/<meta property="og:image" content="<\?php p\(\$head\['ogImage'\]\); \?>">/,
	]) {
		assert.match(template, tag)
	}
	assert.doesNotMatch(template, /<\?=/, 'no unescaped echo in the template')
})

test('without a head the page is noindex and titled by the portal', () => {
	assert.match(template, /\? \$head\['robots'\] : 'noindex'/)
	assert.match(template, /\$portalConfig\['title'\]/)
})

test('the renderer prefers the search title for the tab', () => {
	const app = readFileSync(join(ROOT, 'src', 'site', 'App.vue'), 'utf8')
	assert.match(app, /this\.page\?\.seo\?\.title \|\| this\.page\?\.title/)
})
