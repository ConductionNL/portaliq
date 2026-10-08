#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// widget-tokens.spec.mjs: a widget that styles itself does so from
// `--utrecht-*` tokens alone (site-nlds-widget-palette REQ-SNW-010's second
// scenario, design D5).
//
// WHAT THIS CATCHES THAT READING THE CODE WOULD NOT. A literal colour in a
// widget's stylesheet is invisible in review and on every instance whose theme
// happens to look like it: the widget renders, the colours look plausible, and
// nothing is wrong until a portal with a different set opens the page, where
// the hard-coded value stays put while everything around it changes. The
// thematiq round of 2 October is the same failure from the other side: a site
// title went white on white because one value came from somewhere other than
// the portal's own tokens, and it took a live screenshot to see. A test over
// the stylesheets fails at the moment the literal is written instead.
//
// It also catches the subtler case a reader is most likely to wave through: a
// `var(--utrecht-...)` with a LITERAL FALLBACK, `var(--utrecht-x, #0a3d62)`.
// That reads as token-driven and renders the hex on any portal that does not
// define the token, which is exactly the portal the fallback exists for.
//
// Usage:
//   node --test tests/widget-tokens.spec.mjs

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { test } from 'node:test'

/** Where the widgets live. */
const WIDGETS = new URL('../src/site/widgets/', import.meta.url)

/**
 * The stylesheet without its comments, because a comment EXPLAINING that no
 * hex may appear would otherwise be read as a hex appearing. The first version
 * of this test failed on its own prose, which is the kind of false red that
 * teaches people to ignore a check.
 *
 * @param {string} css The stylesheet.
 * @return {string} The same, with comments removed.
 */
