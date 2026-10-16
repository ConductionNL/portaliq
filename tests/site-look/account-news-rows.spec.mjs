#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// account-news-rows.spec.mjs: the news block on an account page shows who an
// item is for, its date and its title, never its body (account-news-rows).
//
// Usage:
//   node --test tests/site-look/account-news-rows.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { renderSfc } from '../support/render-sfc.mjs'

const FEED = [
	{
		id: 'n1',
		title: 'De Kinderboekenweek is begonnen',
		body: 'Groep 8 las voor. **Lees** [het programma](https://example.org).',
		publishedAt: '2026-10-02T09:00:00+02:00',
		audienceLabel: 'hele school',
	},
	{
		id: 'n2',
		title: 'Groep 7 gaat naar de kinderboerderij',
		body: '# Uitje',
		publishedAt: '2026-09-30',
	},
]

/**
 * The block's HTML for a feed.
 *
 * @param {Array<object>} feed The feed.
 * @return {Promise<string>} The HTML.
 */
function render(feed) {
	return renderSfc('src/site/components/collections/NewsBlock.vue', {
		initialFeed: feed,
		label: 'Nieuw van school',
		t: (key) => key,
		locale: 'nl',
	})
}

test('each row shows who, when and the title', async () => {
	const html = await render(FEED)
	assert.equal(html.split('data-testid="news-block-row"').length - 1, 2)
	assert.match(html, /hele school/)
	assert.match(html, /2 oktober 2026/)
	assert.match(html, /De Kinderboekenweek is begonnen/)
	assert.match(html, /30 september 2026/)
})

test('the body and its raw markdown never show', async () => {
	const html = await render(FEED)
	assert.doesNotMatch(html, /Groep 8 las voor/)
	assert.doesNotMatch(html, /\*\*Lees\*\*|\[het programma\]|# Uitje/)
})
