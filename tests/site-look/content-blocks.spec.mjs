#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// content-blocks.spec.mjs: a melding is a card and a link list is a named
// landmark with targets you can hit, on any set (site-content-blocks-styled).
//
// The Utrecht alert reads every measure from tokens that no shipped set
// declares. These tests read `css/site-theme.css` for the rules that supply
// them from the set's own `--nldesign-*` tokens, and render the link list,
// whose markup changed. Removing a rule, giving it a literal colour, or
// putting back an unnamed landmark fails a test.
//
// Usage:
//   node --test tests/site-look/content-blocks.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { loadSfc } from '../support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const css = readFileSync(join(ROOT, 'css/site-theme.css'), 'utf8').replace(
	/\/\*[\s\S]*?\*\//g,
	'',
)

/**
 * The declarations of the LAST rule with exactly this selector.
 *
 * @param {string} selector The selector, as written in the sheet.
 * @return {string} The declarations, whitespace collapsed.
 */
function rule(selector) {
	let found = null
	for (const match of css.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
		if (match[1].replace(/\s+/g, ' ').trim() === selector) {
			found = match[2].replace(/\s+/g, ' ').trim()
		}
	}
	assert.ok(found !== null, `site-theme.css has a rule for ${selector}`)
	return found
}

const LITERAL_COLOUR = /#[0-9a-f]{3,8}\b|\brgba?\(|\bhsla?\(/i

test('a melding is a card with room inside and the tint of its kind', () => {
	assert.match(
		rule('.pq-site .utrecht-alert'),
		/padding-block: var\(--utrecht-alert-padding-block-start, 24px\)/,
	)
	const tints = {
		info: '--nldesign-color-primary-light',
		ok: '--nldesign-component-status-badge-success-background-color',
		warning: '--nldesign-component-status-badge-warning-background-color',
		error: '--nldesign-component-status-badge-error-background-color',
	}
	for (const [kind, token] of Object.entries(tints)) {
		assert.match(
			rule(`.pq-site .utrecht-alert--${kind}`),
			new RegExp(
				`--utrecht-alert-${kind}-background-color, var\\(${token}, transparent\\)`,
			),
			`a ${kind} melding reads its Utrecht token, then ${token}`,
		)
	}
	assert.match(
		rule('.pq-site .utrecht-alert__content'),
		/gap: var\(--utrecht-alert-message-row-gap, 12px\)/,
	)
})

test('a link in a list is a target of at least 24px', () => {
	assert.match(
		rule('.pq-site .utrecht-link-list__link'),
		/min-block-size: var\(--utrecht-link-list-link-min-block-size, 24px\)/,
	)
})

test('the new rules hold no literal colour', () => {
	const start = css.indexOf('.pq-site .utrecht-alert {')
	const end = css.indexOf('.pq-site .utrecht-link-list__link {')
	assert.ok(start > 0 && end > start)
	assert.doesNotMatch(css.slice(start, end), LITERAL_COLOUR)
})

test('a link list with a heading is a landmark named by it, with an id of its own', async () => {
	const NlLinkList = await loadSfc('src/site/widgets/nlLinkList/NlLinkList.vue')
	const { createSSRApp, h } = await import('vue')
	const { renderToString } = await import('vue/server-renderer')
	const links = [{ label: 'Schooltijden', href: '/praktisch/schooltijden' }]
	const html = await renderToString(
		createSSRApp({
			render: () => [
				h(NlLinkList, { heading: 'Over onze school', links }),
				h(NlLinkList, { heading: 'Praktisch', links }),
				h(NlLinkList, { links }),
			],
		}),
	)
	const navs = [
		...html.matchAll(
			/<nav class="utrecht-link-list-nav" aria-labelledby="([^"]+)"/g,
		),
	].map((m) => m[1])
	assert.equal(navs.length, 2, 'two lists with a heading are two landmarks')
	assert.notEqual(navs[0], navs[1], 'each landmark has its own name')
	for (const id of navs) {
		assert.match(html, new RegExp(`<h2 id="${id}" class="utrecht-heading-3">`))
	}
	assert.match(
		html,
		/<div class="utrecht-link-list-nav" data-testid="nl-link-list">/,
		'a list without a heading is no landmark',
	)
})
