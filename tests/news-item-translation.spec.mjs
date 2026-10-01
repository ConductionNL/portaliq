#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// news-item-translation.spec.mjs: a guardian reads a school news item that AI
// translated, sees the same notice a translated message shows, and reaches the
// original in one step (decision D24, news-item-translation).
//
// Usage:
//   node --test tests/news-item-translation.spec.mjs

import assert from 'node:assert/strict'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { buildNav, shellSections } from '../src/shared/portalNav.js'

const require = createRequire(import.meta.url)
const babel = require('@babel/core')
const { createElement } = require('react')
const { renderToStaticMarkup } = require('react-dom/server')

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const OUT_DIR = join(ROOT, 'node_modules', '.cache', 'portaliq-tests')

/**
 * Compile one portal source file (and the local components it imports) with
 * the portal build's React preset and import it from where `react` resolves.
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
const { NewsItem, hasNews } = await load('components/NewsPage.jsx')

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

test('a translated news item shows the translation, the AI notice naming Dutch, and the original one button away', () => {
	const html = renderToStaticMarkup(createElement(NewsItem, { item: ITEM, t, locale: 'en' }))

	assert.match(html, /<h3 class="portaliq-news__title">Studiedag<\/h3>/)
	assert.match(html, /<p class="portaliq-news__body" lang="ar">المدرسة مغلقة يوم الجمعة\.<\/p>/)
	assert.match(html, /<aside class="portaliq-ai-notice" aria-label="AI translation">/)
	assert.match(html, /Translated by AI from Dutch/)
	assert.match(html, /aria-expanded="false" aria-controls="portaliq-original-news-n1">Show the original text<\/button>/)
	assert.match(html, /<blockquote id="portaliq-original-news-n1" class="portaliq-translated__original" lang="nl" hidden="">De school is vrijdag dicht\.<\/blockquote>/)
})

test('a news item without a labelled translation renders as written with no notice', () => {
	for (const translation of [undefined, { ...ITEM.translation, translatedByAi: false }]) {
		const html = renderToStaticMarkup(createElement(NewsItem, { item: { ...ITEM, translation }, t, locale: 'en' }))
		assert.match(html, /<p class="portaliq-news__body">De school is vrijdag dicht\.<\/p>/)
		assert.doesNotMatch(html, /portaliq-ai-notice/)
	}
})

test('the news page appears only when the feed holds an item', () => {
	assert.equal(hasNews([]), false)
	assert.equal(hasNews(null), false)
	assert.equal(hasNews([ITEM]), true)
	const app = readFileSync(join(ROOT, 'src', 'portal', 'App.jsx'), 'utf8')
	assert.match(app, /buildNav\(state\.contributions\?\.contributions, t, shellSections\(state\)\)/)
	assert.equal(shellSections({ news: [ITEM] }).news, true)
	assert.equal(shellSections({ news: [] }).news, false)
	assert.match(app, /<NewsPage/)
	// The shared navigation offers News only when the feed holds an item.
	assert.ok(buildNav([], (key) => key, { news: true }).some((entry) => entry.special === 'news'))
	assert.ok(!buildNav([], (key) => key, { news: false }).some((entry) => entry.special === 'news'))
	const api = readFileSync(join(ROOT, 'src', 'shared', 'portalApi.js'), 'utf8')
	assert.match(api, /async fetchNewsFeed\(\)[\s\S]*\/api\/news\/feed/)
})

test('the news page carries the same language picker as the messages page', () => {
	const source = readFileSync(join(ROOT, 'src', 'portal', 'components', 'NewsPage.jsx'), 'utf8')
	assert.match(source, /import \{ MessageLanguagePicker \} from '\.\/MessagesPage\.jsx'/)
	assert.match(source, /<MessageLanguagePicker/)
	const messages = readFileSync(join(ROOT, 'src', 'portal', 'components', 'MessagesPage.jsx'), 'utf8')
	assert.match(messages, /<MessageLanguagePicker/)
})

test('newsItem declares translations and the register moved', () => {
	const register = JSON.parse(readFileSync(join(ROOT, 'lib', 'Settings', 'portaliq_register.json'), 'utf8'))
	const newsItem = register.components.schemas.newsItem
	assert.ok(['0.2.0', '0.2.1'].includes(newsItem.version), `newsItem ${newsItem.version}`)
	assert.equal(newsItem.properties.translations.type, 'array')
	assert.equal(register.info.version, register.components.registers.portaliq.version)
})

test('every new SPA string has a Dutch value', () => {
	const en = JSON.parse(readFileSync(join(ROOT, 'src', 'shared', 'i18n', 'en.json'), 'utf8'))
	const nl = JSON.parse(readFileSync(join(ROOT, 'src', 'shared', 'i18n', 'nl.json'), 'utf8'))
	for (const key of ['News', 'No news yet.', 'News from school is translated by AI into your language. You can always see the original text.']) {
		assert.equal(en[key], key, `en identity for ${key}`)
		assert.ok(nl[key] && nl[key] !== '', `nl value for ${key}`)
	}
})
