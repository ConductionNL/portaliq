// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A select field of the landing page form shows the words its optionLabels
// give, and keeps the stored option as the value it sends
// (site-organisation-registration-form).
//
// Usage:
//   node --test tests/site-form-option-labels.spec.mjs
//
// @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-select-field-shows-words-for-its-stored-values

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { optionLabel } from '../src/site/components/forms/fields.js'

const field = {
	id: 'type',
	type: 'select',
	options: ['Supplier', 'Municipality', 'Community'],
	optionLabels: { Supplier: 'Leverancier', Municipality: 'Gemeente', Community: '' },
}

test('an option shows its label, or itself without one', () => {
	assert.deepEqual(field.options.map((o) => optionLabel(field, o)), ['Leverancier', 'Gemeente', 'Community'])
	assert.equal(optionLabel({ options: ['A'] }, 'A'), 'A')
})

test('the form block shows the label and sends the option', () => {
	const source = readFileSync(new URL('../src/site/components/FormBlock.vue', import.meta.url), 'utf8')
	assert.match(source, /:value="option">\s*\{\{ optionLabel\(field, option\) \}\}/)
})
