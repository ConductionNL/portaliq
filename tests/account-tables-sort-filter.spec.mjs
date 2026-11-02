#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// account-tables-sort-filter.spec.mjs: on an account's page the Sessions and
// Messages tables sort and filter from their column heads, as the PtAccount
// board draws them (a sort and a filter button in every head cell).
//
// Usage:
//   node --test tests/account-tables-sort-filter.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const manifest = JSON.parse(readFileSync(new URL('../src/manifest.json', import.meta.url), 'utf8'))
const page = manifest.pages.find((p) => p.id === 'PortalAccountDetail')

for (const id of ['account-sessions', 'account-messages']) {
	test(`${id} sorts and filters from its heads`, () => {
		const widget = page.config.widgets.find((w) => w.id === id)
		assert.ok(widget, `${id} is on the account page`)
		assert.equal(widget.content.sortable, true)
		assert.equal(widget.content.filterable, true)
	})
}
