#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-navigation.spec.mjs: the menu block (site-navigation-block). The
// groups the shell derives from the header menus and the signed-in
// navigation, the rule that a page with the block leaves the header menu
// out, and the block and header rendered in plain node.
//
// Usage:
//   node --test tests/site-navigation.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { buildNav } from '../src/shared/portalNav.js'
import { residentMenuGroups } from '../src/site/lib/residentMenu.js'
import {
	hasNavigationBlock,
	NAVIGATION_BLOCK,
	navigationGroups,
	sideMenuOf,
} from '../src/site/lib/siteNavigation.js'
import { renderSfc } from './support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const nl = (() => {
	const strings = JSON.parse(
		readFileSync(join(ROOT, 'src', 'shared', 'i18n', 'nl.json'), 'utf8'),
	)
	return (key, vars = {}) =>
		(strings[key] || key).replace(/\{(\w+)\}/g, (_, name) =>
			String(vars[name] ?? ''),
		)
})()

/** A parent's answers: learniq with three pages, news and the inbox on. */
const NAV = buildNav(
	[
		{
			app: 'learniq',
			label: 'School',
			pages: [
				{ id: 'child', label: 'Mijn kind' },
				{ id: 'absence', label: 'Afwezigheid' },
				{ id: 'reports', label: 'Rapporten' },
			],
		},
	],
	nl,
	{ news: true, access: true },
)
const MENUS = [
	{
		title: 'Hoofdmenu',
		position: 0,
		items: [
			{ name: 'Home', link: '/' },
			{
				name: 'Over de school',
				link: '/over',
				items: [{ name: 'Team', link: '/over/team' }],
			},
		],
	},
]
function hrefFor(route) {
	return `/apps/portaliq/site?portal=wilgenboom&route=${encodeURIComponent(route)}`
}

test("the groups: the resident menu's groups first, then the site pages", () => {
	const resident = residentMenuGroups(NAV, nl, 2, hrefFor)
	const groups = navigationGroups({ residentGroups: resident, menus: MENUS })

	assert.deepEqual(
		groups.map((group) => group.title),
		[...resident.map((group) => group.title), 'Hoofdmenu'],
	)
	const school = groups.find((group) => group.title === 'School')
	assert.deepEqual(
		school.items.map((item) => [item.name, item.link]),
		[
			['Mijn kind', '/mijn/learniq/child'],
			['Afwezigheid', '/mijn/learniq/absence'],
			['Rapporten', '/mijn/learniq/reports'],
		],
	)
	assert.equal(school.items[0].href, hrefFor('/mijn/learniq/child'))
	const inbox = groups
		.flatMap((group) => group.items)
		.find((item) => item.link === '/mijn/inbox')
	assert.equal(inbox.badge, '2')
	// A child page follows its parent, so nothing the header offers is lost.
	assert.deepEqual(
		groups.at(-1).items.map((item) => item.name),
		['Home', 'Over de school', 'Team'],
	)
})

test('signed out, only the site pages are there, and an empty group is left out', () => {
	const groups = navigationGroups({ residentGroups: [], menus: MENUS })
	assert.deepEqual(
		groups.map((group) => group.title),
		['Hoofdmenu'],
	)
	assert.deepEqual(navigationGroups({ residentGroups: [], menus: [] }), [])
})

test('a menu block in the side region or the grid leaves the header menu out', () => {
	const block = { id: 'menu', widgetKey: NAVIGATION_BLOCK, props: {} }
	assert.equal(hasNavigationBlock({ aside: [block], main: [] }), true)
	assert.equal(hasNavigationBlock({ aside: [], main: [block] }), true)
	assert.equal(
		hasNavigationBlock({ aside: [], main: [{ widgetKey: 'markdown' }] }),
		false,
	)
	assert.equal(hasNavigationBlock({ header: [block] }), false)
	assert.equal(sideMenuOf({ aside: [block] }), true)
	assert.equal(sideMenuOf({ main: [block] }), false)
	assert.equal(sideMenuOf(null), false)
})

test('the block is a named landmark with headed groups, the current page marked, and a button for phones', async () => {
	const groups = navigationGroups({
		residentGroups: residentMenuGroups(NAV, nl, 0, hrefFor),
		menus: MENUS,
	})
	const html = await renderSfc('src/site/components/SiteNavigationBlock.vue', {
		groups,
		currentRoute: '/mijn/learniq/absence',
		label: 'Menu',
		toggleLabel: 'Menu',
	})

	assert.match(html, /<nav class="pq-sitenav" aria-label="Menu"/)
	assert.match(
		html,
		/<button[^>]*aria-expanded="false"[^>]*aria-controls="pq-sitenav-\d+"/,
	)
	assert.equal((html.match(/<h2/g) || []).length, groups.length)
	assert.match(
		html,
		/aria-current="page"[^>]*>(?:<!--\[-->)?<span>Afwezigheid<\/span>/,
	)
	assert.equal((html.match(/aria-current="page"/g) || []).length, 1)
	assert.match(
		html,
		/href="\/apps\/portaliq\/site\?portal=wilgenboom&amp;route=%2Fmijn%2Flearniq%2Fchild"/,
	)
})

test('the header shows no menu when the page carries the block, and keeps the account controls', async () => {
	const props = {
		title: 'Ouderportaal De Wilgenboom',
		menus: MENUS,
		session: { subjectRef: 's1' },
		sessionLabel: 'Ingelogd als Fatima Hulstkamp',
	}
	const withMenu = await renderSfc('src/site/components/BrandHeader.vue', props)
	const without = await renderSfc('src/site/components/BrandHeader.vue', {
		...props,
		showNavigation: false,
	})

	assert.match(withMenu, />Home<\/div>/)
	assert.doesNotMatch(without, />Home<\/div>/)
	assert.doesNotMatch(without, /ac-header__navigation-secondary/)
	assert.match(without, /Ingelogd als Fatima Hulstkamp/)
	assert.match(without, /data-testid="site-signout"/)
	assert.match(without, /Ouderportaal De Wilgenboom/)
})

test('the block is public, and the shell hands it its data', () => {
	const grid = readFileSync(
		join(ROOT, 'src', 'site', 'components', 'WidgetGrid.vue'),
		'utf8',
	)
	assert.match(grid, /siteNavigation: SiteNavigationBlock,/)
	const app = readFileSync(join(ROOT, 'src', 'site', 'App.vue'), 'utf8')
	assert.match(app, /navigation: this\.navigation,/)
	assert.match(app, /:showNavigation="!menuOnPage"/)
	// The signed-in area keeps its own menu beside the content
	// (site-resident-menu), so the block's column is for CMS pages only.
	assert.match(app, /hasNavigationBlock\(this\.regions\) && !this\.accountRoute/)
	assert.match(app, /&& this\.page !== null\s+&& !this\.accountRoute/)
})
