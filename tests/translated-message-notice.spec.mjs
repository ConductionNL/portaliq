#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// translated-message-notice.spec.mjs: a guardian reads a school message that
// AI translated, sees that AI made it and from which language, and reaches the
// original in one step (decision D24). Runs against the site's Vue port
// (site-reaches-portal-parity REQ-SRP-034).
//
// Usage:
//   node --test tests/translated-message-notice.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { t } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'
import {
	isLabelledTranslation,
	languageLabel,
	MESSAGE_LANGUAGES,
	noticeText,
	pickerLabel,
} from '../src/site/pages/inbox/translation.js'
import { withStrings } from '../src/site/pages/inbox/translate.js'
import strings from '../src/site/pages/inbox/strings.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const TranslatedText = await loadSfc('src/site/components/inbox/TranslatedText.vue')

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

/**
 * @param {object} props The props.
 * @return {Promise<string>} The HTML.
 */
function render(props) {
	return renderComponent(TranslatedText, { text: 'De school is morgen dicht.', translation: TRANSLATION, t, locale: 'en', id: 'm1', ...props })
}

test('a translated message shows the translation, then an aside landmark naming AI and the source language', async () => {
	const html = await render({})
	assert.match(html, /<p class="utrecht-paragraph pq-message__body" lang="ar">المدرسة مغلقة غدًا\.<\/p>/)
	assert.match(html, /<aside class="pq-ai-notice" aria-label="AI translation">/)
	assert.match(html, /Translated by AI from Dutch/)
	assert.match(html, /<span class="pq-ai-notice__mark" aria-hidden="true">AI<\/span>/, 'a visible mark, not colour alone')
	assert.match(html, /<span class="pq-ai-notice__disclosure" lang="ar">تُرجم/)
	assert.ok(html.indexOf('lang="ar">المدرسة') < html.indexOf('<aside'), 'translation first, notice after it')
})

test('the original is one button away, wired with aria-expanded and aria-controls, and marked with its language', async () => {
	const closed = await render({})
	assert.match(closed, /aria-expanded="false" aria-controls="portaliq-original-m1">Show the original text<\/button>/)
	assert.match(closed, /<blockquote id="portaliq-original-m1" class="pq-translated__original" lang="nl" hidden>(<!--\[-->)?De school is morgen dicht\./)

	const open = await render({ defaultOpen: true })
	assert.match(open, /aria-expanded="true"[^>]*>Hide the original text<\/button>/)
	assert.match(open, /<blockquote id="portaliq-original-m1" class="pq-translated__original" lang="nl">(<!--\[-->)?De school/)
})

test('a message without a labelled translation renders as written with no notice', async () => {
	for (const translation of [null, { ...TRANSLATION, translatedByAi: undefined }, { ...TRANSLATION, text: '' }]) {
		const html = await render({ translation })
		assert.equal(html, '<p class="utrecht-paragraph pq-message__body">De school is morgen dicht.</p>')
		assert.equal(isLabelledTranslation(translation), false)
	}
})

test('an undetermined source language is not named', async () => {
	assert.equal(noticeText({ ...TRANSLATION, sourceLanguage: 'und' }, t, 'en'), 'Translated by AI')
	const html = await render({ text: 'x', translation: { ...TRANSLATION, sourceLanguage: 'und' }, id: 'm2' })
	assert.doesNotMatch(html, /blockquote[^>]*lang=/, 'the original carries no invented language')
})

test('the language is named in the page language, with the tag as a fallback', () => {
	assert.equal(languageLabel('nl', 'en'), 'Dutch')
	assert.equal(languageLabel('nl', 'nl'), 'Nederlands')
	assert.equal(languageLabel('', 'en'), '')
	assert.equal(pickerLabel('ar', 'en'), 'Arabic (العربية)')
	assert.equal(pickerLabel('en', 'en'), 'English')
	assert.ok(MESSAGE_LANGUAGES.includes('ar') && MESSAGE_LANGUAGES.includes('tr'))
})

test('the Dutch notice reads naturally', () => {
	const nl = withStrings(null, 'nl')
	assert.equal(noticeText(TRANSLATION, nl, 'nl'), 'Door AI vertaald uit het Nederlands')
	assert.equal(nl('Show the original text'), 'Toon de oorspronkelijke tekst')
	assert.equal(nl('AI translation'), 'AI-vertaling')
})

test('every string has a Dutch value', () => {
	for (const key of ['AI translation', 'Translated by AI', 'Translated by AI from {language}', 'Show the original text', 'Hide the original text', 'Messages', 'Show messages in', 'As written']) {
		assert.equal(strings.en[key], key, `en identity for ${key}`)
		assert.ok(strings.nl[key] && strings.nl[key] !== key, `nl value for ${key}`)
	}
})

test('the inbox renders a translated row through the same notice', async () => {
	const html = await render({ text: 'Uw aanvraag is ontvangen.', translation: { ...TRANSLATION, text: 'تم استلام طلبك.' }, id: 'row-1', bodyClass: 'utrecht-paragraph pq-inbox-row__body' })
	assert.match(html, /<p class="utrecht-paragraph pq-inbox-row__body" lang="ar">تم استلام طلبك\.<\/p>/)
	assert.match(html, /Translated by AI from Dutch/)
	const source = readFileSync(join(ROOT, 'src', 'site', 'pages', 'inbox', 'InboxPage.vue'), 'utf8')
	assert.match(source, /<TranslatedText[\s\S]*:translation="message\.translation \|\| null"/, 'InboxPage passes the row translation through')
})
