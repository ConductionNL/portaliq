#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-chrome.spec.mjs: the chrome the school designs draw
// (site-chrome-follows-the-design): the header search and the way to the own
// area, the person chip, the designed footer, the sign-in cards and the motif.
// Rendered in plain node, without a DOM or Nextcloud.
//
// Usage:
//   node --test tests/site-chrome.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { signInRoutes } from '../src/site/lib/authApi.js'
import { personOf } from '../src/site/components/chrome/person.js'
import { footerContentOf, headerSearchOf } from '../src/site/lib/shellData.js'
import { renderSfc } from './support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const read = (rel) => readFileSync(join(ROOT, rel), 'utf8')
const LIBRARY = {
	'@conduction/nextcloud-vue/public':
		"import { h } from 'vue'\nexport const CnSiteIcon = { props: ['name', 'size'], render() { return h('svg', { 'aria-hidden': 'true' }) } }\n",
}
const count = (html, needle) => html.split(needle).length - 1

test('the header search is off until a portal switches it on, and opens an in-site page', () => {
	assert.deepEqual(headerSearchOf({}), {
		enabled: false,
		label: '',
		placeholder: '',
		route: '/zoeken',
	})
	assert.deepEqual(
		headerSearchOf({
			headerSearch: {
				enabled: true,
				placeholder: 'Zoek een cursus',
				route: '/cursussen',
			},
		}),
		{
			enabled: true,
			label: '',
			placeholder: 'Zoek een cursus',
			route: '/cursussen',
		},
	)
	assert.equal(headerSearchOf({ headerSearch: { enabled: 'yes' } }).enabled, false)
	assert.equal(
		headerSearchOf({ headerSearch: { route: '//evil.example' } }).route,
		'/zoeken',
	)
})

test('the person chip shows the name, its initials and the organisation, never a reference', () => {
	assert.deepEqual(personOf({ displayName: 'Fatima Hulstkamp' }), {
		name: 'Fatima Hulstkamp',
		initials: 'FH',
		subline: '',
	})
	assert.deepEqual(
		personOf({
			name: 'linda de jansen',
			organisationName: 'Jansen Installatietechniek',
		}),
		{
			name: 'linda de jansen',
			initials: 'LJ',
			subline: 'Jansen Installatietechniek',
		},
	)
	assert.equal(personOf({ displayName: '123456789' }), null)
	assert.equal(personOf({ displayName: 'ref-1', subjectRef: 'ref-1' }), null)
	assert.equal(personOf(null), null)
})

test('the footer carries its button and contact column only when they say something', () => {
	const full = footerContentOf({
		footer: {
			cta: { label: 'Contact en schooltijden', href: '/contact' },
			contact: {
				title: 'Contact',
				lines: [{ text: 'Wilgenlaan 12, Zuiddrecht' }],
			},
		},
	})
	assert.deepEqual(full.cta, {
		label: 'Contact en schooltijden',
		href: '/contact',
	})
	assert.equal(full.contact.title, 'Contact')
	const empty = footerContentOf({
		footer: { cta: { label: 'Klik' }, contact: { lines: [] } },
	})
	assert.equal(empty.cta, null)
	assert.equal(empty.contact, null)
})

test('a sign-in route carries the card the portal wrote, and its button text', () => {
	const routes = signInRoutes(
		{
			slug: 'vaartveld',
			authentication: {
				modes: ['nextcloud', 'digid'],
				modeLabels: {
					nextcloud: {
						title: 'Ik ben leerling',
						button: 'Inloggen met je schoolaccount',
					},
				},
			},
		},
		'/auth',
	)
	assert.equal(routes[0].label, 'Inloggen met je schoolaccount')
	assert.equal(routes[0].card.title, 'Ik ben leerling')
	assert.equal(routes[1].label, 'Inloggen met DigiD')
	assert.equal(Object.hasOwn(routes[1], 'card'), false)
})