function withoutComments(css) {
	return css.replace(/\/\*[\s\S]*?\*\//g, ' ')
}

/** Colour literals a widget's own stylesheet may not contain. */
const LITERALS = [
	{ name: 'a hex colour', pattern: /#[0-9a-f]{3,8}\b/gi },
	{ name: 'an rgb() or rgba()', pattern: /\brgba?\(/gi },
	{ name: 'an hsl() or hsla()', pattern: /\bhsla?\(/gi },
	{
		name: 'a named colour',
		// The handful an author reaches for, matched only where a VALUE could
		// stand: not inside a token's own name, so `--utrecht-color-grey-30`
		// is a token reference and `background: grey` is not. System colours
		// (Canvas, CanvasText, LinkText) are deliberately allowed: they are
		// the browser's own high-contrast-aware keywords and are what a token
		// fallback should fall back to.
		pattern:
			/(?<![-a-z0-9])(?:white|black|red|green|blue|yellow|orange|purple|grey|gray|silver|navy|teal|lime|aqua|fuchsia|maroon|olive)(?![-a-z0-9])/gi,
	},
]

/**
 * The `<style>` blocks of one widget component.
 *
 * @param {string} source The component's source.
 * @return {string} Everything inside its style blocks.
 */
function styles(source) {
	return [...source.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)]
		.map((match) => match[1])
		.join('\n')
}

/**
 * Every widget component, as `{key, name, source}`.
 *
 * @return {Array<object>} The components.
 */
function components() {
	return readdirSync(WIDGETS, { withFileTypes: true })
		.filter((entry) => entry.isDirectory())
		.map((entry) => {
			const files = readdirSync(new URL(`${entry.name}/`, WIDGETS)).filter(
				(file) => file.endsWith('.vue'),
			)
			return {
				key: entry.name,
				name: files[0],
				source: readFileSync(
					new URL(`${entry.name}/${files[0]}`, WIDGETS),
					'utf8',
				),
			}
		})
}

test('no widget stylesheet names a colour of its own', () => {
	const offences = []
	for (const { key, name, source } of components()) {
		const css = withoutComments(styles(source))
		if (css.trim() === '') {
			continue
		}

		for (const { name: what, pattern } of LITERALS) {
			for (const match of css.matchAll(pattern)) {
				offences.push(`${key}/${name}: ${what} (${match[0]})`)
			}
		}
	}

	assert.deepEqual(offences, [], offences.join('\n'))
})

test('a token fallback is another token or a system colour, never a literal', () => {
	// The case a reviewer waves through: `var(--utrecht-x, #0a3d62)` reads as
	// token-driven and renders the hex on exactly the portal the fallback
	// exists for, the one that does not define the token.
	const offences = []
	for (const { key, name, source } of components()) {
		for (const match of withoutComments(styles(source)).matchAll(
			/var\(\s*(--[a-z0-9-]+)\s*,([^)]*)\)/gi,
		)) {
			const fallback = match[2].trim()
			if (fallback === '') {
				continue
			}

			const sound =
				fallback.startsWith('var(--')
				|| /^(Canvas|CanvasText|LinkText|currentcolor|transparent|inherit|none)$/i.test(
					fallback,
				)
				|| /^[0-9.]+(rem|em|px|%|vh|vw)?$/.test(fallback)
				|| /^(solid|dashed|dotted|50%)$/.test(fallback)
			if (!sound) {
				offences.push(`${key}/${name}: var(${match[1]}, ${fallback})`)
			}
		}
	}

	assert.deepEqual(offences, [], offences.join('\n'))
})

test('the check can fail, on both halves', () => {
	// A guard that cannot fail reports the same green as one that passed, so
	// each half is run against a stylesheet written to break it.
	const withHex = '<style scoped>\n.x { color: #0a3d62; }\n</style>'
	const withName = '<style scoped>\n.x { background: white; }\n</style>'
	const withLiteralFallback =
		'<style scoped>\n.x { color: var(--utrecht-x, #0a3d62); }\n</style>'
	const sound =
		'<style scoped>\n.x { color: var(--utrecht-document-color, CanvasText); }\n</style>'

	const literalsIn = (source) =>
		LITERALS.flatMap(({ pattern }) => [
			...withoutComments(styles(source)).matchAll(pattern),
		]).length
	assert.equal(literalsIn(withHex), 1, 'a hex is caught')
	assert.equal(literalsIn(withName), 1, 'a named colour is caught')
	assert.equal(literalsIn(sound), 0, 'a token reference is not')

	const fallbacksIn = (source) =>
		[
			...withoutComments(styles(source)).matchAll(
				/var\(\s*--[a-z0-9-]+\s*,([^)]*)\)/gi,
			),
		]
			.map((match) => match[1].trim())
			.filter(
				(fallback) =>
					!(
						fallback.startsWith('var(--')
						|| /^(Canvas|CanvasText|LinkText|currentcolor|transparent)$/i.test(
							fallback,
						)
					),
			)
	assert.deepEqual(
		fallbacksIn(withLiteralFallback),
		['#0a3d62'],
		'a literal fallback is caught',
	)
	assert.deepEqual(fallbacksIn(sound), [], 'a system colour fallback is not')
})

test('every widget with its own stylesheet is one design D5 names', () => {
	// A widget that styles itself when NL Design System publishes CSS for its
	// component is a second opinion on the same pixels. D5 lists the ones with
	// no upstream CSS; anything else drawing its own box should be reviewed
	// rather than silently accepted.
	const allowed = [
		'nlBanner',
		'nlDialog',
		'nlDrawer',
		'nlProgressBar',
		'nlProgressCircle',
		'nlToggletip',
		// Wave 4. D5 lists Tabs and Task Navigation among the components with
		// no upstream CSS. `nlSignIn` draws no colour of its own at all: its
		// only rule is the row's layout, and the buttons bring their own
		// colours from `@utrecht/digid-button-css` and `button-css`.
		'nlTabs',
		'nlTaskNav',
		'nlSignIn',
		// site-school-blocks. Compositions with no single upstream component
		// (SITE_COMPOSITIONS in coverage.js), and the numbered steps of
		// `nlList`, which Utrecht's ordered list does not draw: layout and
		// theme tokens only, which the two tests above hold them to.
		'nlList',
		'nlQuickTasks',
		'nlNewsList',
		'nlNewsArticle',
		'nlEventList',
		// site-matches-the-zuiddrecht-boards. The drawn options the boards
		// ask for and Utrecht's CSS does not carry: a link list as a card or
		// under an accent line, a boxed table, a chevron in a button link, and
		// the two compositions (link columns on a band, the lookup form).
		// Layout and theme tokens only, as above.
		'nlLinkList',
		'nlTable',
		'nlButtonLink',
		'nlLinkColumns',
		'nlLookupForm',
		// portal-public-catalogue: a composition too (search, facets, cards,
		// pages); layout and theme tokens only.
		'nlCatalogue',
		// public-detail-page-for-a-provider-item: date cards and a facts grid.
		'nlPublicDetail',
		// public-faq-and-product-finder: the FAQ's group spacing and the
		// finder's chips, layout and result panel; layout and tokens only.
		'nlFaqList',
		'nlProductFinder',
	]

	for (const { key, source } of components()) {
		if (withoutComments(styles(source)).trim() === '') {
			continue
		}

		assert.ok(
			allowed.includes(key),
			`${key} draws its own styles but design D5 does not list it as one without upstream CSS`,
		)
	}
})
