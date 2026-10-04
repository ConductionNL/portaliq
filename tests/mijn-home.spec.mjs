#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// mijn-home.spec.mjs: the site acting on the page keys
// (site-mijn-omgeving-components wave 5: REQ-SMO-006, REQ-SMO-007,
// REQ-SMO-008, REQ-SMO-020): `menu: false` leaves the menu and keeps its
// route, `perRecord` lists a page once per record, the menu draws each
// page's icon, `/mijn` opens the home with "Dit moet u nog doen", and a
// `records` page switches between records through its route.
//
// Usage:
//   node --test tests/mijn-home.spec.mjs

import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import {
	buildNav,
	navEntryForRoute,
	recordIdOfRoute,
} from '../src/shared/portalNav.js'
import { caseOverviewsOf, homeEntriesOf } from '../src/site/components/mijn/home.js'
import { accountRedirect } from '../src/site/lib/accountArea.js'
import menuIcons from '../src/site/lib/menuIcons.js'
import {
	loadPerRecordRows,
	residentMenuGroups,
} from '../src/site/lib/residentMenu.js'
import { instance, inState, t } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const MijnHome = await loadSfc('src/site/components/mijn/MijnHome.vue')
const RecordSwitcher = await loadSfc('src/site/components/mijn/RecordSwitcher.vue')
const ResidentMenu = await loadSfc('src/site/components/ResidentMenu.vue')
const ContributionPage = await loadSfc(
	'src/site/pages/collections/ContributionPage.vue',
)
const CHILDREN = { id: 'parentChildren', register: 'learniq', schema: 'learner' }

/** The guardian's contribution, as learniq#1641 declares it. */
const LEARNIQ = {
	app: 'learniq',
	label: 'Learniq',
	collections: [
		CHILDREN,
		{ id: 'parentGrades', register: 'learniq', schema: 'grade' },
	],
	pages: [
		{
			id: 'overview',
			label: 'Overzicht',
			icon: 'HomeOutline',
			home: true,
			records: { collection: 'parentChildren', titleFields: ['givenName'] },
			blocks: [{ type: 'richText', markdown: 'x' }],
		},
		{
			id: 'parentGrades',
			label: 'Cijfers',
			menu: false,
			blocks: [{ type: 'collection', collection: 'parentGrades' }],
		},
		{
			id: 'absence',
			label: 'Afwezigheid',
			icon: 'Calendar',
			record: { collection: 'parentChildren', titleFields: ['givenName'] },
			perRecord: 'parentChildren',
			blocks: [{ type: 'collection', collection: 'parentChildren' }],
		},
	],
}

const ROWS = [
	{ id: 'vera', givenName: 'Vera' },
	{ id: 'sami', givenName: 'Sami' },
]

const href = (route) => `https://site.example/?route=${route}`

test('a menu: false page leaves the menu and keeps its route', () => {
	// REQ-SMO-020 scenario "Old routes stay, the menu shrinks".
	const nav = buildNav([LEARNIQ], t, {})
	const names = residentMenuGroups(nav, t, 0, href).flatMap((group) =>
		group.items.map((item) => item.name),
	)
	assert.equal(names.includes('Cijfers'), false, 'not in the menu')
	assert.equal(
		navEntryForRoute(nav, '/mijn/learniq/parentGrades')?.page.id,
		'parentGrades',
		'the route still opens it',
	)
	assert.equal(accountRedirect(nav, '/mijn/learniq/parentGrades'), '')
})

test('a perRecord page is listed under each record, linking to that record', () => {
	// REQ-SMO-020 scenario "A page per child".
	const nav = buildNav([LEARNIQ], t, {})
	const groups = residentMenuGroups(nav, t, 0, href, {
		'learniq:parentChildren': ROWS,
	})
	const vera = groups.find((group) => group.title === 'Vera')
	const sami = groups.find((group) => group.title === 'Sami')
	assert.ok(vera && sami, 'one group per child')
	assert.deepEqual(
		vera.items.map((item) => [item.name, item.link]),
		[['Afwezigheid', '/mijn/learniq/absence/vera']],
	)
	assert.deepEqual(
		sami.items.map((item) => [item.name, item.link]),
		[['Afwezigheid', '/mijn/learniq/absence/sami']],
	)
	assert.equal(
		vera.items[0].href,
		'https://site.example/?route=/mijn/learniq/absence/vera',
	)
	assert.equal(vera.items[0].icon, 'Calendar', 'the icon comes along')
	assert.equal(
		'perRecord' in vera.items[0],
		false,
		'no internal mark leaves the function',
	)

	const before = residentMenuGroups(nav, t, 0, href)
	const listed = before
		.flatMap((group) => group.items)
		.filter((item) => item.name === 'Afwezigheid')
	assert.equal(listed.length, 1, 'until the rows are known it is listed once')
	assert.equal(listed[0].link, '/mijn/learniq/absence')
})

