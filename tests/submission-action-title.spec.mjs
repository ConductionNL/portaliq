#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// submission-action-title.spec.mjs: the Submissions list names each row by
// the action the resident read (board PtInzendingen: "Melding openbare
// ruimte"), not by its declared id ("createExample"). A row carries that name
// as `actionTitle`; a row written before it falls back to the id.
//
// Usage:
//   node --test tests/submission-action-title.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { actionTitleOf } from '../src/lib/actionTitle.js'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const root = new URL('../', import.meta.url)
const read = (path) => readFileSync(new URL(path, root), 'utf8')

test('a submission row reads by its action title, else by its action id', () => {
	assert.equal(actionTitleOf({ actionId: 'createMelding', actionTitle: 'Melding openbare ruimte' }), 'Melding openbare ruimte')
	assert.equal(actionTitleOf({ actionId: 'createExample', actionTitle: '  ' }), 'createExample')
	assert.equal(actionTitleOf({ actionId: 'createExample' }), 'createExample')
	assert.equal(actionTitleOf(null), '')
})

test('the register keeps the title on the submission', () => {
	const schema = JSON.parse(read('lib/Settings/portaliq_register.json')).components.schemas.portalSubmission
	assert.equal(schema.properties.actionTitle.type, 'string')
	assert.ok(!schema.required.includes('actionTitle'), 'a row written before the title still fits')
})

test('the Submissions column draws the title through the action-title cell widget', () => {
	const manifest = JSON.parse(read('src/manifest.json'))
	const page = manifest.pages.find((entry) => entry.id === 'PortalSubmissions')
	const column = page.config.columns.find((entry) => entry.key === 'actionId')
	assert.equal(column.widget, 'action-title')
	assert.match(read('src/App.vue'), /'action-title': ActionTitleCell/)
})

test('the cell shows the row\'s title', async () => {
	const cell = await loadSfc('src/cellRenderers/ActionTitleCell.vue')
	const html = await renderComponent(cell, {
		value: 'createMelding',
		row: { actionId: 'createMelding', actionTitle: 'Melding openbare ruimte' },
	})
	assert.match(html, /Melding openbare ruimte/)
	assert.doesNotMatch(html, /createMelding/)
})
