// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// An app declares how its stored values read (contribution-value-labels): a
// column's `valueLabels` turn "approved" into "Goedgekeurd" in the table and
// on the detail card; an unlabelled value reads as before.
//
// @spec openspec/changes/contribution-value-labels/specs/portal-contribution-contract/spec.md#requirement-a-column-and-a-form-field-may-declare-how-their-values-read

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	detailFields,
	formatCell,
	valueLabel,
} from '../src/site/components/collections/cells.js'
import { renderSfc } from './support/render-sfc.mjs'

const t = (key) => key
const nl = { locale: 'nl', t }

const STATUS = {
	approved: 'Goedgekeurd',
	submitted: 'Ingediend',
}

const excuses = {
	id: 'parentExcuseRequests',
	label: 'Afwezigheidsmeldingen van mijn kind',
	columns: [
		{ field: 'reason', label: 'Reden' },
		{ field: 'lifecycle', label: 'Status', valueLabels: STATUS },
	],
}

test('a labelled value reads as its label, an unlabelled one as itself', () => {
	assert.equal(
		formatCell('approved', 'text', { ...nl, valueLabels: STATUS }),
		'Goedgekeurd',
	)
	assert.equal(
		formatCell('rejected', 'text', { ...nl, valueLabels: STATUS }),
		'rejected',
	)
	assert.equal(formatCell('approved', 'text', nl), 'approved')
})

test('the label wins over the render kind, and a list labels each item', () => {
	assert.equal(
		formatCell('approved', 'badge', { ...nl, valueLabels: STATUS }),
		'Goedgekeurd',
	)
	assert.equal(
		formatCell(['approved', 'other'], 'text', { ...nl, valueLabels: STATUS }),
		'Goedgekeurd\nother',
	)
})

test('only own string labels count', () => {
	assert.equal(valueLabel('toString', STATUS), undefined)
	assert.equal(valueLabel('approved', { approved: 7 }), undefined)
	assert.equal(valueLabel('approved', { approved: '  ' }), undefined)
	assert.equal(valueLabel({ approved: 1 }, STATUS), undefined)
	assert.equal(valueLabel(3, { 3: 'Drie' }), 'Drie')
	assert.equal(valueLabel('approved', null), undefined)
})

test('a detail field carries the labels of the column that shows it', () => {
	const fields = detailFields(excuses, { reason: 'Griep', lifecycle: 'approved' })
	const status = fields.find((field) => field.field === 'lifecycle')
	assert.deepEqual(status.valueLabels, STATUS)
	assert.equal(
		formatCell('approved', status.render, {
			...nl,
			valueLabels: status.valueLabels,
		}),
		'Goedgekeurd',
	)
})

test('the table shows the label in the status cell', async () => {
	const html = await renderSfc(
		'src/site/components/collections/CollectionTable.vue',
		{
			collection: excuses,
			objects: [
				{ id: 'r1', reason: 'Griep', lifecycle: 'approved' },
				{ id: 'r2', reason: 'Tandarts', lifecycle: 'submitted' },
			],
			t,
			locale: 'nl',
		},
	)
	assert.match(html, />\s*Goedgekeurd\s*</)
	assert.match(html, />\s*Ingediend\s*</)
	assert.doesNotMatch(html, />\s*approved\s*</)
})

test('the detail card shows the label', async () => {
	const html = await renderSfc('src/site/components/collections/DetailCard.vue', {
		collection: { ...excuses, detail: { fields: ['reason', 'lifecycle'] } },
		row: { id: 'r1', reason: 'Griep', lifecycle: 'approved' },
		t,
		locale: 'nl',
	})
	assert.match(html, /Goedgekeurd/)
	assert.doesNotMatch(html, />\s*approved\s*</)
})
