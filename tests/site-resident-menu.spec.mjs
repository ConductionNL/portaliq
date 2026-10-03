#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-resident-menu.spec.mjs: the blue bar carries the website's pages, and
// a signed-in resident's own items sit in a menu beside the content on the
// `/mijn` pages only (openspec/changes/site-resident-menu). Which items go
// where, signed out against signed in, the current item, the phone button,
// and both languages for every string the menu shows.
//
// Usage:
//   node --test tests/site-resident-menu.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { buildNav, shellSections } from '../src/shared/portalNav.js'
import {
	ownAreaLink,
	residentMenuGroups,
	showsResidentMenu,
} from '../src/site/lib/residentMenu.js'
import { headerMenusOf } from '../src/site/lib/shellData.js'
import { loadSfc, renderComponent, renderSfc } from './support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const read = (...parts) => readFileSync(join(ROOT, ...parts), 'utf8')
const bundle = (locale) => JSON.parse(read('src', 'shared', 'i18n', `${locale}.json`))
const fill = (text, vars = {}) => text.replace(/\{(\w+)\}/g, (_, name) => String(vars[name] ?? ''))
function translator (locale) {
	const strings = bundle(locale)
	return (key, vars) => fill(strings[key] || key, vars)
}
const en = translator('en')
const nl = translator('nl')
const href = (route) => `/apps/portaliq/site?route=${encodeURIComponent(route)}`

/**
 * A resident with the apps the Woo journey installs: dossiq gives its own
 * "Mijn zaken" and "Berichten", opencatalogi its dossiers, and the shell its
 * own sections.
 */
const CONTRIBUTIONS = {
	contributions: [
		{
			app: 'dossiq',
			label: 'Dossiq',
			pages: [
				{ id: 'mijnZaken', label: 'Mijn zaken' },
				{ id: 'berichten', label: 'Berichten' },
				{ id: 'verzoeken', label: 'Mijn verzoeken' },
			],
		},
		{
			app: 'opencatalogi',
			label: 'Open Catalogi',
			pages: [{ id: 'dossiers', label: 'Mijn dossiers' }],
		},
	],
	cases: { enabled: true },
	tasks: { enabled: true },
	unreadCount: 2,
}

const SESSION = { subjectRef: '999993653', name: 'Suzanne Moulin' }

/**
 * The navigation as the shell builds it for SESSION.
 *
 * @param {Function} t The translator.
 * @param {object} [extra] More shell sections.
 * @return {Array<object>} The navigation.
 */
function navFor(t, extra = {}) {
	return buildNav(CONTRIBUTIONS.contributions, t, {
		...shellSections({
			session: SESSION,
			contributions: CONTRIBUTIONS,
			threads: [],
			news: [],
		}),
		...extra,
	})
}

/** Every item name, group by group. */
const names = (groups) => groups.map((group) => [group.title, group.items.map((item) => item.name)])

test('the blue bar holds the website menus only, never the resident items', () => {
	const app = read('src', 'site', 'App.vue')
	const headerMenus = app.slice(app.indexOf('\t\theaderMenus() {'), app.indexOf('\t\tresidentMenu() {'))
	assert.match(headerMenus, /return headerMenusOf\(this\.menus\)/)
	assert.doesNotMatch(headerMenus, /this\.nav|accountMenu|residentMenu/)
	assert.doesNotMatch(read('src', 'site', 'lib', 'accountArea.js'), /export function accountMenu/)

	const cms = [
		{ title: 'Hoofdmenu', position: 0, items: [{ name: 'Home', link: '/' }] },
		{ title: 'Footer', position: 1, items: [] },
	]
	assert.deepEqual(headerMenusOf(cms).map((menu) => menu.title), ['Hoofdmenu'])
})