test('signed out, the designed header offers search and one button to the own area', async () => {
	const html = await renderSfc('src/site/components/chrome/HeaderTools.vue', {
		searchBox: {
			enabled: true,
			label: 'Zoeken op de website',
			placeholder: 'Zoek een cursus',
		},
		accountLabel: 'Mijn academie',
		accountHref: '?route=%2Fmijn',
		hasMenu: true,
	})
	assert.match(html, /data-testid="site-header-search"/)
	assert.match(html, /role="search"/)
	assert.match(
		html,
		/placeholder="Zoek een cursus"[^>]*aria-label="Zoeken op de website"/,
	)
	assert.match(html, /data-testid="site-account-button"[^>]*>[\s\S]*Mijn academie/)
	assert.match(
		html,
		/aria-expanded="false"[^>]*aria-controls="pq-site-navigation"/,
	)
	assert.equal(count(html, 'site-signout'), 0)
})

test('signed in, the designed header shows the person chip and a sign-out link, not the account button', async () => {
	const html = await renderSfc('src/site/components/chrome/HeaderTools.vue', {
		searchBox: { enabled: false },
		accountLabel: 'Mijn academie',
		session: {
			displayName: 'Linda Jansen',
			organisationName: 'Jansen Installatietechniek',
		},
		accountLink: {
			route: '/mijn',
			href: '?route=%2Fmijn',
			label: 'Mijn omgeving',
		},
	})
	assert.match(html, /class="pq-header-tools__initials" aria-hidden="true">LJ</)
	assert.match(html, /data-testid="site-auth-subject">Linda Jansen</)
	assert.match(
		html,
		/data-testid="site-auth-subline">\s*Jansen Installatietechniek/,
	)
	assert.match(html, /data-testid="site-signout"/)
	assert.equal(count(html, 'site-account-button'), 0)
	assert.equal(count(html, 'site-header-search'), 0)
})

test('a portal that asks for no designed header keeps its header markup', () => {
	const header = read('src/site/components/BrandHeader.vue')
	assert.match(
		header,
		/designed\(\) \{\s*return Boolean\(this\.accountLabel\) \|\| this\.searchBox\.enabled === true/,
	)
	// The tools load on demand, so the site's entry does not carry them.
	assert.match(
		header,
		/defineAsyncComponent\(\(\) => import\('\.\/chrome\/HeaderTools\.vue'\)\)/,
	)
})

const MENUS = [
	{
		title: 'Snel naar',
		position: 1,
		items: [{ name: 'Kind ziek melden', link: '/ziek' }],
	},
]

test('a designed footer reads brand, contact, menus; any other footer keeps the menus first', async () => {
	const designed = await renderSfc(
		'src/site/components/FooterColumns.vue',
		{
			title: 'De Wilgenboom',
			menus: MENUS,
			footer: {
				description: 'De deur staat open.',
				cta: { label: 'Contact en schooltijden', href: '/contact' },
				contact: {
					title: 'Contact',
					lines: [
						{ text: 'Wilgenlaan 12' },
						{ text: 'E-mail', href: 'mailto:a@example.org' },
					],
				},
			},
		},
		LIBRARY,
	)
	const brand = designed.indexOf('ac-footer__logo')
	const contact = designed.indexOf('site-footer-contact')
	const menu = designed.indexOf('site-footer-menu')
	assert.ok(
		brand > 0 && brand < contact && contact < menu,
		'brand, then contact, then the menus',
	)
	assert.match(
		designed,
		/data-testid="site-footer-cta"[^>]*>\s*Contact en schooltijden/,
	)
	assert.match(designed, /<a href="mailto:a@example.org">E-mail<\/a>/)

	const plain = await renderSfc(
		'src/site/components/FooterColumns.vue',
		{ title: 'Open Tilburg', menus: MENUS },
		LIBRARY,
	)
	assert.ok(
		plain.indexOf('site-footer-menu') < plain.indexOf('ac-footer__logo'),
		'menus first, brand last, as before',
	)
	assert.equal(count(plain, 'site-footer-cta'), 0)
})

test('the sign-in page lists one card per way in, the first button primary, with notice and panel', async () => {
	const html = await renderSfc('src/site/components/chrome/SignInPage.vue', {
		routes: [
			{
				mode: 'nextcloud',
				label: 'Inloggen met je schoolaccount',
				href: '/a',
				card: { title: 'Ik ben leerling', hint: 'Je leerlingnummer.' },
			},
			{
				mode: 'digid',
				label: 'Inloggen met DigiD',
				href: '/b',
				card: { title: 'Ik ben ouder of verzorger' },
			},
		],
		page: {
			title: 'Inloggen op Mijn Vaartveld',
			intro: 'Kies wie je bent.',
			notice: { text: 'Wachtwoord vergeten?' },
			staffLink: {
				text: 'Werkt u hier?',
				label: 'Log in op de werkplek',
				href: '/login',
			},
			panel: {
				title: 'Alles op een plek',
				items: [{ title: 'Rooster', text: 'Je lessen' }],
			},
		},
	})
	assert.match(
		html,
		/data-testid="site-signin-title">\s*Inloggen op Mijn Vaartveld/,
	)
	assert.equal(count(html, 'data-testid="site-signin-card"'), 2)
	assert.ok(
		html.indexOf('Ik ben leerling') < html.indexOf('Ik ben ouder of verzorger'),
		'in the order of the modes',
	)
	assert.match(
		html,
		/class="utrecht-button-link--primary-action[^"]*"[^>]*href="\/a"/,
	)
	assert.match(
		html,
		/class="utrecht-button-link--secondary-action[^"]*"[^>]*href="\/b"/,
	)
	assert.match(html, /pq-signin__mark--digid[^>]*>(<!--\[-->)?DigiD/)
	assert.match(html, /role="note"[^>]*data-testid="site-signin-notice"/)
	assert.match(
		html,
		/<a class="utrecht-link" href="\/login">Log in op de werkplek<\/a>/,
	)
	assert.match(html, /data-testid="site-signin-panel"/)
	assert.match(html, /pq-signin--with-panel/)
})

