#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// content-dates.spec.mjs: a date inside page content reads in the language
// the content is written in (site-dates-in-content-language).
//
// On a portal that serves Dutch and English, an English browser gets an
// English document, while the page itself is Dutch: the school proof showed
// "2 October" and "Oct" in Dutch news and agenda blocks. The site shell now
// provides the page's own language; these tests render a block with an
// English document and a Dutch page.
//
// Usage:
//   node --test tests/site-look/content-dates.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { after, before, test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { loadSfc } from '../support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const ITEMS = [{ date: '2026-10-07', title: 'Schoolfotograaf' }]

let saved
before(async () => {
	// Vue's DOM runtime reads `document` when it loads: load it first, then
	// stand in an English document for the date helpers.
	await import('vue')
	await import('vue/server-renderer')
	saved = globalThis.document
	globalThis.document = { documentElement: { lang: 'en' } }
})
after(() => {
	globalThis.document = saved
})

/**
 * Render the event list, with or without the shell's content language.
 *
 * @param {string|null} contentLocale The page's language, or null for none.
 * @param {string} display `tiles` or `labels`.
 * @return {Promise<string>} The HTML.
 */
async function eventList(contentLocale, display) {
	const NlEventList = await loadSfc('src/site/widgets/nlEventList/NlEventList.vue')
	const { createSSRApp, h } = await import('vue')
	const { renderToString } = await import('vue/server-renderer')
	const app = createSSRApp({
		render: () => h(NlEventList, { items: ITEMS, display, upcomingOnly: false }),
	})
	if (contentLocale !== null) {
		app.provide('siteContentLocale', () => contentLocale)
	}
	return renderToString(app)
}

test('a date tile on a Dutch page reads "okt" in an English document', async () => {
	const html = await eventList('nl', 'tiles')
	assert.match(html, />okt</)
	assert.doesNotMatch(html, />Oct</)
})

test('a date label on a Dutch page reads "7 okt"', async () => {
	const html = await eventList('nl', 'labels')
	assert.match(html, /7 okt/)
})

test('outside the shell the document language still applies', async () => {
	const html = await eventList(null, 'tiles')
	assert.match(html, />Oct</)
})

test('the shell provides the page record language, else the site language, and marks the page', () => {
	const app = readFileSync(join(ROOT, 'src/site/App.vue'), 'utf8')
	assert.match(app, /siteContentLocale: \(\) => this\.contentLocale,/)
	assert.match(app, /const own = String\(this\.page\?\.locale \|\| ''\)\.trim\(\)/)
	assert.match(app, /:lang="contentLocale"/)
	for (const widget of [
		'nlNewsList/NlNewsList',
		'nlNewsArticle/NlNewsArticle',
		'nlEventList/NlEventList',
		'nlCatalogue/NlCatalogue',
	]) {
		const source = readFileSync(
			join(ROOT, `src/site/widgets/${widget}.vue`),
			'utf8',
		)
		assert.match(
			source,
			/from: 'siteContentLocale'/,
			`${widget} reads the content language`,
		)
	}
})
