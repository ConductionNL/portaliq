#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// news-item-translation.spec.mjs: a guardian reads a school news item that AI
// translated, sees the same notice a translated message shows, and reaches the
// original in one step (decision D24, news-item-translation). Runs against
// the site's Vue port (site-reaches-portal-parity REQ-SRP-033).
//
// Usage:
//   node --test tests/news-item-translation.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { buildNav, shellSections } from '../src/shared/portalNav.js'
import { pages } from '../src/site/pages/inbox/index.js'
import strings from '../src/site/pages/inbox/strings.js'
import { hasNews } from '../src/site/pages/inbox/translation.js'
import { t } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const NewsItem = await loadSfc('src/site/components/inbox/NewsItem.vue')

const ITEM = {
	id: 'n1',
	title: 'Studiedag',
	body: 'De school is vrijdag dicht.',
	translation: {
		targetLanguage: 'ar',
		text: 'المدرسة مغلقة يوم الجمعة.',
		translatedByAi: true,
		sourceLanguage: 'nl',
		originalRef: 'portaliq:newsItem:n1',
	},
}

/**
 * The shared portal API's source.
 *
 * @return {string} The source.
 */
function portalApiSource() {
	return readFileSync(join(ROOT, 'src', 'shared', 'portalApi.js'), 'utf8')
}

test('a translated news item shows the translation, the AI notice naming Dutch, and the original one button away', async () => {
	const html = await renderComponent(NewsItem, { item: ITEM, t, locale: 'en' })
	assert.match(
		html,
		/<h3 class="utrecht-heading-3 pq-news__title">Studiedag<\/h3>/,
	)
	assert.match(
		html,
		/<p class="utrecht-paragraph pq-news__body" lang="ar">المدرسة مغلقة يوم الجمعة\.<\/p>/,
	)
	assert.match(html, /<aside class="pq-ai-notice" aria-label="AI translation">/)
	assert.match(html, /Translated by AI from Dutch/)
	assert.match(
		html,
		/aria-expanded="false" aria-controls="portaliq-original-news-n1">Show the original text<\/button>/,
	)
	assert.match(
		html,
		/<blockquote id="portaliq-original-news-n1" class="pq-translated__original" lang="nl" hidden>(<!--\[-->)?De school is vrijdag dicht\./,
	)
})

test('a news item without a labelled translation renders as written with no notice', async () => {
	for (const translation of [
		undefined,
		{ ...ITEM.translation, translatedByAi: false },
	]) {
		const html = await renderComponent(NewsItem, {
			item: { ...ITEM, translation },
			t,
			locale: 'en',
		})
		assert.match(
			html,
			/<p class="utrecht-paragraph pq-news__body">De school is vrijdag dicht\.<\/p>/,
		)
		assert.doesNotMatch(html, /pq-ai-notice/)
	}
})

test('the news page appears only when the feed holds an item, and reads the feed', () => {
	assert.equal(hasNews([]), false)
	assert.equal(hasNews(null), false)
	assert.equal(hasNews([ITEM]), true)
	assert.equal(typeof pages.news, 'function')
	// The shared navigation offers News only when the feed holds an item.
	assert.equal(shellSections({ news: [ITEM] }).news, true)
	assert.equal(shellSections({ news: [] }).news, false)
	assert.ok(
		buildNav([], (key) => key, { news: true }).some(
			(entry) => entry.special === 'news',
		),
	)
	assert.ok(
		!buildNav([], (key) => key, { news: false }).some(
			(entry) => entry.special === 'news',
		),
	)
	assert.match(
		portalApiSource(),
		/async fetchNewsFeed\(\)[\s\S]*\/api\/news\/feed/,
	)
})

test('the news page carries the same language picker as the messages page', () => {
	for (const page of ['NewsPage', 'MessagesPage']) {
		const source = readFileSync(
			join(ROOT, 'src', 'site', 'pages', 'inbox', `${page}.vue`),
			'utf8',
		)
		assert.match(
			source,
			/import MessageLanguagePicker from '\.\.\/\.\.\/components\/inbox\/MessageLanguagePicker\.vue'/,
		)
		assert.match(source, /<MessageLanguagePicker/)
	}
})

test('newsItem declares translations and the register moved', () => {
	const register = JSON.parse(
		readFileSync(
			join(ROOT, 'lib', 'Settings', 'portaliq_register.json'),
			'utf8',
		),
	)
	const newsItem = register.components.schemas.newsItem
	// The version is pinned so a register change has to be deliberate, and
	// the list is widened WITH the change that moves it. #1166 bumped the
	// schema to 0.3.0 for `publishedAt` and left this list behind, which
	// turned `validate` and `check:specs` red on development itself and so on
	// every open pull request.
	assert.ok(
		['0.2.0', '0.2.1', '0.3.0'].includes(newsItem.version),
		`newsItem ${newsItem.version} is not a version this test knows; widen the list`
			+ ' in the same change that moves the schema',
	)
	assert.equal(newsItem.properties.translations.type, 'array')
	assert.equal(
		register.info.version,
		register.components.registers.portaliq.version,
	)
})

test('every string has a Dutch value', () => {
	for (const key of [
		'News',
		'No news yet.',
		'News from school is translated by AI into your language. You can always see the original text.',
	]) {
		assert.equal(strings.en[key], key, `en identity for ${key}`)
		assert.ok(strings.nl[key] && strings.nl[key] !== key, `nl value for ${key}`)
	}
})