test('the resident items go into groups: cases, one per app, messages, then details', () => {
	const groups = residentMenuGroups(navFor(nl, { messages: true, news: true }), nl, 0, href)
	assert.deepEqual(names(groups), [
		['Zaken en taken', ['Mijn zaken', 'Mijn taken', 'Toegang tot zaken']],
		['Dossiq', ['Mijn zaken (Dossiq)', 'Berichten (Dossiq)', 'Mijn verzoeken']],
		['Open Catalogi', ['Mijn dossiers']],
		['Berichten en nieuws', ['Berichten', 'Gesprekken', 'Nieuws']],
		['Uw gegevens en account', ['Mijn gegevens', 'Mijn account']],
	])
	const dossiers = groups[2].items[0]
	assert.equal(dossiers.link, '/mijn/opencatalogi/dossiers')
	assert.equal(dossiers.href, href('/mijn/opencatalogi/dossiers'))
})

test('no two items read the same, in either language', () => {
	for (const t of [nl, en]) {
		const all = residentMenuGroups(navFor(t, { messages: true, news: true }), t, 0, href)
			.flatMap((group) => group.items.map((item) => item.name.toLowerCase()))
		assert.equal(new Set(all).size, all.length, all.join(', '))
	}
	// The shell's inbox and its conversations were both "Berichten" in Dutch.
	assert.notEqual(nl('Inbox'), nl('Conversations'))
})

test('an app named only by its id groups under that id, and an empty group is left out', () => {
	const nav = buildNav([{ app: 'pipelinq', pages: [{ id: 'vragen', label: 'Vragen' }] }], en, {})
	assert.deepEqual(names(residentMenuGroups(nav, en, 0, href)), [
		['pipelinq', ['Vragen']],
		['Messages and news', ['Inbox']],
	])
	assert.deepEqual(residentMenuGroups([], en, 0, href), [])
	assert.deepEqual(residentMenuGroups(null, en, 0, href), [])
})

test('pages with the same group share one heading across apps, in the order they first appear', () => {
	const nav = buildNav(
		[
			{
				app: 'dossiq',
				label: 'Dossiq',
				pages: [
					{ id: 'mijnZaken', label: 'Mijn zaken', group: 'Mijn zaken en verzoeken' },
					{ id: 'uren', label: 'Mijn uren' },
				],
			},
			{
				app: 'pipelinq',
				label: 'Pipelinq',
				pages: [
					{ id: 'vragen', label: 'Mijn vragen', group: 'Vragen en contact' },
					{ id: 'verzoeken', label: 'Mijn verzoeken', group: 'Mijn zaken en verzoeken' },
				],
			},
			{
				app: 'opencatalogi',
				label: 'Open Catalogi',
				pages: [{ id: 'dossiers', label: 'Mijn dossiers', group: '  ' }],
			},
		],
		nl,
		{},
	)
	assert.deepEqual(names(residentMenuGroups(nav, nl, 0, href)), [
		['Mijn zaken en verzoeken', ['Mijn zaken', 'Mijn verzoeken']],
		['Dossiq', ['Mijn uren']],
		['Vragen en contact', ['Mijn vragen']],
		['Open Catalogi', ['Mijn dossiers']],
		['Berichten en nieuws', ['Berichten']],
	])
})

test('two items of one name in a shared group are told apart by their app', () => {
	const nav = buildNav(
		[
			{ app: 'dossiq', label: 'Dossiq', pages: [{ id: 'a', label: 'Mijn zaken', group: 'Mijn zaken en verzoeken' }] },
			{ app: 'pipelinq', label: 'Pipelinq', pages: [{ id: 'b', label: 'Mijn zaken', group: 'Mijn zaken en verzoeken' }] },
		],
		nl,
		{},
	)
	assert.deepEqual(names(residentMenuGroups(nav, nl, 0, href))[0], [
		'Mijn zaken en verzoeken',
		['Mijn zaken (Dossiq)', 'Mijn zaken (Pipelinq)'],
	])
})

