#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// news-dialog-eyebrow.spec.mjs: the news dialog names the list and the item's
// state above its title, as the PtNieuwsbericht board draws it.
//
// Usage:
//   node --test tests/news-dialog-eyebrow.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const dialog = readFileSync(new URL('../src/dialogs/NewsItemDialog.vue', import.meta.url), 'utf8')
const nl = JSON.parse(readFileSync(new URL('../l10n/nl.json', import.meta.url), 'utf8')).translations

test('the dialog passes its eyebrow to CnDialog', () => {
	assert.match(dialog, /<CnDialog[^>]*:eyebrow="eyebrow"/s)
})

test('the eyebrow names the list, and the state of an item that exists', () => {
	for (const key of ["t('portaliq', 'News')", "t('portaliq', 'News, draft')", "t('portaliq', 'News, published')"]) {
		assert.ok(dialog.includes(key), key)
	}
})

test('the eyebrows read in Dutch as the board draws them', () => {
	assert.equal(nl.News, 'Nieuws')
	assert.equal(nl['News, draft'], 'Nieuws, concept')
	assert.equal(nl['News, published'], 'Nieuws, gepubliceerd')
})
