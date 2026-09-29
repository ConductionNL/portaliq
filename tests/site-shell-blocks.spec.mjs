#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-shell-blocks.spec.mjs: the portal's header and footer as blocks
// (portal-theme-blocks-and-contributed-pages tasks 4 and 5, REQ-PTB-004,
// REQ-PTB-005, REQ-PTB-007). The blocks are rendered in plain node, with no
// DOM and no Nextcloud globals, which is itself the "mounts without
// Nextcloud" scenario.
//
// Usage:
//   node --test tests/site-shell-blocks.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { withoutStyling } from '../src/site/lib/blockProps.js'
import {
	footerContentOf,
	footerMenusOf,
	headerMenusOf,
	headerVariantOf,
	legalLinksOf,
	registerRouteOf,
	subFooterMenuOf,
} from '../src/site/lib/shellData.js'
import { renderSfc } from './support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

// The library's public entry imports `.vue` files node cannot load; the
// footer only needs the icon, which renders an aria-hidden svg.
const LIBRARY = {
	'@conduction/nextcloud-vue/public':
		"import { h } from 'vue'\nexport const CnSiteIcon = { props: ['name', 'size'], render() { return h('svg', { 'aria-hidden': 'true', 'data-icon': this.name }) } }\n",
}

const MENUS = [
	{
		title: 'Hoofdmenu',
		position: 0,
		items: [
			{ name: 'Home', link: '/' },
			{ name: 'Zoeken', link: '/zoeken' },
		],
	},
	{
		title: 'Over ons',
		position: 1,
		items: [{ name: 'Contact', link: '/contact' }],
	},
	{
		title: 'Juridisch',
		position: 2,
		items: [{ name: 'Privacy', link: '/privacy' }],
	},
]

const CRUMBS = [
	{ route: '/', label: 'Home', href: '?' },
	{ route: '/zoeken', label: 'Zoeken', href: '?route=%2Fzoeken' },
]

const SIGN_IN = [{ mode: 'digid', label: 'Inloggen met DigiD', href: '/auth/digid' }]

/**
 * Count the occurrences of a substring.
 *
 * @param {string} html   The rendered HTML.
 * @param {string} needle What to count.
 * @return {number} How often it occurs.
 */
function count(html, needle) {
	return html.split(needle).length - 1
}

test('an existing portal keeps its header: same markup as the hard-coded header, except the site name is not a heading', async () => {
	const baseline = await renderSfc('tests/fixtures/ShellHeaderBaseline.vue', {
		site: { title: 'Open Tilburg' },
		headerMenus: headerMenusOf(MENUS),
		route: '/zoeken',
		breadcrumbs: CRUMBS,
		signInRoutes: SIGN_IN,
		signinFailed: true,
		signinFailedMessage: 'Inloggen is niet gelukt.',
	})
	const block = await renderSfc('src/site/components/BrandHeader.vue', {
		title: 'Open Tilburg',
		variant: headerVariantOf({}),
		menus: headerMenusOf(MENUS),
		currentRoute: '/zoeken',
		breadcrumbs: CRUMBS,
		signInRoutes: SIGN_IN,
		signinFailedMessage: 'Inloggen is niet gelukt.',
	})

	// The one intended difference (REQ-PTB-004: the site name is not a heading).
	// `<!---->` is the empty anchor Vue leaves for a false `v-if`, a comment
	// node rather than markup, and the block has two more of them (the
	// single-bar navigation and the register control).
	const markup = (html) => html.replaceAll('<!---->', '')
	const expected = markup(baseline)
		.replace(
			'<h1 class="logo-text" data-testid="site-title">',
			'<span class="logo-text" data-testid="site-title">',
		)
		.replace('</h1>', '</span>')
	assert.equal(markup(block), expected)
	assert.equal(count(block, '<h1'), 0, 'the page content owns the h1')
})

test('a single-bar header lists each link once, and an unknown variant renders double', async () => {
	const single = await renderSfc('src/site/components/BrandHeader.vue', {
		title: 'Docs',
		variant: headerVariantOf({ headerVariant: 'single' }),
		menus: headerMenusOf(MENUS),
	})
	assert.equal(count(single, '>Home</div>'), 1)
	assert.equal(count(single, 'ac-header__navigation-secondary'), 0)
	assert.equal(count(single, 'data-testid="site-header-nav"'), 1)

	assert.equal(headerVariantOf({ headerVariant: 'x' }), 'double')
	assert.equal(headerVariantOf({}), 'double')
	const unknown = await renderSfc('src/site/components/BrandHeader.vue', {
		variant: headerVariantOf({ headerVariant: 'x' }),
		menus: headerMenusOf(MENUS),
	})
	assert.equal(count(unknown, '>Home</div>'), 1)
	assert.equal(count(unknown, 'ac-header__navigation-secondary'), 1)
	assert.equal(count(unknown, 'site-header-nav'), 0)
})