test('a portal that wrote no card or page text keeps the plain list of buttons', () => {
	const area = read('src/site/components/AccountArea.vue')
	assert.match(area, /<SignInPage\s+v-if="signInDesigned"/)
	assert.match(area, /<template v-else>\s*<h1 class="utrecht-heading-2">/)
	assert.match(
		area,
		/Object\.keys\(this\.signInPageText\)\.length > 0\s*\|\| this\.signInRoutes\.some\(\(way\) => way\.card\)/,
	)
})

test('the motif reads the bridge tokens, draws nothing without them, and the chrome names no colour', () => {
	const css = read('css/site-theme.css')
	const chrome = css.slice(css.indexOf('THE CHROME THE SCHOOL DESIGNS DRAW'))
	assert.match(chrome, /block-size: var\(--cn-brand-stripe-height, 0\)/)
	assert.match(
		chrome,
		/var\(\s*--cn-brand-stripe-image,\s*linear-gradient\(\s*var\(--cn-brand-stripe-color-1\),\s*var\(--cn-brand-stripe-color-1\)\s*\)/,
	)
	assert.match(chrome, /var\(\s*--cn-brand-stripe-image-inverse,/)
	assert.match(
		chrome,
		/var\(--thematiq-accent-color, var\(--nldesign-color-primary\)\)/,
	)
	assert.doesNotMatch(chrome, /#[0-9a-f]{3,8}\b/i)
	assert.doesNotMatch(chrome, /\brgba?\(/)
})

test('the shell hands the portal sign-in ways to the widget grid, and the header search to the header', () => {
	const app = read('src/site/App.vue')
	assert.match(app, /signInRoutes: this\.signInRoutes,/)
	assert.match(app, /:searchBox="headerSearch"/)
	assert.match(app, /@search="goSearch"/)
	assert.match(app, /searchRoute\(\) \{\s*return this\.headerSearch\.route/)
})
