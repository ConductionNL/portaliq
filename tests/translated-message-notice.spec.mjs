#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// translated-message-notice.spec.mjs: a guardian reads a school message that
// AI translated, sees that AI made it and from which language, and reaches the
// original in one step (decision D24).
//
// Usage:
//   node --test tests/translated-message-notice.spec.mjs

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

const translated = await load('components/TranslatedText.jsx')
const { default: TranslatedText, languageLabel, noticeText, isLabelledTranslation } = translated
const { default: InboxPage } = await load('components/InboxPage.jsx')
const { pickerLabel, MESSAGE_LANGUAGES } = await load('components/MessagesPage.jsx')

/**
 * A translator over one of the portal's own bundles, the way i18n/index.js
 * builds it (that module imports JSON, which plain node cannot).
 *
 * @param {string} locale - 'en' or 'nl'
 * @return {Function} t(key, vars)
 */
function bundleTranslator(locale) {
	const bundle = JSON.parse(readFileSync(join(ROOT, 'src', 'portal', 'i18n', `${locale}.json`), 'utf8'))
	return (key, vars) => {
		let text = bundle[key] || key
		for (const [name, value] of Object.entries(vars || {})) {
			text = text.replace(`{${name}}`, String(value))
		}
		return text
	}
}

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
	text: 'المدرسة مغلقة غدًا.',
	translatedByAi: true,
	sourceLanguage: 'nl',
	sourceLanguageDetected: true,
	model: 'nextcloud',
	originalRef: 'portaliq:guardianMessage:m1',
	disclosure: 'تُرجم بواسطة الذكاء الاصطناعي.',
	disclosureLanguage: 'ar',
}

test('a translated message shows the translation, then an aside landmark naming AI and the source language', () => {
	const html = renderToStaticMarkup(createElement(TranslatedText, { text: 'De school is morgen dicht.', translation: TRANSLATION, t, locale: 'en', id: 'm1' }))

	assert.match(html, /<p class="portaliq-message__body" lang="ar">المدرسة مغلقة غدًا\.<\/p>/)
	assert.match(html, /<aside class="portaliq-ai-notice" aria-label="AI translation">/)
	assert.match(html, /Translated by AI from Dutch/)
	assert.match(html, /<span class="portaliq-ai-notice__mark" aria-hidden="true">AI<\/span>/, 'a visible mark, not colour alone')
	assert.ok(html.indexOf('lang="ar">المدرسة') < html.indexOf('<aside'), 'translation first, notice after it')
})

test('the original is one button away, wired with aria-expanded and aria-controls, and marked with its language', () => {
	const closed = renderToStaticMarkup(createElement(TranslatedText, { text: 'De school is morgen dicht.', translation: TRANSLATION, t, locale: 'en', id: 'm1' }))
	assert.match(closed, /<button type="button" class="portaliq-ai-notice__toggle" aria-expanded="false" aria-controls="portaliq-original-m1">Show the original text<\/button>/)
	assert.match(closed, /<blockquote id="portaliq-original-m1" class="portaliq-translated__original" lang="nl" hidden="">De school is morgen dicht\.<\/blockquote>/)

	const open = renderToStaticMarkup(createElement(TranslatedText, { text: 'De school is morgen dicht.', translation: TRANSLATION, t, locale: 'en', id: 'm1', defaultOpen: true }))
	assert.match(open, /aria-expanded="true"[^>]*>Hide the original text<\/button>/)
	assert.match(open, /<blockquote id="portaliq-original-m1" class="portaliq-translated__original" lang="nl">De school/)
})

test('a message without a labelled translation renders as written with no notice', () => {
	for (const translation of [undefined, { ...TRANSLATION, translatedByAi: undefined }, { ...TRANSLATION, text: '' }]) {
		const html = renderToStaticMarkup(createElement(TranslatedText, { text: 'De school is morgen dicht.', translation, t, locale: 'en', id: 'm1' }))
		assert.equal(html, '<p class="portaliq-message__body">De school is morgen dicht.</p>')
		assert.equal(isLabelledTranslation(translation), false)
	}
})

test('an undetermined source language is not named', () => {
	assert.equal(noticeText({ ...TRANSLATION, sourceLanguage: 'und' }, t, 'en'), 'Translated by AI')
	const html = renderToStaticMarkup(createElement(TranslatedText, { text: 'x', translation: { ...TRANSLATION, sourceLanguage: 'und' }, t, locale: 'en', id: 'm2' }))
	assert.doesNotMatch(html, /blockquote[^>]*lang=/, 'the original carries no invented language')
})

test('the language is named in the portal language, with the tag as a fallback', () => {
	assert.equal(languageLabel('nl', 'en'), 'Dutch')
	assert.equal(languageLabel('nl', 'nl'), 'Nederlands')
	assert.equal(languageLabel('', 'en'), '')
	assert.equal(pickerLabel('ar', 'en'), 'Arabic (العربية)')
	assert.equal(pickerLabel('en', 'en'), 'English')
	assert.ok(MESSAGE_LANGUAGES.includes('ar') && MESSAGE_LANGUAGES.includes('tr'))
})

test('the Dutch notice reads naturally', () => {
	const nl = bundleTranslator('nl')
	assert.equal(noticeText(TRANSLATION, nl, 'nl'), 'Door AI vertaald uit het Nederlands')
	assert.equal(nl('Show the original text'), 'Toon de oorspronkelijke tekst')
	assert.equal(nl('AI translation'), 'AI-vertaling')
})

test('every new SPA string has a Dutch value', () => {
	const en = JSON.parse(readFileSync(join(ROOT, 'src', 'portal', 'i18n', 'en.json'), 'utf8'))
	const nl = JSON.parse(readFileSync(join(ROOT, 'src', 'portal', 'i18n', 'nl.json'), 'utf8'))
	for (const key of ['AI translation', 'Translated by AI', 'Translated by AI from {language}', 'Show the original text', 'Hide the original text', 'Messages', 'Show messages in', 'As written']) {
		assert.equal(en[key], key, `en identity for ${key}`)
		assert.ok(nl[key] && nl[key] !== '', `nl value for ${key}`)
	}
})

test('the inbox renders a translated row through the same notice', async () => {
	const { useState } = require('react')
	assert.ok(useState)
	const html = renderToStaticMarkup(createElement(TranslatedText, {
		text: 'Uw aanvraag is ontvangen.',
		translation: { ...TRANSLATION, text: 'تم استلام طلبك.' },
		t,
		locale: 'en',
		id: 'row-1',
		bodyClassName: 'portaliq-inbox-row__body',
	}))
	assert.match(html, /<p class="portaliq-inbox-row__body" lang="ar">تم استلام طلبك\.<\/p>/)
	assert.match(html, /Translated by AI from Dutch/)
	assert.equal(typeof InboxPage, 'function')
	const source = readFileSync(join(ROOT, 'src', 'portal', 'components', 'InboxPage.jsx'), 'utf8')
	assert.match(source, /<TranslatedText[\s\S]*translation=\{message\.translation\}/, 'InboxPage passes the row translation through')
})
