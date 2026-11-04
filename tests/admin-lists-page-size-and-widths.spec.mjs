#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// admin-lists-page-size-and-widths.spec.mjs: the staff lists show as many
// rows per page as their boards draw and give each column the board's width,
// so a head label stays on one line where the board draws it so
// (PtNieuws, PtUitnodigingen, PtToegangsverzoeken, PtPortals, PtInzendingen,
// PtAccounts).
//
// Usage:
//   node --test tests/admin-lists-page-size-and-widths.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const manifest = JSON.parse(readFileSync(new URL('../src/manifest.json', import.meta.url), 'utf8'))

/**
 * The config of the page with this id.
 *
 * @param {string} id The page id.
 * @return {object} The page's config.
 */
function config(id) {
	const page = manifest.pages.find((p) => p.id === id)
	assert.ok(page, `page ${id} exists`)
	return page.config
}

/**
 * The width of each column of a page, by key.
 *
 * @param {string} id The page id.
 * @return {object} Column key to width.
 */
function widths(id) {
	return Object.fromEntries(config(id).columns.map((c) => [c.key, c.width]))
}

const pageSizes = {
	News: 6,
	Invitations: 6,
	AccessRequests: 8,
	PortalSubmissions: 8,
	PortalAccounts: 7,
}

for (const [id, limit] of Object.entries(pageSizes)) {
	test(`${id} shows ${limit} rows per page, as its board`, () => {
		assert.deepEqual(config(id).pagination, { limit })
	})
}

test('PtPortals keeps the default page size: the board lists every portal on one page', () => {
	assert.equal(config('Portals').pagination, undefined)
})

test('every column of the staff lists carries the board\'s width', () => {
	assert.deepEqual(widths('News'), { title: '340px', target: '216px', status: '131px', publishedAt: '181px', authorRef: '124px' })
	assert.deepEqual(widths('Invitations'), { email: '229px', state: '133px', sentAt: '163px', expiresAt: '131px', invitedBy: '187px', organisation: '150px' })
	assert.deepEqual(widths('AccessRequests'), { displayName: '163px', onBehalfOf: '195px', reason: '262px', requestedAt: '178px', state: '194px' })
	assert.deepEqual(widths('Portals'), { title: '142px', kind: '129px', theme: '119px', organisation: '153px', pages: '128px', '@self.updated': '191px', status: '131px' })
	assert.deepEqual(widths('PortalSubmissions'), { actionId: '339px', appId: '134px', submittedAt: '212px', deliveryStatus: '286px' })
	assert.deepEqual(widths('PortalAccounts'), { displayName: '304px', audience: '152px', identityType: '155px', status: '197px', lastLoginAt: '186px' })
})

test('PtUitnodigingen names its rows as bold text without an underline', () => {
	assert.equal(config('Invitations').rowTitle, 'plain')
})
