#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// account-pages-language.spec.mjs: the account pages ("Toegang tot zaken",
// my cases, my details, my account and the rest of slice e) speak the page's
// language even where the shell's bundle lacks a string, and the example
// mandates read as the party's name after "U regelt nu zaken voor"
// (acting-for-and-access-page-read-in-dutch).
//
// Usage:
//   node --test tests/account-pages-language.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { h } from 'vue'
import { createSSRApp } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { pages } from '../src/site/pages/e/index.js'
import {
	accountPageTranslator,
	withAccountStrings,
} from '../src/site/pages/e/translate.js'

/** A shell translator that knows one key, as the site bundle does. */
function shell(key, vars) {
	return key === 'Ask for access'
		? 'Toegang aanvragen (shell)'
		: key.replace('{party}', vars?.party ?? '{party}')
}

test('a key the shell lacks is answered from the account strings, in the page language', () => {
	const nl = accountPageTranslator(shell, 'nl')
	assert.equal(
		nl('Mandates through DigiD Machtigen'),
		'Machtigen via DigiD Machtigen',
	)
	assert.equal(
		nl('Who may arrange matters for you, and for whom you arrange matters.'),
		'Wie zaken voor u mag regelen, en voor wie u zaken regelt.',
	)
	assert.equal(
		nl('Ask for access'),
		'Toegang aanvragen (shell)',
		'the shell wins where it knows the key',
	)
	const en = accountPageTranslator(shell, 'en-GB')
	assert.equal(
		en('Mandates through DigiD Machtigen'),
		'Mandates through DigiD Machtigen',
	)
})

test('the wrapped page gets the falling-back t and only the props it declares', async () => {
	const page = {
		name: 'Probe',
		props: {
			t: { type: Function, default: null },
			api: { type: Object, default: null },
		},
		render() {
			return h('p', this.t('Mandates through DigiD Machtigen'))
		},
	}
	const wrapped = withAccountStrings(page)
	assert.deepEqual(Object.keys(wrapped.props).sort(), ['api', 'locale', 't'])
	const html = await renderToString(
		createSSRApp(wrapped, { t: shell, locale: 'nl', api: {} }),
	)
	assert.equal(html, '<p>Machtigen via DigiD Machtigen</p>')
	assert.doesNotMatch(
		html,
		/locale=/,
		'a prop the page does not declare is not forwarded',
	)
})

test('every account page loader wraps its page', () => {
	const source = readFileSync(
		new URL('../src/site/pages/e/index.js', import.meta.url),
		'utf8',
	)
	for (const key of Object.keys(pages)) {
		assert.match(source, new RegExp(`${key}: translated\\(`), key)
	}
})

test('an example mandate is named by its party, not by a sentence', () => {
	const set = JSON.parse(
		readFileSync(
			new URL('../lib/Settings/example-sets/zuiddrecht.json', import.meta.url),
			'utf8',
		),
	)
	const labels = JSON.stringify(set).match(/"label":"[^"]*{{cast:[^"]*"/g) || []
	assert.ok(labels.length >= 3)
	for (const label of labels) {
		assert.doesNotMatch(label, /"label":"(Namens|Voor|Gemachtigd)/i, label)
	}
})
