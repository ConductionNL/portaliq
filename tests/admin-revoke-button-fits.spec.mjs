#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// admin-revoke-button-fits.spec.mjs: the admin settings' "Revoke all sessions
// for this organisation" button fits its label. The form put the field and the
// button on one row, where the button shrank below its label (233px for a
// 273px label, PtBeheerinstellingen). The board draws the field full width and
// the button under it at the end, at its own width.
//
// Usage:
//   node --test tests/admin-revoke-button-fits.spec.mjs
//
// @spec exclude styling only: the board PtBeheerinstellingen is the specification

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const SFC = readFileSync(new URL('../src/views/AdminRoot.vue', import.meta.url), 'utf8')
const CSS = SFC.slice(SFC.indexOf('<style'))

/**
 * The declarations of the first rule with this exact selector.
 *
 * @param {string} selector The selector.
 * @return {string} The declarations.
 */
function rule (selector) {
	const at = CSS.indexOf(`${selector} {`)
	assert.ok(at > -1, selector)
	return CSS.slice(at, CSS.indexOf('}', at))
}

test('the form stacks the field over the button', () => {
	const form = rule('.portaliq-admin-settings__revoke')
	assert.match(form, /flex-direction: column;/)
	assert.match(form, /align-items: stretch;/)
})

test('the button keeps its label\'s width, at the end of the row', () => {
	const button = rule('.portaliq-admin-settings__revoke > .button-vue')
	assert.match(button, /flex: none;/)
	assert.match(button, /align-self: flex-end;/)
})