test('no register destination, no register control; a declared one renders beside sign-in', async () => {
	assert.equal(registerRouteOf({ authentication: { modes: ['digid'] } }), null)
	const without = await renderSfc('src/site/components/BrandHeader.vue', {
		signInRoutes: SIGN_IN,
		registerRoute: registerRouteOf({ authentication: { modes: ['digid'] } }),
	})
	assert.equal(count(without, 'data-testid="site-signin"'), 1)
	assert.equal(count(without, 'site-register'), 0)

	const route = registerRouteOf({ authentication: { register: '/registreren' } })
	assert.deepEqual(route, { href: '/registreren', label: '' })
	const withRegister = await renderSfc('src/site/components/BrandHeader.vue', {
		signInRoutes: SIGN_IN,
		registerRoute: route,
	})
	assert.match(
		withRegister,
		/href="\/registreren" data-testid="site-register">\s*Registreren\s*</,
	)
})

test('a block mounts without Nextcloud and renders its default strings', async () => {
	assert.equal(typeof globalThis.OC, 'undefined')
	assert.equal(typeof globalThis.window, 'undefined')
	const html = await renderSfc('src/site/components/BrandHeader.vue', {
		session: { name: 'Anna' },
		sessionLabel: 'Anna',
		breadcrumbs: CRUMBS,
	})
	assert.match(html, />\s*Uitloggen\s*</)
	assert.match(html, /aria-label="Kruimelpad"/)
	assert.match(html, /<span class="sr-only">Logo<\/span>/)
})

test('authored style and class never reach a block', () => {
	assert.deepEqual(
		withoutStyling({
			title: 'Welkom',
			style: 'position:absolute;top:0',
			class: 'evil',
		}),
		{ title: 'Welkom' },
	)
	assert.deepEqual(withoutStyling(undefined), {})
})

test('menus split by position: 0 header, 1 footer column, 2 or higher the legal strip', () => {
	assert.deepEqual(
		headerMenusOf(MENUS).map((m) => m.title),
		['Hoofdmenu'],
	)
	assert.deepEqual(
		footerMenusOf(MENUS).map((m) => m.title),
		['Over ons'],
	)
	assert.equal(subFooterMenuOf(MENUS).title, 'Juridisch')
	assert.equal(subFooterMenuOf(MENUS.slice(0, 2)), null)
	assert.deepEqual(legalLinksOf({}, MENUS), [
		{ label: 'Privacy', href: '/privacy' },
	])
	assert.deepEqual(
		legalLinksOf(
			{ footer: { legalLinks: [{ label: 'Cookies', href: '/cookies' }] } },
			MENUS,
		),
		[{ label: 'Cookies', href: '/cookies' }],
	)
	assert.deepEqual(footerContentOf({}), {
		description: '',
		colophon: '',
		socials: [],
		legalLinks: [],
		badges: [],
	})
})

test('the legal bar always names someone: the colophon, else the portal title', async () => {
	const fallback = await renderSfc(
		'src/site/components/FooterColumns.vue',
		{
			title: 'Open Tilburg',
			legalLinks: legalLinksOf({}, MENUS),
			menus: footerMenusOf(MENUS),
		},
		LIBRARY,
	)
	assert.match(fallback, /data-testid="site-footer-colophon">\s*Open Tilburg\s*</)
	assert.match(fallback, /href="\/privacy">Privacy</)

	const named = await renderSfc(
		'src/site/components/FooterColumns.vue',
		{ title: 'Open Tilburg', footer: { colophon: 'Gemeente Tilburg, KvK 123' } },
		LIBRARY,
	)
	assert.match(
		named,
		/data-testid="site-footer-colophon">\s*Gemeente Tilburg, KvK 123\s*</,
	)
	assert.equal(count(named, 'site-subfooter-menu'), 0)
})

test('every footer band names its role, and socials carry a screen-reader label', async () => {
	const html = await renderSfc(
		'src/site/components/FooterColumns.vue',
		{
			title: 'Open Tilburg',
			footer: {
				socials: [
					{
						label: 'Mastodon',
						href: 'https://social.example',
						icon: 'mastodon',
					},
				],
				badges: [{ label: 'ISO 27001', href: 'https://cert.example' }],
			},
		},
		LIBRARY,
	)
	assert.equal(count(html, '<section'), 2)
	assert.match(html, /<section class="pq-footer__band pq-footer__band--content">/)
	assert.match(
		html,
		/<section class="ac-footer__sub-footer pq-footer__band pq-footer__band--legal">/,
	)
	assert.match(html, /<span class="sr-only">Mastodon<\/span>/)
	assert.match(html, /href="https:\/\/cert.example"[^>]*>ISO 27001</)
})