test('the rows of every perRecord collection are read once, a failed read leaves them out', async () => {
	const asked = []
	const rows = await loadPerRecordRows(
		[LEARNIQ, { app: 'dossiq', collections: [], pages: [] }],
		{
			fetchCollection: async (collection, options) => {
				asked.push([collection.id, options])
				return ROWS
			},
		},
	)
	assert.deepEqual(asked, [['parentChildren', { orNull: true }]])
	assert.deepEqual(rows, { 'learniq:parentChildren': ROWS })
	assert.deepEqual(
		await loadPerRecordRows([LEARNIQ], { fetchCollection: async () => null }),
		{},
	)
	assert.deepEqual(await loadPerRecordRows([LEARNIQ], null), {})
})

test('a route chooses the record of a record page, and only of a record page', () => {
	const nav = buildNav([LEARNIQ], t, {})
	assert.equal(recordIdOfRoute('/mijn/learniq/absence/vera'), 'vera')
	assert.equal(recordIdOfRoute('/mijn/learniq/absence'), '')
	assert.equal(recordIdOfRoute('/mijn/learniq/absence/'), '')
	assert.equal(recordIdOfRoute('/elders/a/b/c'), '')
	assert.equal(
		navEntryForRoute(nav, '/mijn/learniq/absence/vera')?.page.id,
		'absence',
	)
	assert.equal(
		navEntryForRoute(nav, '/mijn/learniq/overview/sami')?.page.id,
		'overview',
		'a records page too',
	)
	assert.equal(
		navEntryForRoute(nav, '/mijn/learniq/parentGrades/x'),
		null,
		'not a record page',
	)
})

test('the menu draws a page icon before its label, decorative, in the Den Haag side navigation', async () => {
	const html = await renderComponent(inState(ResidentMenu, { icons: menuIcons }), {
		groups: [
			{
				key: 'learniq',
				title: 'Learniq',
				items: [
					{
						key: 'a',
						name: 'Afwezigheid',
						link: '/mijn/learniq/absence',
						href: '#',
						icon: 'Calendar',
					},
					{
						key: 'b',
						name: 'Onbekend',
						link: '/mijn/x',
						href: '#',
						icon: 'NoSuchIcon',
					},
				],
			},
		],
		currentRoute: '/mijn/learniq/absence',
	})
	assert.match(html, /class="denhaag-sidenav pq-resident-menu__group"/)
	assert.match(
		html,
		/aria-current="page"[^>]*><svg class="pq-resident-menu__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M/,
	)
	assert.match(html, /denhaag-sidenav__link--current/)
	assert.match(
		html,
		/<span class="denhaag-sidenav__link-label pq-resident-menu__label">Afwezigheid<\/span>/,
	)
	assert.equal(
		(html.match(/<svg /g) || []).length,
		1,
		'an unknown icon draws nothing',
	)
	assert.equal(typeof menuIcons.FolderAccount, 'string')
})

test('/mijn is the home: a greeting, then what is still to do', async () => {
	// REQ-SMO-007 scenario "Sanne's overview": open work comes first.
	const nav = buildNav([], t, { cases: true })
	assert.equal(
		accountRedirect(nav, '/mijn'),
		'',
		'the bare /mijn no longer redirects',
	)
	const html = await renderComponent(
		inState(MijnHome, {
			tasks: [
				{
					uuid: 't1',
					title: 'Beantwoord onze vraag over uw Woo-verzoek',
					dueAt: '2099-10-12T12:00:00',
				},
			],
			tasksFailed: false,
		}),
		{
			session: { displayName: 'Sanne de Vries' },
			nav,
			contributions: { contributions: [] },
			api: {},
			locale: 'nl',
		},
	)
	assert.match(html, /id="site-account-title"/)
	assert.match(html, /Welkom, Sanne/)
	assert.ok(html.indexOf('Dit moet u nog doen') > html.indexOf('Welkom, Sanne'))
	assert.ok(
		html.indexOf('Dit moet u nog doen') < html.indexOf('Nieuwe berichten'),
		'open work before messages',
	)
	assert.match(html, /Beantwoord onze vraag over uw Woo-verzoek/)
	assert.match(html, /Voor 12 oktober 2099/)
})

test('a portal with nothing to do shows the greeting and says so', async () => {
	// REQ-SMO-007 scenario "A portal with nothing to do".
	const html = await renderComponent(
		inState(MijnHome, { tasks: [], tasksFailed: false }),
		{
			session: { displayName: '999993653', subjectRef: 'bsn:999993653' },
			nav: [],
			contributions: { contributions: [] },
			api: {},
			locale: 'nl',
		},
	)
	assert.match(html, />Welkom<\/h1>/, 'never a number for a name')
	assert.match(html, /U hoeft nu niets te doen\./)
})

