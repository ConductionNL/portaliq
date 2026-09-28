#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// news-title-and-newsletter-translation.spec.mjs: a translated news item shows
// its translated title with the body under the one AI notice, the original
// shows both, and the newsletter archive renders its items exactly as the News
// page does (decision D24, news-title-and-newsletter-translation).
//
// Usage:
//   node --test tests/news-title-and-newsletter-translation.spec.mjs

import assert from 'node:assert/strict'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'

const require = createRequire(import.meta.url)
const babel = require('@babel/core')
const { createElement } = require('react')
const { renderToStaticMarkup } = require('react-dom/server')

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const OUT_DIR = join(ROOT, 'node_modules', '.cache', 'portaliq-tests-news-title')

/**
 * Compile one portal source file with the portal build's React preset and
 * import it from where `react` resolves.
 *
 * @param {string} relative - the path under src/portal
 * @return {Promise<object>} the module
 */
async function load(relative) {
	const source = join(ROOT, 'src', 'portal', relative)
	const compiled = babel.transformSync(readFileSync(source, 'utf8'), {
		filename: source,
		babelrc: false,
		configFile: false,
		presets: [['@babel/preset-react', { runtime: 'automatic' }]],
	})
	mkdirSync(OUT_DIR, { recursive: true })
	const flat = (path) => path.replace(/[\\/]/g, '_').replace(/\.jsx?$/, '.mjs')
	const code = compiled.code.replace(/from '\.\/([A-Za-z]+)\.jsx'/g, (whole, name) => `from './${flat('components/' + name + '.jsx')}'`)
	const out = join(OUT_DIR, flat(relative))
	writeFileSync(out, code)
	return import(pathToFileURL(out).href)
}

await load('components/TranslatedText.jsx')
await load('components/MessagesPage.jsx')
const { NewsItem, NewsletterArchive, hasArchive } = await load('components/NewsPage.jsx')

/**
 * The identity translator: English source strings with interpolation.
 *
 * @param {string} key - the English string
 * @param {object} [vars] - placeholder values
 * @return {string} the string
 */
function t(key, vars) {
	let text = key
	for (const [name, value] of Object.entries(vars || {})) {
		text = text.replace(`{${name}}`, String(value))
	}
	return text
}

const TRANSLATION = {
	targetLanguage: 'ar',
	text: 'المدرسة مغلقة يوم الجمعة.',
	title: 'يوم دراسي',
	translatedByAi: true,
	sourceLanguage: 'nl',
	originalRef: 'portaliq:newsItem:n1',
}

const ITEM = { id: 'n1', title: 'Studiedag', body: 'De school is vrijdag dicht.', translation: TRANSLATION }

test('a translated title shows in the reader\'s language and the original shows title and body', () => {
	const html = renderToStaticMarkup(createElement(NewsItem, { item: ITEM, t, locale: 'en' }))

	assert.match(html, /<h3 class="portaliq-news__title" lang="ar">يوم دراسي<\/h3>/)
	assert.equal((html.match(/portaliq-ai-notice"/g) || []).length, 1, 'one notice covers title and body')
	assert.match(html, /Translated by AI from Dutch/)
	assert.match(
		html,
		/<blockquote id="portaliq-original-news-n1" class="portaliq-translated__original" lang="nl" hidden=""><p class="portaliq-translated__original-title">Studiedag<\/p><p>De school is vrijdag dicht\.<\/p><\/blockquote>/,
	)
})

test('a translation without a title keeps the title as written and the original as before', () => {
	const item = { ...ITEM, translation: { ...TRANSLATION, title: undefined } }
	const html = renderToStaticMarkup(createElement(NewsItem, { item, t, locale: 'en' }))

	assert.match(html, /<h3 class="portaliq-news__title">Studiedag<\/h3>/)
	assert.match(html, /<blockquote id="portaliq-original-news-n1" class="portaliq-translated__original" lang="nl" hidden="">De school is vrijdag dicht\.<\/blockquote>/)
})

test('an unlabelled translation shows neither the translated title nor a notice', () => {
	const item = { ...ITEM, translation: { ...TRANSLATION, translatedByAi: false } }
	const html = renderToStaticMarkup(createElement(NewsItem, { item, t, locale: 'en' }))

	assert.match(html, /<h3 class="portaliq-news__title">Studiedag<\/h3>/)
	assert.doesNotMatch(html, /portaliq-ai-notice/)
})

test('the newsletter archive renders its items as the News page does, with its own ids', () => {
	const archive = [
		{ id: 'nl1', title: 'Nieuwsbrief september', sentAt: '2026-09-15T00:00:00+00:00', items: [ITEM] },
		{ id: 'nl2', title: 'Nieuwsbrief juni', sentAt: '2026-06-15T00:00:00+00:00', items: [] },
	]
	const html = renderToStaticMarkup(createElement(NewsletterArchive, { archive, t, locale: 'en' }))

	assert.match(html, /<h2 id="portaliq-news-archive-heading">Newsletters<\/h2>/)
	assert.match(html, /<h3 class="portaliq-newsletter__title">Nieuwsbrief september<\/h3>/)
	assert.match(html, /<h4 class="portaliq-news__title" lang="ar">يوم دراسي<\/h4>/)
	assert.match(html, /Translated by AI from Dutch/)
	assert.match(html, /aria-controls="portaliq-original-newsletter-nl1-n1"/)
	assert.match(html, /This newsletter has no items for you\./)
})

test('an empty archive renders nothing', () => {
	assert.equal(hasArchive([]), false)
	assert.equal(hasArchive(null), false)
	assert.equal(renderToStaticMarkup(createElement(NewsletterArchive, { archive: [], t, locale: 'en' })), '')
})

test('the News page reads the archive through the API and reloads it with the language', () => {
	const api = readFileSync(join(ROOT, 'src', 'portal', 'lib', 'portalApi.js'), 'utf8')
	assert.match(api, /async fetchNewsletterArchive\(\)[\s\S]*\/api\/newsletters\/archive/)
	const page = readFileSync(join(ROOT, 'src', 'portal', 'components', 'NewsPage.jsx'), 'utf8')
	assert.match(page, /api\.fetchNewsletterArchive\(\)/)
	assert.match(page, /<NewsletterArchive/)
})

test('every new SPA string has a Dutch value and the check runs with the specs', () => {
	const en = JSON.parse(readFileSync(join(ROOT, 'src', 'portal', 'i18n', 'en.json'), 'utf8'))
	const nl = JSON.parse(readFileSync(join(ROOT, 'src', 'portal', 'i18n', 'nl.json'), 'utf8'))
	for (const key of ['Newsletters', 'This newsletter has no items for you.']) {
		assert.equal(en[key], key, `en identity for ${key}`)
		assert.ok(nl[key] && nl[key] !== '', `nl value for ${key}`)
	}
	const pkg = JSON.parse(readFileSync(join(ROOT, 'package.json'), 'utf8'))
	assert.match(pkg.scripts['check:specs'], /check:news-title-and-newsletter-translation/)
})
