#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// resident-menu.golden.spec.mjs: pins the resident menu's groups, its layout
// and the item it marks, over every kind of entry
// (tests/Fixtures/Golden/resident-menu.json).
//
// Usage:
//   node --test tests/golden/resident-menu.golden.spec.mjs

import { test } from 'node:test'
import {
	laidOut,
	menuCurrentRoute,
	residentMenuGroups,
} from '../../src/site/lib/residentMenu.js'
import { golden } from '../support/golden.mjs'

const t = (key, vars = {}) => `[${key}]${Object.entries(vars).map(([k, v]) => `${k}=${v}`).join(',')}`
const href = (route) => `/site${route}`

const special = (key, label, icon) => ({ key, special: key, label, ...(icon ? { icon } : {}) })
const page = (app, label, id, extra = {}) => ({
	key: `${app}:${id}`,
	label,
	contribution: { app, label: app === 'dossiq' ? 'Dossiq' : '' },
	page: { id, ...extra },
})

const NAV = [
	special('cases', 'Mijn zaken', 'folder'),
	special('tasks', 'Mijn taken'),
	special('access', 'Toegang'),
	special('samenwerken', 'Samenwerken'),
	special('inbox', 'Berichtenbox', 'mail'),
	special('news', 'Nieuws'),
	special('details', 'Mijn gegevens'),
	special('account', 'Account'),
	{ key: 'thema/parkeren', special: 'thema/parkeren', label: 'Parkeren', theme: { slug: 'parkeren' } },
	page('dossiq', 'Mijn zaken', 'cases', { record: { collection: 'cases' } }),
	page('dossiq', 'Mijn verzoeken', 'requests', { icon: 'x', group: ' Verzoeken ' }),
	page('pipelinq', 'Verzoeken', 'leads', { group: 'verzoeken' }),
	page('learniq', 'Kind', 'child', { perRecord: 'children', record: { titleFields: ['givenName'], subtitleFields: ['school'] } }),
	page('learniq', 'Kinderen', 'kids', { perRecord: 'children', group: 'Mijn kinderen', records: { subtitleFields: ['school', 'class'] } }),
	page('learniq', 'Gesprekken', 'talks', { badge: { collection: 'talks', label: '{count} gepland' } }),
	page('learniq', 'Verborgen', 'hidden', { menu: false }),
	page('dossiq', 'Archief', 'archive', { menu: false, blocks: [{ collection: 'cases' }, null] }),
	{ ...page('shillinq', 'Facturen', 'invoices'), icon: 'euro' },
]

const ROWS = {
	'learniq:children': [
		{ id: 'c1', givenName: 'Vera', name: 'Vera V', school: 'De Wilg', class: '3a' },
		{ uuid: 'c 2', givenName: 'Sami', school: '' },
		{ '@self': { id: 'c3' }, title: 'Derde' },
		{ id: '', givenName: 'Niemand' },
		{ id: 'c5' },
	],
	'learniq:talks': [{ id: 't1' }, { id: 't2' }],
}

const LAYOUT = [
	{ title: 'Zaken en taken', items: ['overview', 'cases', 'tasks', 'learniq:talks', 'unknown'] },
	{ title: 'Leeg', items: ['nothing'] },
	{ title: ' [Your details and account] ', items: ['details'] },
	{ items: ['inbox', 'cases'] },
]

const CASES = {
	contributions: [{ app: 'dossiq', collections: [{ id: 'cases', kind: 'cases' }] }],
}

const MENUS = {
	plain: () => residentMenuGroups(NAV, { t, unread: 0, hrefFor: href }),
	unread: () => residentMenuGroups(NAV, { t, unread: 3, hrefFor: href }),
	rows: () => residentMenuGroups(NAV, { t, unread: 0, hrefFor: href, recordRows: ROWS }),
	tasks: () => residentMenuGroups(NAV, { t, unread: 2, hrefFor: href, recordRows: ROWS, layout: null, leaveOut: null, openTasks: 4 }),
	layout: () => residentMenuGroups(NAV, { t, unread: 2, hrefFor: href, recordRows: ROWS, layout: LAYOUT, leaveOut: null, openTasks: 4 }),
	leaveOut: () => residentMenuGroups(NAV, { t, unread: 0, hrefFor: href, recordRows: ROWS, layout: null, leaveOut: ['tasks', 'dossiq:requests', 'thema/parkeren', 'news', 'details', 'account'] }),
	both: () => residentMenuGroups(NAV, { t, unread: 1, hrefFor: href, recordRows: ROWS, layout: LAYOUT, leaveOut: ['cases', 'learniq:kids'] }),
	empty: () => residentMenuGroups(null, { t, unread: 0, hrefFor: href }),
	noRowsKnown: () => residentMenuGroups(NAV, { t, unread: 0, hrefFor: href, recordRows: {}, layout: [], leaveOut: [], openTasks: 0 }),
}

test('the resident menu is built as pinned', () => {
	const file = golden('resident-menu')
	for (const [name, build] of Object.entries(MENUS)) {
		const groups = build()
		file.check(`groups:${name}`, groups)
		for (const route of ['/mijn/dossiq/cases', '/mijn/dossiq/cases/z1', '/mijn/cases/abc', '/mijn/learniq/child/c1/x', '/mijn', '', '/elders', '/mijn/shillinq/invoices', '/mijn/dossiq/archive/1', '/mijn/learniq/hidden']) {
			file.check(`current:${name}:${route}`, menuCurrentRoute(route, { groups, nav: NAV, contributions: CASES }))
		}
	}
	const plain = residentMenuGroups(NAV, { t, unread: 0, hrefFor: href })
	file.check('current:noNav', menuCurrentRoute('/mijn/dossiq/cases', plain))
	file.check('current:nullGroups', menuCurrentRoute('/mijn/x', { groups: null, nav: NAV, contributions: null }))
	file.check('laidOut:none', laidOut(plain, { layout: null, t, hrefFor: href }))
	file.check('laidOut:empty', laidOut(plain, { layout: [], t, hrefFor: href }))
	file.check('laidOut:layout', laidOut(plain, { layout: LAYOUT, t, hrefFor: href }))
	file.save()
})
