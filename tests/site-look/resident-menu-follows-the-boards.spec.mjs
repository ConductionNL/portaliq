#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// resident-menu-follows-the-boards.spec.mjs: the resident menu as the four
// school MijnMenu boards draw it (resident-menu-follows-the-boards): a
// declared item may carry the board's word, a page listed once per row
// places all its rows in the declared group, the menu may open with the
// person and their class, an item may have a second address, and the menu's
// look reads the theme's accent.
//
// Usage:
//   node --test tests/site-look/resident-menu-follows-the-boards.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { buildNav } from '../../src/shared/portalNav.js'
import { accountRedirect } from '../../src/site/lib/accountArea.js'
import {
	aliasedRoute,
	loadPerRecordRows,
	menuPerson,
	menuSubline,
	residentMenuGroups,
} from '../../src/site/lib/residentMenu.js'
import { loadSfc, renderComponent } from '../support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
function t(key) {
	return { Overview: 'Overzicht', Conversations: 'Gesprekken' }[key] || key
}
const href = (route) => `?route=${route}`
const ResidentMenu = await loadSfc('src/site/components/ResidentMenu.vue')

const LEARNIQ = {
	app: 'learniq',
	label: 'Learniq',
	collections: [
		{ id: 'parentChildren', register: 'learniq', schema: 'learner' },
		{ id: 'parentAbsence', register: 'learniq', schema: 'excuse' },
		{ id: 'studentEnrolments', register: 'learniq', schema: 'enrolment' },
	],
	pages: [
		{
			id: 'child',
			label: 'Mijn kind',
			group: 'Mijn kinderen',
			records: {
				collection: 'parentChildren',
				titleFields: ['givenName'],
				subtitleFields: ['cohortName', 'teacherName'],
			},
			perRecord: 'parentChildren',
			blocks: [{ type: 'richText', markdown: 'x' }],
		},
		{
			id: 'absence',
			label: 'Afwezigheid',
			blocks: [{ type: 'collection', collection: 'parentAbsence' }],
		},
	],
}

const ROWS = {
	'learniq:parentChildren': [
		{
			id: 'vera',
			givenName: 'Vera',
			cohortName: 'Groep 7',
			teacherName: 'Meester Daan',
		},
		{
			id: 'sami',
			givenName: 'Sami',
			cohortName: 'Groep 4',
			teacherName: 'Juf Esra',
		},
	],
	'learniq:studentEnrolments': [
		{ id: 'e1', levelLabel: '4 havo', groupName: 'klas H4b' },
	],
}

/** The wilgenboom board's layout, with the conversations named "Berichten". */
const LAYOUT = [
	{
		title: 'Mijn Wilgenboom',
		items: ['overview', { item: 'messages', label: 'Berichten' }],
	},
	{ title: 'Mijn kinderen', items: ['learniq:child'] },
	{ title: 'Regelen', items: ['learniq:absence'] },
]

const nav = () => buildNav([LEARNIQ], t, { messages: true })

test('a declared item carries the board word, and a page per row brings all its rows', () => {
	const groups = residentMenuGroups(nav(), t, 0, href, ROWS, LAYOUT)
	assert.deepEqual(
		groups
			.slice(0, 3)
			.map((group) => [group.title, group.items.map((item) => item.name)]),
		[
			['Mijn Wilgenboom', ['Overzicht', 'Berichten']],
			['Mijn kinderen', ['Vera', 'Sami']],
			['Regelen', ['Afwezigheid']],
		],
	)
	const children = groups[1].items
	assert.deepEqual(
		children.map((item) => item.subline),
		['Groep 7 · Meester Daan', 'Groep 4 · Juf Esra'],
	)
	assert.match(children[1].link, /\/mijn\/learniq\/child\/sami$/)
	// The label never renames a row of a page listed per row.
	const renamed = residentMenuGroups(nav(), t, 0, href, ROWS, [
		{ title: 'Kinderen', items: [{ item: 'learniq:child', label: 'Kind' }] },
	])
	assert.deepEqual(
		renamed[0].items.map((item) => item.name),
		['Vera', 'Sami'],
	)
})

test('without a layout the menu is what it was', () => {
	const groups = residentMenuGroups(nav(), t, 0, href, ROWS)
	const names = groups.flatMap((group) => group.items.map((item) => item.name))
	assert.ok(names.includes('Gesprekken'))
	assert.ok(names.includes('Vera'))
	assert.equal(names.includes('Berichten'), false)
})