test('the inbox item carries the unread count, read out in words', () => {
	const groups = residentMenuGroups(navFor(en), en, 2, href)
	const inbox = groups.flatMap((group) => group.items).find((item) => item.link === '/mijn/inbox')
	assert.equal(inbox.badge, '2')
	assert.equal(inbox.badgeLabel, '2 unread')
	const none = residentMenuGroups(navFor(en), en, 0, href)
		.flatMap((group) => group.items)
		.find((item) => item.link === '/mijn/inbox')
	assert.equal(none.badge, undefined)
})

test('the menu shows signed in on a /mijn page only; a public page and a signed-out visitor get none', () => {
	const nav = navFor(en)
	assert.equal(showsResidentMenu(SESSION, '/mijn/cases', nav), true)
	assert.equal(showsResidentMenu(SESSION, '/mijn', nav), true)
	assert.equal(showsResidentMenu(SESSION, '/', nav), false)
	assert.equal(showsResidentMenu(SESSION, '/nieuws', nav), false)
	assert.equal(showsResidentMenu(SESSION, '/mijnheer', nav), false)
	assert.equal(showsResidentMenu(null, '/mijn/cases', nav), false)
	assert.equal(showsResidentMenu(SESSION, '/mijn/cases', []), false)

	const app = read('src', 'site', 'App.vue')
	assert.match(app, /if \(!showsResidentMenu\(this\.session, this\.route, this\.nav\)\)/)
})

test('the header links to the own area when signed in, and keeps the sign-in links when signed out', async () => {
	const link = ownAreaLink(SESSION, nl, href)
	assert.deepEqual(link, { route: '/mijn', href: href('/mijn'), label: 'Mijn omgeving' })
	assert.equal(ownAreaLink(null, nl, href), null)

	const signedIn = await renderSfc('src/site/components/BrandHeader.vue', {
		title: 'Gemeente',
		session: SESSION,
		sessionLabel: 'Ingelogd als Suzanne Moulin',
		accountLink: link,
		signOutLabel: 'Uitloggen',
	})
	assert.match(signedIn, /data-testid="site-auth-subject"[^>]*>Ingelogd als Suzanne Moulin</)
	assert.match(signedIn, /data-testid="site-own-area"[^>]*>\s*Mijn omgeving\s*</)
	assert.match(signedIn, /href="\/apps\/portaliq\/site\?route=%2Fmijn"/)
	assert.match(signedIn, /data-testid="site-signout"[^>]*>\s*Uitloggen\s*</)
	assert.doesNotMatch(signedIn, /data-testid="site-signin"/)

	const signedOut = await renderSfc('src/site/components/BrandHeader.vue', {
		title: 'Gemeente',
		signInRoutes: [{ mode: 'digid', href: '/login', label: 'Inloggen met DigiD' }],
	})
	assert.match(signedOut, /data-testid="site-signin"[^>]*>\s*Inloggen met DigiD\s*</)
	assert.doesNotMatch(signedOut, /site-own-area|site-signout/)
})

test('the menu is a named landmark with labelled groups and marks the current item', async () => {
	const groups = residentMenuGroups(navFor(nl), nl, 2, href)
	const html = await renderSfc('src/site/components/ResidentMenu.vue', {
		groups,
		currentRoute: '/mijn/dossiq/verzoeken',
		label: 'Mijn omgeving',
	})
	assert.match(html, /<nav[^>]*aria-label="Mijn omgeving"[^>]*data-testid="site-resident-menu"/)
	const current = html.match(/<a[^>]*aria-current="page"[^>]*>[\s\S]*?<\/a>/g)
	assert.equal(current.length, 1)
	assert.match(current[0], /pq-resident-menu__link--current/)
	assert.match(current[0], /Mijn verzoeken/)
	assert.match(current[0], /href="\/apps\/portaliq\/site\?route=%2Fmijn%2Fdossiq%2Fverzoeken"/)

	// Each group's list is named by its label, and the label is no heading,
	// so the page's own h1 stays the first heading.
	for (const id of html.matchAll(/<ul[^>]*aria-labelledby="([^"]+)"/g)) {
		assert.match(html, new RegExp(`<p id="${id[1]}"`))
	}
	assert.doesNotMatch(html, /<h[1-6]/)

	// The unread count is read out in words.
	assert.match(html, /data-testid="site-resident-menu-badge"[\s\S]*?2 ongelezen/)
})