/**
 * The declarations of every rule whose selector contains `needle`, keyed by
 * the rule's media context and the selector's tail after the band.
 *
 * @param {string} css    A stylesheet.
 * @param {string} needle The band selector, e.g. `section:first-of-type`.
 * @return {Map<string, string>} `media | tail` to the sorted declarations.
 */
function bandRules(css, needle) {
	const rules = new Map()
	const pattern = /(@media[^{]*)\{|([^{}@]+)\{([^{}]*)\}|\}/g
	let media = ''
	let depth = 0
	const source = css.replace(/\/\*[\s\S]*?\*\//g, '')
	let match
	while ((match = pattern.exec(source)) !== null) {
		if (match[1] !== undefined) {
			media = match[1].replace(/\s+/g, '')
			depth = 1
			continue
		}
		if (match[2] === undefined) {
			if (depth === 1) {
				media = ''
				depth = 0
			}
			continue
		}
		for (const selector of match[2].split(',')) {
			const at = selector.indexOf(needle)
			if (at === -1) {
				continue
			}
			const tail = selector.slice(at + needle.length).trim()
			const declarations = match[3]
				.split(';')
				.map((d) => d.replace(/\s+/g, '').trim())
				// `grid-gap` is the deprecated alias of `gap`; the vendored
				// rule declares both with one value.
				.map((d) => d.replace(/^grid-gap:/, 'gap:'))
				.filter((d, i, all) => d !== '' && all.indexOf(d) === i)
				.sort()
				.join(';')
			rules.set(`${media}|${tail}`, declarations)
		}
	}
	return rules
}

test('a third band changes nothing about the first two: every positional footer rule is restated by role', () => {
	const vendored = readFileSync(join(ROOT, 'css/nlds/nlds-app.css'), 'utf8')
	const site = readFileSync(join(ROOT, 'css/site-theme.css'), 'utf8')

	for (const [position, role] of [
		[
			'section:first-of-type',
			'section.pq-footer__band.pq-footer__band--content',
		],
		[
			'section:last-of-type:not(:only-of-type)',
			'section.pq-footer__band.pq-footer__band--legal',
		],
	]) {
		const expected = bandRules(vendored, position)
		const actual = bandRules(site, role)
		assert.ok(expected.size > 0, `the vendored sheet still styles ${position}`)
		for (const [key, declarations] of expected) {
			assert.equal(
				actual.get(key),
				declarations,
				`${role} restates ${position} ${key}`,
			)
		}
	}
	const rulesOnly = site.replace(/\/\*[\s\S]*?\*\//g, '')
	assert.doesNotMatch(rulesOnly, /#[0-9a-f]{3,8}\b/i, 'no colour literal')
	assert.doesNotMatch(
		rulesOnly,
		/of-type/,
		'no rule in site-theme.css selects a band by position',
	)
})

// A shell helper App.vue calls without importing it throws a ReferenceError
// in the browser the moment the footer computes, and the node renders above
// never mount App.vue, so they cannot see it. Found live on 29 Sep 2026: a
// merge of development kept the calls and dropped two names from the import.
test('App.vue imports every shell and block-prop helper it calls', () => {
	const app = readFileSync(join(ROOT, 'src/site/App.vue'), 'utf8')
	const script = app.slice(app.indexOf('<script'))
	const helpers = {
		'./lib/shellData.js': readFileSync(
			join(ROOT, 'src/site/lib/shellData.js'),
			'utf8',
		),
		'./lib/blockProps.js': readFileSync(
			join(ROOT, 'src/site/lib/blockProps.js'),
			'utf8',
		),
	}
	for (const [path, source] of Object.entries(helpers)) {
		const exported = [...source.matchAll(/^export function (\w+)/gm)].map(
			(m) => m[1],
		)
		const importLine = script.match(
			new RegExp(
				`import\\s*\\{([^}]*)\\}\\s*from\\s*'${path.replace(/\./g, '\\.')}'`,
			),
		)
		const imported = importLine
			? importLine[1]
					.split(',')
					.map((s) => s.trim())
					.filter(Boolean)
			: []
		for (const name of exported) {
			if (new RegExp(`\\b${name}\\(`).test(script)) {
				assert.ok(
					imported.includes(name),
					`App.vue calls ${name}() but does not import it from ${path}`,
				)
			}
		}
	}
})