test('the person block reads the name from the session and the class from the declared row', () => {
	const declared = {
		collection: 'learniq:studentEnrolments',
		fields: ['levelLabel', 'groupName'],
	}
	assert.deepEqual(menuPerson({ displayName: 'Noor Bakker' }, declared, ROWS), {
		initials: 'NB',
		name: 'Noor Bakker',
		subline: '4 havo · klas H4b',
	})
	assert.equal(menuPerson({ displayName: 'Noor Bakker' }, null, ROWS), null)
	assert.equal(menuPerson({ displayName: '123456789' }, declared, ROWS), null)
	assert.equal(menuPerson({ displayName: 'Noor' }, declared, {}).subline, '')
	assert.equal(menuSubline(declared, ROWS), '4 havo · klas H4b')
})

test('the person collection is read with the menu rows', async () => {
	const asked = []
	const api = {
		fetchCollection: async (collection) => {
			asked.push(collection.id)
			return []
		},
	}
	await loadPerRecordRows([LEARNIQ], api, [
		'learniq:studentEnrolments',
		'other:none',
	])
	assert.deepEqual(asked.sort(), ['parentChildren', 'studentEnrolments'])
})

test('a second address opens its item; any other unknown page opens the home', () => {
	const routes = {
		berichten: 'messages',
		afwezig: 'learniq:absence',
		weg: 'learniq:none',
	}
	assert.equal(aliasedRoute(nav(), '/mijn/berichten', routes), '/mijn/messages')
	assert.equal(
		aliasedRoute(nav(), '/mijn/afwezig', routes),
		'/mijn/learniq/absence',
	)
	assert.equal(aliasedRoute(nav(), '/mijn/weg', routes), '')
	assert.equal(aliasedRoute(nav(), '/mijn/berichten', null), '')
	assert.equal(accountRedirect(nav(), '/mijn/berichten', routes), '/mijn/messages')
	assert.equal(accountRedirect(nav(), '/mijn/documenten', routes), '/mijn')
	assert.equal(accountRedirect(nav(), '/mijn/learniq/absence', routes), '')
})

test('the menu renders the person block, and the card takes the second line', async () => {
	const groups = residentMenuGroups(nav(), t, 0, href, ROWS, LAYOUT)
	const html = await renderComponent(ResidentMenu, {
		groups,
		currentRoute: '/mijn',
		person: {
			initials: 'NB',
			name: 'Noor Bakker',
			subline: '4 havo · klas H4b',
		},
	})
	assert.match(
		html,
		/data-testid="site-resident-menu-person"[\s\S]*NB[\s\S]*Noor Bakker/,
	)
	assert.match(
		html,
		/data-testid="site-resident-menu-person-subline">4 havo · klas H4b</,
	)
	// The overview is the page on screen.
	assert.match(html, /aria-current="page"[^>]*>[\s\S]*?Overzicht/)

	const withCard = await renderComponent(ResidentMenu, {
		groups,
		person: { initials: 'LJ', name: 'Linda Jansen', subline: '' },
		card: {
			label: 'U regelt het voor',
			title: 'Jansen BV',
			subline: '4 medewerkers · via eHerkenning',
		},
	})
	assert.equal(
		withCard.includes('site-resident-menu-person"'),
		false,
		'the card stands in its place',
	)
	assert.match(withCard, /4 medewerkers · via eHerkenning/)
})

test('the look reads the theme: accent wash for the page on screen, the badge roles, a second line under the name', () => {
	const source = readFileSync(
		join(ROOT, 'src/site/components/ResidentMenu.vue'),
		'utf8',
	)
	const rule = (selector) => {
		const start = source.indexOf(`${selector} {`)
		assert.ok(start > -1, `${selector} is styled`)
		return source.slice(start, source.indexOf('}', start))
	}
	assert.match(
		rule('.pq-resident-menu__link--current'),
		/--thematiq-accent-light-color/,
	)
	assert.match(
		rule('.pq-resident-menu__link--current'),
		/--thematiq-accent-text-color/,
	)
	assert.match(
		rule('.pq-resident-menu__badge'),
		/--thematiq-badge-background-color/,
	)
	assert.match(rule('.pq-resident-menu__subline'), /display: block/)
	assert.doesNotMatch(rule('.pq-resident-menu__link'), /overflow-wrap: anywhere/)
	assert.doesNotMatch(
		source.slice(source.indexOf('<style scoped>')),
		/#[0-9a-f]{3,6}\b/i,
	)
})

test('the site hands the person block and the second addresses on', () => {
	const app = readFileSync(join(ROOT, 'src/site/App.vue'), 'utf8')
	assert.match(app, /:menuPerson="residentMenuPerson"/)
	assert.match(app, /this\.site\?\.residentMenu\?\.routes/)
	assert.match(app, /this\.site\?\.residentMenu\?\.person\?\.collection/)
})
