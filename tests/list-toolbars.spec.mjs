#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// list-toolbars.spec.mjs: every list a portaliq board draws carries the
// board's toolbar: a Filter button and the four-way view switch (Tabel,
// Kaarten, Bord, Kaart). Under the board look a mode a list cannot open is
// drawn disabled and named as not available, so the switch reads the same on
// every list while only working modes act.
//
// Usage:
//   node --test tests/list-toolbars.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const MANIFEST = JSON.parse(readFileSync(new URL('../src/manifest.json', import.meta.url), 'utf8'))
const config = (id) => MANIFEST.pages.find((page) => page.id === id).config

// The list pages behind PtAccounts, PtUitnodigingen, PtSessies, PtInzendingen,
// PtPortals, PtToegangsverzoeken, PtAanvraagformulieren, PtBegrippen, PtMedia
// and PtNieuws.
const BOARD_LISTS = [
	'PortalAccounts',
	'Invitations',
	'PortalSessions',
	'PortalSubmissions',
	'Portals',
	'AccessRequests',
	'FormBindings',
	'Glossary',
	'Media',
	'News',
]

test('the manifest draws in the board look', () => {
	assert.equal(MANIFEST.look, 'board')
})

for (const id of BOARD_LISTS) {
	test(`${id} has the Filter button and the four-way view switch`, () => {
		assert.equal(config(id).filterMenu, true)
		assert.deepEqual(config(id).viewSwitch, ['table', 'cards', 'board', 'map'])
	})
}
