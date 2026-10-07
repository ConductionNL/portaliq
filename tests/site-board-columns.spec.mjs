#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-board-columns.spec.mjs: the third pass over the Zuiddrecht boards.
// The page, band and header columns, the content type size and the e-mail
// prompt's padding are each read from a token WITH today's measure as the
// fallback, so a set that names none of them renders as before; the rest
// of the pass is scoped to a portal with the designed header. Two new
// drawn options (a tinted link list, an outlined sign-in card) leave every
// placement that does not ask for them unchanged.
//
// Usage:
//   node --test tests/site-board-columns.spec.mjs
//
// @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { renderSfc } from './support/render-sfc.mjs'

const root = new URL('../', import.meta.url)
const read = (path) => readFileSync(new URL(path, root), 'utf8')
const css = read('css/site-theme.css')
const site = JSON.parse(read('lib/Settings/sites/zuiddrecht.json'))

/**
 * One page of the Zuiddrecht declaration.
 *
 * @param {string} route The page's route.
 * @return {object} The page.
 */
function page(route) {
	return site.pages.find((entry) => entry.route === route)
}

/**
 * One placement on a page of the Zuiddrecht declaration.
 *
 * @param {string} route The page's route.
 * @param {string} id    The placement's id.
 * @return {object} The placement.
 */
function widget(route, id) {
	return page(route).body.widgets.find((entry) => entry.id === id)
}

/**
 * Every `var(--name ...)` in a text, with whether it names a fallback.
 *
 * @param {string} text The stylesheet.
 * @param {string} name The custom property.
 * @return {Array<boolean>} One entry per use: true when it has a fallback.
 */
function usesOf(text, name) {
	const pattern = new RegExp(`var\\(\\s*${name}\\s*([,)])`, 'g')
	return [...text.matchAll(pattern)].map((match) => match[1] === ',')
}

test('every new column and type token is read with a fallback', () => {
	for (const name of [
		'--nldesign-website-page-max-width',
		'--nldesign-website-band-max-width',
		'--nldesign-website-page-gutter',
		'--nldesign-website-header-max-width',
		'--nldesign-website-header-gutter',
		'--nldesign-website-content-font-size',
	]) {
		const uses = usesOf(css, name)
		assert.ok(uses.length > 0, `${name} is read`)
		assert.ok(
			uses.every(Boolean),
			`${name} always names today's measure as its fallback`,
		)
	}
})

test("without the tokens the header keeps today's 1232px column and 16px room", () => {
	assert.match(
		css,
		/calc\(\(100% - var\(--nldesign-website-header-max-width, 1232px\)\) \/ 2\)/,
	)
	assert.match(
		css,
		/max-inline-size: var\(--nldesign-website-header-max-width, 1232px\)/,
	)
	assert.match(css, /var\(--nldesign-website-header-gutter, 16px\)/)
	assert.match(css, /var\(--nldesign-website-page-max-width, 1200px\)/)
})

test('the e-mail prompt has room inside it on a set that names no alert padding', () => {
	const prompt = read('src/site/components/e/ContactPrompt.vue')
	for (const side of ['block-start', 'block-end', 'inline-start', 'inline-end']) {
		assert.match(
			prompt,
			new RegExp(`var\\(--utrecht-alert-padding-${side}, \\d+px\\)`),
			`the ${side} padding reads the set's token first`,
		)
	}
	assert.match(
		css,
		/\.pq-site:has\(\.pq-site__header--designed\) \.pq-contact-prompt \{\s*display: flex;/,
		'the one-line prompt is the designed header only',
	)
})

test('a link list may draw tinted, and an unknown display stays plain', async () => {
	const tinted = await renderSfc('src/site/widgets/nlLinkList/NlLinkList.vue', {
		heading: 'Zelf iets opvragen?',
		display: 'tinted',
		links: [{ label: 'Woo-verzoek indienen', href: '/mijn' }],
	})
	assert.match(tinted, /nl-link-list--tinted/)
	const card = await renderSfc('src/site/widgets/nlLinkList/NlLinkList.vue', {
		display: 'card',
		links: [{ label: 'A', href: '/a' }],
	})
	assert.doesNotMatch(card, /nl-link-list--tinted/)
})

test('a sign-in card may be outlined, with a primary button; the default stays inverse', async () => {
	const outline = await renderSfc('src/site/widgets/nlSignIn/NlSignIn.vue', {
		heading: 'Bewaren of volgen',
		display: 'card',
		tone: 'outline',
		buttonLabel: 'Inloggen en bewaren',
	})
	assert.match(outline, /nl-signin-card--outline/)
	assert.match(outline, /utrecht-button--primary-action/)
	const plain = await renderSfc('src/site/widgets/nlSignIn/NlSignIn.vue', {
		heading: 'Mijn Zuiddrecht',
		display: 'card',
		buttonLabel: 'Inloggen met DigiD',
	})
	assert.match(plain, /nl-signin-card--inverse/)
	const odd = await renderSfc('src/site/widgets/nlSignIn/NlSignIn.vue', {
		heading: 'X',
		display: 'card',
		tone: 'neon',
		buttonLabel: 'Y',
	})
	assert.match(odd, /nl-signin-card--inverse/, 'an unknown tone is inverse')
})

test('the Zuiddrecht declaration stacks the side cards and leaves no empty rows', () => {
	const signIn = widget('/', 'zd-home-05-nlsignin')
	const links = widget('/', 'zd-home-06-nllinklist')
	assert.equal(links.gridY, signIn.gridY + signIn.gridHeight)
	const content = page('/afval')
		.body.widgets.filter((entry) => entry.gridX === 0)
		.sort((a, b) => a.gridY - b.gridY)
	for (let i = 1; i < content.length; i++) {
		const previous = content[i - 1]
		assert.equal(
			content[i].gridY,
			previous.gridY + previous.gridHeight,
			`${content[i].id} follows ${previous.id} without an empty row`,
		)
	}
	assert.equal(
		widget('/publicatie', 'zd-publicatie-02-nlsignin').props.tone,
		'outline',
	)
	assert.equal(
		widget('/publicatie', 'zd-publicatie-03-nllinklist').props.display,
		'tinted',
	)
})
