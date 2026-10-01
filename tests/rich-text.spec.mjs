#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// rich-text.spec.mjs: a `richText` block on a contribution page renders
// headings and paragraphs as text, and no markup from the manifest
// (site-reaches-portal-parity REQ-SRP-018).
//
// Usage:
//   node --test tests/rich-text.spec.mjs
//
// MarkdownBlock.vue was checked and not reused: cnRenderMarkdown removes a
// script but keeps safe raw HTML such as <b> and <img>, and this requirement
// allows none.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { richTextLines } from '../src/site/components/collections/richText.js'
import { renderSfc } from './support/render-sfc.mjs'

test('headings and paragraphs come out of the markdown, blank lines are dropped', () => {
	assert.deepEqual(richTextLines('# Title\n\n## Part\n### Detail\nText line\n#### Not a heading'), [
		{ index: 0, level: 1, text: 'Title' },
		{ index: 2, level: 2, text: 'Part' },
		{ index: 3, level: 3, text: 'Detail' },
		{ index: 4, level: 0, text: 'Text line' },
		{ index: 5, level: 0, text: '#### Not a heading' },
	])
})

test('a script tag, an event handler and raw HTML stay text', async () => {
	const html = await renderSfc('src/site/components/collections/RichTextBlock.vue', {
		markdown: '## Welkom\n<script>alert(1)</script>\n<img src=x onerror=alert(1)> <b>vet</b>',
	})

	assert.doesNotMatch(html, /<script/i)
	assert.doesNotMatch(html, /<img/i)
	assert.doesNotMatch(html, /<b>/)
	assert.match(html, /&lt;script&gt;alert\(1\)&lt;\/script&gt;/)
	assert.match(html, /<h3 class="utrecht-heading-3">Welkom<\/h3>/)
})

test('an empty block renders an empty container', async () => {
	const html = await renderSfc('src/site/components/collections/RichTextBlock.vue', {})
	assert.match(html, /<div class="pq-richtext" data-testid="collections-richtext">(<!--\[--><!--\]-->)?<\/div>/)
})
