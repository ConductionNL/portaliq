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
import { test } from 'node:test'
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
	})
	const block = await renderSfc('src/site/components/BrandHeader.vue', {
		title: 'Open Tilburg',
		variant: headerVariantOf({}),
		menus: headerMenusOf(MENUS),
		currentRoute: '/zoeken',
		breadcrumbs: CRUMBS,
		signInRoutes: SIGN_IN,
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