test('the home reads the tasks, says so when it cannot, and leaves the list out when home pages exist and nothing is open', async () => {
	const failing = instance(MijnHome, {
		api: { fetchTasks: async () => ({ results: [], total: 0, failed: true }) },
	})
	await failing.loadTasks()
	assert.equal(failing.tasksFailed, true)

	const ok = instance(MijnHome, {
		api: {
			fetchTasks: async () => ({
				results: [
					{ uuid: 'late', dueAt: '2099-12-01' },
					{ uuid: 'soon', dueAt: '2099-01-01' },
					{ uuid: 'none' },
				],
			}),
		},
	})
	await ok.loadTasks()
	assert.deepEqual(
		ok.tasks.map((task) => task.uuid),
		['soon', 'late', 'none'],
		'soonest first',
	)

	const withHome = instance(MijnHome, {
		nav: buildNav([LEARNIQ], t, {}),
		initialTasks: [],
	})
	assert.equal(withHome.homeEntries.length, 1)
	assert.equal(withHome.showTasks, false, 'nothing open, the home page speaks')
	const withoutHome = instance(MijnHome, { nav: [], initialTasks: [] })
	assert.equal(
		withoutHome.showTasks,
		true,
		'without a home page it says there is nothing to do',
	)
})

test('home pages are the pages marked home, in contribution order', () => {
	const nav = buildNav([LEARNIQ], t, { cases: true })
	assert.deepEqual(
		homeEntriesOf(nav).map((entry) => entry.page.id),
		['overview'],
	)
	assert.deepEqual(homeEntriesOf(null), [])
	assert.deepEqual(caseOverviewsOf(null, 'x'), [])
})

test("without a home page the home shows each app's running cases", () => {
	const ctx = instance(MijnHome, {
		contributions: {
			contributions: [
				{
					app: 'dossiq',
					collections: [
						{ id: 'mijnZaken', kind: 'cases' },
						{ id: 'berichten', kind: 'inbox' },
					],
				},
				{ app: 'learniq', collections: [{ id: 'x' }] },
			],
		},
		locale: 'nl',
	})
	assert.deepEqual(
		ctx.caseOverviews.map((overview) => [overview.key, overview.page.blocks]),
		[
			[
				'__home__:dossiq',
				[
					{
						type: 'cases',
						collection: 'mijnZaken',
						open: true,
						limit: 4,
						label: 'Lopende zaken',
					},
				],
			],
		],
	)
})

test('a records page shows a switcher as a radio group; the first record opens, a choice goes to its route', async () => {
	// REQ-SMO-008 scenario "A guardian switches child".
	const html = await renderComponent(RecordSwitcher, {
		rows: [...ROWS, { id: 'x' }],
		chosen: 'sami',
		titleFields: ['givenName'],
		legend: 'Kies voor wie',
	})
	assert.match(
		html,
		/^<fieldset class="pq-record-switcher"[^>]*><legend class="sr-only">Kies voor wie<\/legend>/,
	)
	assert.equal(
		(html.match(/type="radio"/g) || []).length,
		2,
		'a row without a name is no choice',
	)
	assert.match(html, /value="sami" checked/)
	assert.doesNotMatch(html, /value="vera" checked/)
	assert.match(
		html,
		/<span class="pq-record-switcher__initials" aria-hidden="true">V<\/span>/,
	)

	const page = LEARNIQ.pages[0]
	const entry = {
		key: 'learniq:overview',
		label: 'Overzicht',
		page,
		contribution: LEARNIQ,
	}
	const ctx = instance(ContributionPage, {
		entry,
		initialData: { parentChildren: { loading: false, objects: ROWS } },
		$nextTick: (fn) => fn(),
		$refs: {},
	})
	assert.equal(ctx.switching, true)
	assert.equal(ctx.activeRecord.id, 'vera', 'the first record opens')
	ctx.choose('sami')
	assert.equal(ctx.activeRecord.id, 'sami')
	assert.deepEqual(
		ctx.emitted,
		[['navigate', '/mijn/learniq/overview/sami']],
		'the choice is the route',
	)
	ctx.choose('nobody')
	assert.equal(ctx.emitted.length, 1, 'a row that is not there is no choice')
})

test('both headings that can own /mijn carry the same id and testid', async () => {
	// A live finding: MijnHome set only the id, so every test (and learniq's
	// parent-flow suite) that waits for data-testid="site-account-title"
	// timed out on the new home, while AccountArea's own heading carried
	// both. The two headings are interchangeable or neither is.
	const files = [
		'src/site/components/mijn/MijnHome.vue',
		'src/site/components/AccountArea.vue',
	]
	for (const file of files) {
		const source = await readFile(new URL('../' + file, import.meta.url), 'utf8')
		const at = source.indexOf('id="site-account-title"')
		assert.ok(at > -1, `${file} must still hold the heading`)
		assert.match(
			source.slice(at, at + 200),
			/data-testid="site-account-title"/,
			`${file} gives the heading its id but not its testid`,
		)
	}
})
