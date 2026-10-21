#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// account-page-sections.spec.mjs: "Mijn account" in the order and look of the
// MijnAccount board. A lead under the title; the name's label above its field;
// "Contactvoorkeuren" as a section with the channel question as its legend and
// the choices in the board's order, drawn as NL Design System radio buttons;
// the code from a letter on the grey surface; removing the account under a
// rule, its sentence before its button and the button in the error colour.
//
// Usage:
//   node --test tests/site-look/account-page-sections.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { renderSfc } from '../support/render-sfc.mjs'

const PAGE = 'src/site/pages/e/AccountPage.vue'
const DETAILS = {
	displayName: 'Sanne de Vries',
	contactAddresses: [],
	contactChannel: 'email',
}

/**
 * The style block of a component, whitespace collapsed.
 *
 * @param {string} file The component.
 * @return {string} The CSS.
 */
function style(file) {
	const source = readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8')
	return source.slice(source.indexOf('<style')).replace(/\s+/g, ' ')
}

/**
 * Render the page.
 *
 * @return {Promise<string>} The HTML.
 */
function render() {
	return renderSfc(PAGE, { api: {}, t: (key) => key, initialDetails: DETAILS })
}

test('a lead under the title says what the page is for', async () => {
	assert.match(
		await render(),
		/<\/h1><p class="utrecht-paragraph pq-account__lead">How you are signed in, how we reach you and what we let you know about\.<\/p>/,
	)
})

test('contact preferences: an h2, the question as legend, the choices in the board order as radios', async () => {
	const html = await render()
	assert.match(
		html,
		/<h2 id="pq-account-preferences"[^>]*>Contact preferences<\/h2>/,
	)
	const order = [...html.matchAll(/data-testid="account-channel-([a-z]+)"/g)].map(
		(m) => m[1],
	)
	assert.deepEqual(order, ['email', 'phone', 'post', 'portal'])
	assert.match(
		html,
		/class="utrecht-radio-button utrecht-radio-button--html-input"[^>]*value="email" checked/,
	)
	assert.match(style(PAGE), /\.pq-account__radio \{[^}]*font-weight: normal/)
})

test('the name label stands above its field', () => {
	assert.match(style(PAGE), /\.pq-account__name-label \{ flex-basis: 100%; \}/)
})

test('removing the account: under a rule, the sentence first, the button in the error colour', async () => {
	const html = await render()
	assert.match(
		html,
		/<h2 id="pq-account-remove"[^>]*>Remove my account<\/h2><p[^>]*>Your portal account is removed\. Your cases stay with the organisation\.<\/p><button[^>]*pq-account__danger/,
	)
	const css = style(PAGE)
	assert.match(css, /\.pq-account__remove \{[^}]*border-block-start: 1px solid/)
	assert.match(
		css,
		/\.pq-account__danger \{[^}]*color: var\(\s*--nldesign-color-accent-text/,
	)
})

test('the code from a letter stands on the grey surface', () => {
	assert.match(
		style('src/site/components/e/InvitationCodeForm.vue'),
		/\.pq-account__code \{[^}]*background: var\(--nldesign-color-surface/,
	)
})
