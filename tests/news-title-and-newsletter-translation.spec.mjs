#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// news-title-and-newsletter-translation.spec.mjs: a translated news item shows
// its translated title with the body under the one AI notice, the original
// shows both, and the newsletter archive renders its items exactly as the News
// page does (decision D24, news-title-and-newsletter-translation). Runs
// against the site's Vue port (site-reaches-portal-parity REQ-SRP-033).
//
// Usage:
//   node --test tests/news-title-and-newsletter-translation.spec.mjs

import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import strings from '../src/site/pages/inbox/strings.js'
import { hasArchive } from '../src/site/pages/inbox/translation.js'
import { t } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const NewsItem = await loadSfc('src/site/components/inbox/NewsItem.vue')
const NewsletterArchive = await loadSfc(
	'src/site/components/inbox/NewsletterArchive.vue',
)

const TRANSLATION = {
	targetLanguage: 'ar',
	text: 'المدرسة مغلقة يوم الجمعة.',
	title: 'يوم دراسي',
	translatedByAi: true,
	sourceLanguage: 'nl',
	originalRef: 'portaliq:newsItem:n1',
}

const ITEM = {
	id: 'n1',
	title: 'Studiedag',
	body: 'De school is vrijdag dicht.',
	translation: TRANSLATION,
}

test("a translated title shows in the reader's language and the original shows title and body", async () => {
	const html = await renderComponent(NewsItem, { item: ITEM, t, locale: 'en' })
	assert.match(
		html,
		/<h3 class="utrecht-heading-3 pq-news__title" lang="ar">يوم دراسي<\/h3>/,
	)
	assert.equal(
		(html.match(/pq-ai-notice"/g) || []).length,
		1,
		'one notice covers title and body',
	)
	assert.match(html, /Translated by AI from Dutch/)
	assert.match(
		html,
		/<blockquote id="portaliq-original-news-n1" class="pq-translated__original" lang="nl" hidden>(<!--\[-->)?<p class="utrecht-paragraph pq-translated__original-title">Studiedag<\/p><p class="utrecht-paragraph">De school is vrijdag dicht\.<\/p>/,
	)
})

test('a translation without a title keeps the title as written and the original as before', async () => {
	const item = { ...ITEM, translation: { ...TRANSLATION, title: undefined } }
	const html = await renderComponent(NewsItem, { item, t, locale: 'en' })
	assert.match(
		html,
		/<h3 class="utrecht-heading-3 pq-news__title">Studiedag<\/h3>/,
	)
	assert.match(
		html,
		/<blockquote id="portaliq-original-news-n1" class="pq-translated__original" lang="nl" hidden>(<!--\[-->)?De school is vrijdag dicht\.(<!--\]-->)?<\/blockquote>/,
	)
})

test('an unlabelled translation shows neither the translated title nor a notice', async () => {
	const item = { ...ITEM, translation: { ...TRANSLATION, translatedByAi: false } }
	const html = await renderComponent(NewsItem, { item, t, locale: 'en' })
	assert.match(
		html,
		/<h3 class="utrecht-heading-3 pq-news__title">Studiedag<\/h3>/,
	)
	assert.doesNotMatch(html, /pq-ai-notice/)
})

test('the newsletter archive renders its items as the News page does, with its own ids', async () => {
	const archive = [
		{
			id: 'nl1',
			title: 'Nieuwsbrief september',
			sentAt: '2026-09-15T00:00:00+00:00',
			items: [ITEM],
		},
		{
			id: 'nl2',
			title: 'Nieuwsbrief juni',
			sentAt: '2026-06-15T00:00:00+00:00',
			items: [],
		},
	]
	const html = await renderComponent(NewsletterArchive, {
		archive,
		t,
		locale: 'en',
	})
	assert.match(
		html,
		/<h2 id="portaliq-news-archive-heading" class="utrecht-heading-2">Newsletters<\/h2>/,
	)
	assert.match(
		html,
		/<h3 class="utrecht-heading-3 pq-newsletter__title">Nieuwsbrief september<\/h3>/,
	)
	assert.match(
		html,
		/<h4 class="utrecht-heading-4 pq-news__title" lang="ar">يوم دراسي<\/h4>/,
	)
	assert.match(html, /Translated by AI from Dutch/)
	assert.match(html, /aria-controls="portaliq-original-newsletter-nl1-n1"/)
	assert.match(html, /This newsletter has no items for you\./)
})

test('an empty archive renders nothing', async () => {
	assert.equal(hasArchive([]), false)
	assert.equal(hasArchive(null), false)
	assert.equal(
		await renderComponent(NewsletterArchive, { archive: [], t, locale: 'en' }),
		'<!---->',
	)
})

test('the News page reads the archive through the API and reloads it with the language', () => {
	const shared = join(ROOT, 'src', 'shared', 'portalApi.js')
	const api = readFileSync(
		existsSync(shared)
			? shared
			: join(ROOT, 'src', 'portal', 'lib', 'portalApi.js'),
		'utf8',
	)
	assert.match(
		api,
		/async fetchNewsletterArchive\(\)[\s\S]*\/api\/newsletters\/archive/,
	)
	const page = readFileSync(
		join(ROOT, 'src', 'site', 'pages', 'inbox', 'NewsPage.vue'),
		'utf8',
	)
	assert.match(page, /this\.api\.fetchNewsletterArchive\(\)/)
	assert.match(page, /<NewsletterArchive/)
})

test('every string has a Dutch value and the check runs with the specs', () => {
	for (const key of ['Newsletters', 'This newsletter has no items for you.']) {
		assert.equal(strings.en[key], key, `en identity for ${key}`)
		assert.ok(strings.nl[key] && strings.nl[key] !== key, `nl value for ${key}`)
	}
	const pkg = JSON.parse(readFileSync(join(ROOT, 'package.json'), 'utf8'))
	assert.match(
		pkg.scripts['check:specs'],
		/check:news-title-and-newsletter-translation/,
	)
})
