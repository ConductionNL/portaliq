#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// account-dialogs-board-form.spec.mjs: the invite and issue-account dialogs
// draw the boards' form (PtUitnodigen, PtAccountUitgeven): the audience is a
// select over the shared audience options, the closing sentence is an info
// note, and the issue-account fields sit in a two-column grid.
//
// Usage:
//   node --test tests/account-dialogs-board-form.spec.mjs
//
// @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-issue-and-withdraw-accounts-through-the-validated-actions-req-isa-003

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const read = (rel) => readFileSync(join(ROOT, rel), 'utf8')
const INVITE = read('src/dialogs/InviteDialog.vue')
const ISSUE = read('src/dialogs/IssueAccountDialog.vue')
const OPTIONS = read('src/lib/audienceOptions.js')

test('both dialogs pick the audience from the shared options', () => {
	for (const source of [INVITE, ISSUE]) {
		assert.match(source, /<NcSelect\s+v-model="audience"\s+:options="audiences"/)
		assert.match(source, /from '\.\.\/lib\/audienceOptions\.js'/)
		assert.match(source, /<NcNoteCard type="info"/)
	}
	for (const id of ['citizen', 'client', 'organisation', 'supplier']) {
		assert.match(OPTIONS, new RegExp(`id: '${id}'`))
	}
})

test('an audience the options do not know is kept', () => {
	assert.match(OPTIONS, /\|\| \{ id, label: id \}/)
	assert.match(OPTIONS, /typeof option === 'string' \? option : option\?\.id \|\| ''/)
})

test('the issue-account fields sit in a two-column grid, the check spanning it', () => {
	assert.match(ISSUE, /class="issue__grid"/)
	assert.match(ISSUE, /grid-template-columns: repeat\(auto-fit, minmax\(240px, 1fr\)\)/)
	assert.match(ISSUE, /\.issue__wide \{\n\tgrid-column: 1 \/ -1;/)
})
