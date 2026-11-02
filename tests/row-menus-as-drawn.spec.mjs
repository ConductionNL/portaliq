#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// row-menus-as-drawn.spec.mjs: the admin lists' row menus open as the boards
// draw them. View comes first and Edit second (PtUitnodigingen,
// PtToegangsverzoeken, PtSessies), and the entry that takes something away is destructive, so the
// library draws it in red (Uitnodiging intrekken, Afwijzen met een reden,
// Sessie intrekken).
//
// Usage:
//   node --test tests/row-menus-as-drawn.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const manifest = JSON.parse(readFileSync(new URL('../src/manifest.json', import.meta.url), 'utf8'))
const actionsOf = (id) => manifest.pages.find((p) => p.id === id).config.actions

const lists = [
	{ page: 'Invitations', destructive: 'withdraw-invitation' },
	{ page: 'AccessRequests', destructive: 'refuse' },
	{ page: 'PortalSessions', destructive: 'revoke-session' },
]

for (const { page, destructive } of lists) {
	test(`${page}: View opens the row menu`, () => {
		assert.equal(actionsOf(page)[0], 'builtin:view')
	})

	test(`${page}: Edit stands second, before the list's own entries`, () => {
		assert.equal(actionsOf(page)[1], 'builtin:edit')
	})

	test(`${page}: the destructive entry is the last one`, () => {
		const last = actionsOf(page).at(-1)
		assert.equal(last.id, destructive)
	})

	test(`${page}: ${destructive} is destructive and the others are not`, () => {
		for (const action of actionsOf(page).filter((a) => typeof a === 'object')) {
			assert.equal(action.destructive === true, action.id === destructive, action.id)
		}
	})
}
