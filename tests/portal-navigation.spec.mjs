#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-navigation.spec.mjs: the "Navigation" section on a portal's page
// and the "Pages hidden for this client" field on an account's page
// (operate-pages-per-portal-and-client T03, T05) move pages, send the
// navigation and the hidden list in the stored shape, and are placed on
// their pages.
//
// Usage:
//   node --test tests/portal-navigation.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	accountPagesUrl,
	copyAudiences,
	hiddenPagesFrom,
	movePage,
	navigationFrom,
	navigationUrl,
} from '../src/lib/portalNavigation.js'

const PAGES = [
	{ page: 'pipelinq:invoices', label: 'Invoices', hidden: false },
	{ page: 'dossiq:cases', label: 'My cases', hidden: false },
	{ page: 'pipelinq:quotes', label: 'Quotes', hidden: true },
]

test('the routes name the portal and the account, encoded', () => {
	const generate = (path) => `/index.php${path}`
	assert.equal(
		navigationUrl('open tilburg', generate),
		'/index.php/apps/portaliq/api/portals/open%20tilburg/navigation',
	)
	assert.equal(
		accountPagesUrl('ref/1', generate),
		'/index.php/apps/portaliq/api/accounts/ref%2F1/pages',
	)
})

test('moving "My cases" up puts it above "Invoices" and leaves the list given alone', () => {
	const moved = movePage(PAGES, 1, -1)
	assert.deepEqual(
		moved.map((page) => page.page),
		['dossiq:cases', 'pipelinq:invoices', 'pipelinq:quotes'],
	)
	assert.equal(PAGES[0].page, 'pipelinq:invoices')
	assert.deepEqual(
		movePage(PAGES, 1, 1).map((page) => page.page),
		['pipelinq:invoices', 'pipelinq:quotes', 'dossiq:cases'],
	)
})

test('a move past either end changes nothing', () => {
	assert.deepEqual(movePage(PAGES, 0, -1), PAGES)
	assert.deepEqual(movePage(PAGES, 2, 1), PAGES)
	assert.deepEqual(movePage(PAGES, 7, -1), PAGES)
	assert.deepEqual(movePage(null, 0, 1), [])
})

test('the save sends every page per audience in its order as {page, hidden}', () => {
	assert.deepEqual(
		navigationFrom([
			{ audience: 'client', pages: [...PAGES, { label: 'no key' }] },
			{ audience: 'supplier', pages: [] },
			{ pages: PAGES },
		]),
		{
			client: [
				{ page: 'pipelinq:invoices', hidden: false },
				{ page: 'dossiq:cases', hidden: false },
				{ page: 'pipelinq:quotes', hidden: true },
			],
			supplier: [],
		},
	)
	assert.deepEqual(navigationFrom(null), {})
})

test('an account save sends only the hidden page keys', () => {
	assert.deepEqual(hiddenPagesFrom(PAGES), ['pipelinq:quotes'])
	assert.deepEqual(
		hiddenPagesFrom([{ page: 'x:y', hidden: 'yes' }, { hidden: true }]),
		[],
	)
	assert.deepEqual(hiddenPagesFrom(undefined), [])
})

test('a copy of the audiences is independent of the stored state', () => {
	const stored = [{ audience: 'client', pages: PAGES }]
	const copy = copyAudiences(stored)
	copy[0].pages[0].hidden = true
	assert.equal(stored[0].pages[0].hidden, false)
})

test('the portal page has a Navigation section and the account page the hidden pages field', () => {
	const manifest = JSON.parse(
		readFileSync(new URL('../src/manifest.json', import.meta.url), 'utf8'),
	)
	const page = (id) => manifest.pages.find((entry) => entry.id === id)
	const portal = page('PortalDetail').config
	const settings = portal.widgets.find((widget) => widget.id === 'portal-settings')
	const section = settings.content.sections.find((entry) => entry.key === 'navigation')
	assert.ok(section, 'the portal settings list a navigation section')
	const widget = portal.widgets.find((entry) => entry.id === section.widgetId)
	assert.equal(widget.type, 'PortalNavigation')

	const account = page('PortalAccountDetail').config
	const field = account.widgets.find((entry) => entry.type === 'PortalAccountPages')
	assert.ok(field, 'the account page carries the hidden pages widget')
	assert.ok(
		account.layout.some((cell) => cell.widgetId === field.id),
		'the hidden pages widget is placed in the layout',
	)
})

test('the section says that hiding a page on a portal is not access control', () => {
	const source = readFileSync(
		new URL('../src/widgets/PortalNavigation.vue', import.meta.url),
		'utf8',
	)
	assert.match(
		source,
		/Hidden pages are left out of this portal's menu\. To stop one client reading them, hide them on the client's account\./,
	)
})
