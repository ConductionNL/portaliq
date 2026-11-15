#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// account-dialogs-board-rhythm.spec.mjs: in the board look the invite and
// issue-account dialogs space their fields as PtUitnodigen and
// PtAccountUitgeven draw them: help lines on the normal line, the labelled
// audience select full width with no bottom margin of its own, the
// issue-account grid rows 16px apart, the check's help right under its text
// and the note 16px under the grid. Every rule is scoped to the board look.
//
// Usage:
//   node --test tests/account-dialogs-board-rhythm.spec.mjs
//
// @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-issue-and-withdraw-accounts-through-the-validated-actions-req-isa-003

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const read = (rel) => readFileSync(join(ROOT, rel), 'utf8')
const style = (source) => source.slice(source.indexOf('<style scoped>'))
const INVITE = read('src/dialogs/InviteDialog.vue')
const ISSUE = read('src/dialogs/IssueAccountDialog.vue')

/** The declarations of the first rule whose selector is exactly `selector`. */
function rule(source, selector) {
	const css = style(source)
	const at = css.indexOf(`\n${selector} {\n`)
	assert.notEqual(at, -1, `no rule for ${selector}`)
	return css.slice(at, css.indexOf('}', at))
}

test('the help lines stand on the normal line in the board look', () => {
	assert.match(
		rule(INVITE, '.cn-dialog-board .invite__help'),
		/line-height: normal;/,
	)
	assert.match(
		rule(ISSUE, '.cn-dialog-board .issue__help'),
		/line-height: normal;/,
	)
})

test('the audience select is full width with no bottom margin in the board look', () => {
	assert.match(INVITE, /<div class="invite__select">\s*<NcSelect/)
	const invite = rule(
		INVITE,
		'.cn-dialog-board .invite__select :deep(.v-select.select)',
	)
	assert.match(invite, /width: 100%;/)
	assert.match(invite, /margin-bottom: 0;/)
	assert.equal(
		(ISSUE.match(/<div class="issue__select">\s*<NcSelect/g) || []).length,
		2,
	)
	const issue = rule(
		ISSUE,
		'.cn-dialog-board .issue__select :deep(.v-select.select)',
	)
	assert.match(issue, /width: 100%;/)
	assert.match(issue, /margin-bottom: 0;/)
})

test('the issue-account grid rows stand 16px apart in the board look', () => {
	assert.match(rule(ISSUE, '.cn-dialog-board .issue__grid'), /row-gap: 16px;/)
})

test('the check help sits under its text and the note 16px under the grid', () => {
	assert.match(
		rule(ISSUE, '.cn-dialog-board .issue__help--check'),
		/margin-top: -5px;/,
	)
	assert.match(
		rule(ISSUE, '.cn-dialog-board .dialog__content .notecard.issue__note'),
		/margin-top: -2px;/,
	)
})

test('the Nextcloud look keeps its own rhythm', () => {
	assert.match(rule(INVITE, '.invite__help'), /line-height: 1\.3;/)
	assert.match(rule(ISSUE, '.issue__help'), /line-height: 1\.3;/)
	assert.match(rule(ISSUE, '.issue__grid'), /gap: 14px 16px;/)
})
