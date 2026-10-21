#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// mandate-invite-dialog.spec.mjs: "Iemand machtigen" as the Machtigen board
// draws it: 640px wide, no frame (the board measures border 0 where the live
// dialog drew 1px), rounded corners and a shadow on a dimmed page, 28px
// inside, and "Annuleren" and "Uitnodiging sturen" 12px apart at the end of
// the last line.
//
// Usage:
//   node --test tests/site-look/mandate-invite-dialog.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const FILE = 'src/site/modals/e/MandateInviteModal.vue'

/**
 * The rule body for one selector in the dialog's style.
 *
 * @param {string} selector The exact selector.
 * @return {string} The declarations, whitespace collapsed.
 */
function rule(selector) {
	const source = readFileSync(new URL(`../../${FILE}`, import.meta.url), 'utf8')
	const css = source
		.slice(source.indexOf('<style'))
		.replace(/\/\*[\s\S]*?\*\//g, ' ')
		.replace(/\s+/g, ' ')
	const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
	const match = css.match(new RegExp(`(?:^|[}>]) ?${escaped} \\{([^}]*)\\}`))
	return match ? match[1] : ''
}

test('the dialog is 640px wide, frameless, rounded, with a shadow and 28px inside', () => {
	const body = rule('.pq-mandate-invite')
	assert.match(body, /inline-size: min\(40rem, calc\(100vw - 2rem\)\)/)
	assert.match(body, /border: 0;/)
	assert.match(body, /border-radius: var\(--nldesign-website-border-radius-large/)
	assert.match(body, /box-shadow:/)
	assert.match(body, /padding: 1\.75rem/)
	assert.match(rule('.pq-mandate-invite::backdrop'), /background:/)
})

test('the actions stand 12px apart at the end of the line', () => {
	const body = rule('.pq-mandate-invite__buttons')
	assert.match(body, /gap: 0\.75rem/)
	assert.match(body, /justify-content: flex-end/)
})
