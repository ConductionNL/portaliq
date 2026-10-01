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
import { existsSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
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
 * The shared portal API's source, wherever the shell slice put it.
 *
 * @return {string} The source.
 */
function portalApiSource() {
	const shared = join(ROOT, 'src', 'shared', 'portalApi.js')
	return readFileSync(
		existsSync(shared)
			? shared
			: join(ROOT, 'src', 'portal', 'lib', 'portalApi.js'),
		'utf8',
	)
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
	assert.ok(
		['0.2.0', '0.2.1'].includes(newsItem.version),
		`newsItem ${newsItem.version}`,
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
