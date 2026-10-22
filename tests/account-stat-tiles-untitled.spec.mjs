#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// account-stat-tiles-untitled.spec.mjs: the account page's two session tiles
// draw no card title, as board PtAccount draws them: the tile's own label
// ("Uitgegeven sessies", "Actieve sessies") names the number, so the label
// moves from the card title into the tile instead of being lost.
//
// Usage:
//   node --test tests/account-stat-tiles-untitled.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const manifest = JSON.parse(readFileSync(join(ROOT, 'src', 'manifest.json'), 'utf8'))
const page = manifest.pages.find((p) => p.id === 'PortalAccountDetail')

for (const [id, label] of [['account-stats-sessions-issued', 'Uitgegeven sessies'], ['account-stats-active-sessions', 'Actieve sessies']]) {
	test(`${id} draws no card title and labels its tile "${label}"`, () => {
		const entry = page.config.layout.find((l) => l.widgetId === id)
		assert.equal(entry.showTitle, false)
		const widget = page.config.widgets.find((w) => w.id === id)
		assert.equal(widget.content.entries[0].title, label)
	})
}
