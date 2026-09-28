#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// newsletter-title-translation.spec.mjs: a newsletter's own title shows in the
// reader's language under the same AI notice a news item has, with the button
// that shows the original title (decision D24, newsletter-title-translation).
//
// Usage:
//   node --test tests/newsletter-title-translation.spec.mjs

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
const OUT_DIR = join(ROOT, 'node_modules', '.cache', 'portaliq-tests-newsletter-title')

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
const { NewsletterArchive } = await load('components/NewsPage.jsx')

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

const NEWSLETTER_TRANSLATION = {
	targetLanguage: 'ar',
	text: 'نشرة سبتمبر',
	title: 'نشرة سبتمبر',
	translatedByAi: true,
	sourceLanguage: 'nl',
	originalRef: 'portaliq:newsletter:nl1',
}

test('a translated newsletter title shows in the reader\'s language with the notice and the original one click away', () => {
	const archive = [{ id: 'nl1', title: 'Nieuwsbrief september', items: [], translation: NEWSLETTER_TRANSLATION }]
	const html = renderToStaticMarkup(createElement(NewsletterArchive, { archive, t, locale: 'en' }))

	assert.match(html, /<h3 class="portaliq-newsletter__title" lang="ar">نشرة سبتمبر<\/h3>/)
	assert.equal((html.match(/portaliq-ai-notice"/g) || []).length, 1, 'one notice for the title')
	assert.match(html, /Translated by AI from Dutch/)
	assert.match(html, /aria-controls="portaliq-original-newsletter-nl1"/)
	assert.match(html, /Show the original text/)
	assert.match(html, /<blockquote id="portaliq-original-newsletter-nl1" class="portaliq-translated__original" lang="nl" hidden="">Nieuwsbrief september<\/blockquote>/)
})

test('a newsletter without a labelled translation keeps its title as written and shows no notice', () => {
	for (const translation of [undefined, { ...NEWSLETTER_TRANSLATION, translatedByAi: false }]) {
		const archive = [{ id: 'nl1', title: 'Nieuwsbrief september', items: [], translation }]
		const html = renderToStaticMarkup(createElement(NewsletterArchive, { archive, t, locale: 'en' }))

		assert.match(html, /<h3 class="portaliq-newsletter__title">Nieuwsbrief september<\/h3>/)
		assert.doesNotMatch(html, /portaliq-ai-notice/)
	}
})

test('the check runs with the specs', () => {
	const pkg = JSON.parse(readFileSync(join(ROOT, 'package.json'), 'utf8'))
	assert.match(pkg.scripts['check:specs'], /check:newsletter-title-translation/)
	assert.equal(pkg.scripts['check:newsletter-title-translation'], 'node --test tests/newsletter-title-translation.spec.mjs')
})