test('on a phone the menu folds behind one button that says whether it is open', async () => {
	const component = await loadSfc('src/site/components/ResidentMenu.vue')
	const groups = residentMenuGroups(navFor(nl), nl, 0, href)
	const closed = await renderComponent(component, { groups, currentRoute: '/mijn/cases' })
	assert.match(closed, /<button[^>]*aria-expanded="false"[^>]*aria-controls="pq-resident-menu-list"[^>]*data-testid="site-resident-menu-toggle"[^>]*>\s*Menu mijn omgeving\s*</)
	assert.match(closed, /id="pq-resident-menu-list" class="pq-resident-menu__groups"/)
	assert.doesNotMatch(closed, /pq-resident-menu__groups--open/)

	// The button toggles, and choosing an item folds the list and navigates.
	const self = { open: false, emitted: [], $emit(name, value) { this.emitted.push([name, value]) } }
	self.open = !self.open
	assert.equal(self.open, true)
	component.methods.select.call(self, '/mijn/tasks')
	assert.equal(self.open, false)
	assert.deepEqual(self.emitted, [['navigate', '/mijn/tasks']])

	// Below tablet width the list is hidden until opened, and nothing in the
	// menu or the area sets a width that could scroll the page sideways.
	const sfc = read('src', 'site', 'components', 'ResidentMenu.vue')
	const phone = sfc.slice(sfc.indexOf('@media (max-width: 767px)'))
	assert.match(phone, /\.pq-resident-menu__toggle \{\s*display: inline-flex;/)
	assert.match(phone, /\.pq-resident-menu__groups \{\s*display: none;/)
	assert.match(phone, /\.pq-resident-menu__groups--open \{\s*display: block;/)
	const area = read('src', 'site', 'components', 'AccountArea.vue')
	assert.match(area, /\.pq-account--with-menu \{\s*display: grid;\s*grid-template-columns: minmax\(0, 1fr\);/)
	assert.match(area, /@media \(min-width: 768px\) \{\s*\.pq-account--with-menu \{\s*grid-template-columns: minmax\(180px, 260px\) minmax\(0, 1fr\);/)
	assert.doesNotMatch(sfc + area, /(?:^|[^-])width:\s*\d{3,}px/m)

	// Signed in, the masthead's controls take their own row on a phone, so
	// the site name and the sign-out button are not squeezed over the bar.
	const header = read('src', 'site', 'components', 'BrandHeader.vue')
	const narrow = header.slice(header.indexOf('@media (max-width: 767px)'))
	assert.match(narrow, /\.ac-header__navigation-main \{\s*flex-wrap: wrap;\s*block-size: auto;/)
})

test('the signed-in area shows the menu only with a session and groups', async () => {
	const area = await loadSfc('src/site/components/AccountArea.vue')
	const withMenu = area.computed.withMenu
	assert.equal(withMenu.call({ session: SESSION, menuGroups: [{ key: 'cases' }] }), true)
	assert.equal(withMenu.call({ session: null, menuGroups: [{ key: 'cases' }] }), false)
	assert.equal(withMenu.call({ session: SESSION, menuGroups: [] }), false)
})

test('every string the menu and header show has a Dutch and an English value', () => {
	const nlBundle = bundle('nl')
	const enBundle = bundle('en')
	for (const key of [
		'My area',
		'Menu of my area',
		'Close the menu',
		'Cases and tasks',
		'Messages and news',
		'Your details and account',
		'{label} ({source})',
		'Conversations',
	]) {
		assert.equal(enBundle[key], key, `en identity for ${key}`)
		assert.ok(nlBundle[key], `nl value for ${key}`)
		assert.doesNotMatch(nlBundle[key], /—/)
	}
})
