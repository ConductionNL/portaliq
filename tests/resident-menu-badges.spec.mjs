#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// resident-menu-badges.spec.mjs: the resident menu as the school designs draw
// it (resident-menu-badges-and-cards): a per-record page in a group lists each
// row with its subtitle, a page may count the rows of a collection, the phone
// button carries the total, and the menu may open with whom the resident acts for.
//
// Usage:
//   node --test tests/resident-menu-badges.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { buildNav } from '../src/shared/portalNav.js'
import {
	loadPerRecordRows,
	residentMenuGroups,
} from '../src/site/lib/residentMenu.js'
import { t } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const ResidentMenu = await loadSfc('src/site/components/ResidentMenu.vue')
const href = (route) => `?route=${route}`

const LEARNIQ = {
	app: 'learniq',
	label: 'Learniq',
	collections: [
		{ id: 'parentChildren', register: 'learniq', schema: 'learner' },
		{ id: 'conferenceTasks', register: 'learniq', schema: 'task' },
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
			id: 'conferences',
			label: 'Oudergesprekken',
			group: 'Regelen',
			badge: { collection: 'conferenceTasks', label: '{count} te plannen' },
			blocks: [{ type: 'collection', collection: 'conferenceTasks' }],
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
		{ id: 'sami', givenName: 'Sami', cohortName: 'Groep 4' },
	],
	'learniq:conferenceTasks': [{ id: 't1' }],
}

test('a per-record page in a group lists each row as an item with its subtitle', () => {
	const groups = residentMenuGroups(buildNav([LEARNIQ], t, {}), t, 0, href, ROWS)
	const children = groups.find((group) => group.title === 'Mijn kinderen')
	assert.ok(children, 'one group named after the page group')
	assert.deepEqual(
		children.items.map((item) => [item.name, item.subline]),
		[
			['Vera', 'Groep 7 · Meester Daan'],
			['Sami', 'Groep 4'],
		],
	)
	assert.match(children.items[0].link, /\/vera$/)
	assert.equal(
		groups.some((group) => group.title === 'Vera'),
		false,
		'no group per row',
	)
})

test('without a group a per-record page keeps a group per row, as before', () => {
	const plain = structuredClone(LEARNIQ)
	delete plain.pages[0].group
	const groups = residentMenuGroups(buildNav([plain], t, {}), t, 0, href, ROWS)
	assert.ok(groups.some((group) => group.title === 'Vera'))
	assert.equal(
		groups.find((group) => group.title === 'Vera').items[0].subline,
		undefined,
	)
})

test('a page counts the rows of the collection its badge names, once they are known', () => {
	const counted = residentMenuGroups(buildNav([LEARNIQ], t, {}), t, 0, href, ROWS)
	const item = counted
		.flatMap((group) => group.items)
		.find((entry) => entry.name === 'Oudergesprekken')
	assert.equal(item.badge, '1')
	assert.equal(item.badgeLabel, '1 te plannen')

	const unknown = residentMenuGroups(buildNav([LEARNIQ], t, {}), t, 0, href, {})
	const none = unknown
		.flatMap((group) => group.items)
		.find((entry) => entry.name === 'Oudergesprekken')
	assert.equal(none.badge, undefined)
})

test('the badge collection is loaded with the per-record ones', async () => {
	const asked = []
	const api = {
		fetchCollection: async (collection) => {
			asked.push(collection.id)
			return []
		},
	}
	await loadPerRecordRows([LEARNIQ], api)
	assert.deepEqual(asked.sort(), ['conferenceTasks', 'parentChildren'])
})

test('the menu renders sublines, the organisation card and the total on the phone button', async () => {
	// Two unread messages and one conference to plan: three new.
	const groups = residentMenuGroups(buildNav([LEARNIQ], t, {}), t, 2, href, ROWS)
	const html = await renderComponent(ResidentMenu, {
		groups,
		showLabel: 'Mijn Wilgenboom',
		newLabel: '{count} nieuw',
		card: { label: 'U regelt het voor', title: 'Jansen Installatietechniek BV' },
	})
	assert.match(
		html,
		/data-testid="site-resident-menu-subline">Groep 7 · Meester Daan</,
	)
	assert.match(
		html,
		/data-testid="site-resident-menu-card"[\s\S]*U regelt het voor[\s\S]*Jansen Installatietechniek BV/,
	)
	assert.match(
		html,
		/Mijn Wilgenboom[\s\S]*data-testid="site-resident-menu-toggle-badge">\s*3 nieuw/,
	)
	const bare = await renderComponent(ResidentMenu, { groups: [] })
	assert.equal(bare.includes('site-resident-menu-card'), false)
	assert.equal(bare.includes('site-resident-menu-toggle-badge'), false)
})
