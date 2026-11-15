#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// form-textarea-rows.spec.mjs: a long answer in a site form gets room. The
// WooVerzoek and FormulierHulp boards draw "Welke informatie wilt u
// ontvangen?" as a box of seven lines (206px); the browser's default of two
// lines left it 48px high, the height of a one-line text box.
//
// Usage:
//   node --test tests/site-look/form-textarea-rows.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { mountSfc } from '../support/mount-sfc.mjs'

const FILE = 'src/site/components/c/SchemaField.vue'

/**
 * Mount one textarea field.
 *
 * @param {object} config The field config.
 * @return {Promise<object>} The textarea node.
 */
async function textarea(config = {}) {
	const view = await mountSfc(FILE, {
		id: 'f-woo-vraag',
		field: 'vraag',
		label: 'Welke informatie wilt u ontvangen?',
		input: 'textarea',
		config: { size: 'full', ...config },
	})
	const node = view.findAll((n) => n.tag === 'textarea')[0]
	assert.ok(node, 'a textarea renders')
	return node
}

test('a textarea is seven lines high by default', async () => {
	assert.equal(Number((await textarea()).props.rows), 7)
})

test('a field may ask for its own number of lines', async () => {
	assert.equal(Number((await textarea({ rows: 3 })).props.rows), 3)
})

test('a nonsense number of lines falls back to seven', async () => {
	assert.equal(Number((await textarea({ rows: 'veel' })).props.rows), 7)
	assert.equal(Number((await textarea({ rows: 0 })).props.rows), 7)
})
