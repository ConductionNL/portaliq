#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// open-record.spec.mjs: a notification's link opens the record it is about
// (inbox-notifications-and-preferences, REQ-NAP-005). The portal reads and
// strips `#open=<app>/<collection>/<id>`, keeps it through a sign-in in
// sessionStorage, and finds the page that shows that collection. The inbox's
// Open button and the settings section are wired in.
//
// Usage:
//   node --test tests/open-record.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	consumeOpenTarget,
	forgetOpenTarget,
	navKeyFor,
	OPEN_STORAGE_KEY,
	parseOpenFragment,
	rowFor,
} from '../src/shared/openRecord.js'

/**
 * A sessionStorage stand-in.
 *
 * @return {object}
 */
function storage() {
	const map = new Map()
	return {
		getItem: (k) => (map.has(k) ? map.get(k) : null),
		setItem: (k, v) => map.set(k, String(v)),
		removeItem: (k) => map.delete(k),
		map,
	}
}

test('the fragment names the app, the collection and the record', () => {
	assert.deepEqual(parseOpenFragment('#open=dossiq/mijnZaken/zaak%201'), {
		app: 'dossiq',
		collection: 'mijnZaken',
		id: 'zaak 1',
	})
	assert.equal(parseOpenFragment('#token=abc'), null)
	assert.equal(parseOpenFragment('#open=dossiq/mijnZaken'), null)
	assert.equal(parseOpenFragment(''), null)
})

test('the fragment is stripped and kept through a sign-in', () => {
	const store = storage()
	const replaced = []
	const location = {
		hash: '#open=dossiq/mijnZaken/z-1',
		pathname: '/apps/portaliq/portal',
		search: '?org=venray',
	}
	const history = { replaceState: (_s, _t, url) => replaced.push(url) }

	const target = consumeOpenTarget(location, history, store)
	assert.deepEqual(target, { app: 'dossiq', collection: 'mijnZaken', id: 'z-1' })
	assert.deepEqual(
		replaced,
		['/apps/portaliq/portal?org=venray'],
		'the address bar loses the fragment',
	)
	assert.ok(
		store.getItem(OPEN_STORAGE_KEY),
		'the target is kept for after the sign-in',
	)

	// Back from the sign-in: no fragment, the kept target comes back.
	const again = consumeOpenTarget(
		{ hash: '', pathname: '/apps/portaliq/portal', search: '' },
		history,
		store,
	)
	assert.deepEqual(again, target)

	forgetOpenTarget(store)
	assert.equal(
		consumeOpenTarget({ hash: '', pathname: '/', search: '' }, history, store),
		null,
	)
})

test('the page that shows the collection of that app is found', () => {
	const nav = [
		{
			key: 'dossiq:home',
			page: { blocks: [{ type: 'richText' }] },
			contribution: { app: 'dossiq' },
		},
		{
			key: 'filinq:docs',
			page: { blocks: [{ type: 'collection', collection: 'mijnZaken' }] },
			contribution: { app: 'filinq' },
		},
		{
			key: 'dossiq:cases',
			page: { blocks: [{ type: 'citizenCase', collection: 'mijnZaken' }] },
			contribution: { app: 'dossiq' },
		},
		{ key: '__inbox__', special: 'inbox' },
	]
	assert.equal(
		navKeyFor(nav, { app: 'dossiq', collection: 'mijnZaken', id: 'z-1' }),
		'dossiq:cases',
	)
	assert.equal(
		navKeyFor(nav, { app: 'dossiq', collection: 'elders', id: 'z-1' }),
		null,
	)
})

test("only a row in the resident's own scoped list is opened", () => {
	const objects = [{ id: 'z-1', status: 'Ontvangen' }, { '@self': { id: 'z-2' } }]
	assert.equal(rowFor(objects, 'z-2'), objects[1])
	assert.equal(rowFor(objects, 'someone-elses'), null)
})

test('the shell, the inbox and the page view are wired to it', () => {
	const app = readFileSync(
		new URL('../src/portal/App.jsx', import.meta.url),
		'utf8',
	)
	assert.match(app, /consumeOpenTarget\(/)
	assert.match(app, /onOpenRecord=/)
	const inbox = readFileSync(
		new URL('../src/site/pages/inbox/InboxPage.vue', import.meta.url),
		'utf8',
	)
	assert.match(inbox, /recordLink/)
	assert.match(inbox, /NotificationSettings/)
	const page = readFileSync(
		new URL('../src/portal/components/PageView.jsx', import.meta.url),
		'utf8',
	)
	assert.match(page, /openRecord/)
	for (const locale of ['en', 'nl']) {
		const bundle = JSON.parse(
			readFileSync(
				new URL(`../src/shared/i18n/${locale}.json`, import.meta.url),
				'utf8',
			),
		)
		for (const key of [
			'Open',
			'Notification settings',
			'Changes on your cases',
			'New messages',
			'E-mail',
			'Push',
			'Save',
			'Your choices are saved.',
			'Your choices could not be saved. Try again.',
			'This record is not in your list, so nothing of it is shown.',
		]) {
			assert.ok(bundle[key], `${locale} has "${key}"`)
		}
	}
})

// The site's half (site-reaches-portal-parity slice b, REQ-SRP-021): the
// shell asks openRecordEntry() which page to open, and the contribution page
// selects the row once its collection has loaded.

const { openRecordEntry } = await import('../src/site/pages/collections/index.js')

const siteNav = [
	{ key: '__cases__', special: 'cases' },
	{
		key: 'learniq:absences',
		contribution: { app: 'learniq' },
		page: { id: 'absences', blocks: [{ type: 'collection', collection: 'parentExcuseRequests' }] },
	},
]

test('site: a link kept across the sign-in opens the page that shows its collection', () => {
	const store = storage()
	store.setItem(OPEN_STORAGE_KEY, JSON.stringify({ app: 'learniq', collection: 'parentExcuseRequests', id: 'x1' }))

	const entry = openRecordEntry(siteNav, { location: { hash: '', pathname: '/site', search: '' }, history: null, storage: store })

	assert.equal(entry.key, 'learniq:absences')
	assert.ok(store.map.has(OPEN_STORAGE_KEY), 'kept until the page has selected the row')
})

test('site: a link in the address is stripped, and one no page shows is forgotten', () => {
	const store = storage()
	const replaced = []
	const entry = openRecordEntry(siteNav, {
		location: { hash: '#open=learniq/elsewhere/x1', pathname: '/apps/portaliq/site', search: '?portal=wilgenboom' },
		history: { replaceState: (...args) => replaced.push(args) },
		storage: store,
	})

	assert.equal(entry, null)
	assert.deepEqual(replaced, [[null, '', '/apps/portaliq/site?portal=wilgenboom']])
	assert.equal(store.map.has(OPEN_STORAGE_KEY), false)
})

test('site: the contribution page selects the row from the resident\'s own rows, or says it is not there', () => {
	const page = readFileSync(new URL('../src/site/pages/collections/ContributionPage.vue', import.meta.url), 'utf8')
	assert.match(page, /openRecordState\(/)
	assert.match(page, /forgetOpenTarget\(sessionStore\(\)\)/)
	assert.match(page, /tr\('This record is not in your list, so nothing of it is shown\.'\)/)
	const loader = readFileSync(new URL('../src/site/pages/collections/collectionLoader.js', import.meta.url), 'utf8')
	assert.match(loader, /rowFor\(loaded\.objects, target\.id\) \|\| target\.row \|\| null/)
})
