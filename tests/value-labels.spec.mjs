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
	deriveColumns,
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

// A detail field that is no column reads through the collection's own
// `fieldConfigs` (resident-sees-words-not-codes).
// @spec openspec/changes/resident-sees-words-not-codes/specs/portal-contribution-contract/spec.md#requirement-a-collection-may-say-how-the-values-of-any-of-its-fields-read

const complaints = {
	id: 'klachten',
	columns: [{ field: 'status', label: 'Status' }],
	fieldConfigs: {
		status: { valueLabels: { in_progress: 'In behandeling' } },
		complaintCategory: {
			label: 'Soort klacht',
			valueLabels: { service: 'Dienstverlening' },
		},
	},
	detail: { fields: ['complaintCategory', 'status'] },
}

test('a detail field that is no column takes its label and value labels from fieldConfigs', () => {
	const fields = detailFields(complaints, {})
	const category = fields.find((field) => field.field === 'complaintCategory')
	assert.equal(category.label, 'Soort klacht')
	assert.deepEqual(category.valueLabels, { service: 'Dienstverlening' })
})

test('a column without value labels falls back to fieldConfigs, in the table and on the card', () => {
	const [status] = deriveColumns(complaints, [])
	assert.deepEqual(status.valueLabels, { in_progress: 'In behandeling' })
	const own = {
		...complaints,
		columns: [{ field: 'status', valueLabels: { in_progress: 'Loopt' } }],
	}
	assert.deepEqual(deriveColumns(own, [])[0].valueLabels, { in_progress: 'Loopt' })
	const card = detailFields(complaints, {}).find(
		(field) => field.field === 'status',
	)
	assert.deepEqual(card.valueLabels, { in_progress: 'In behandeling' })
	assert.equal(card.label, 'Status')
})

test('the detail card shows the fieldConfigs label and value label', async () => {
	const html = await renderSfc('src/site/components/collections/DetailCard.vue', {
		collection: complaints,
		row: { id: 'k1', complaintCategory: 'service', status: 'in_progress' },
		t,
		locale: 'nl',
	})
	assert.match(html, /Soort klacht/)
	assert.match(html, /Dienstverlening/)
	assert.match(html, /In behandeling/)
	assert.doesNotMatch(html, />\s*(service|in_progress)\s*</)
})

// A declared detail field with no value is left out, so a case without a
// public team name shows no "Behandeld door" row (dossiq #3257).
test('the detail card leaves out a declared field without a value', async () => {
	const cases = {
		id: 'mijnZaken',
		columns: [{ field: 'identifier', label: 'Zaaknummer' }],
		fieldConfigs: {
			assignedGroupPublicName: { label: 'Behandeld door' },
			receivedOutsideWorkingHours: { label: 'Ontvangen buiten kantoortijd' },
		},
		detail: {
			fields: [
				'identifier',
				'assignedGroupPublicName',
				'endDate',
				'receivedOutsideWorkingHours',
			],
		},
	}
	const html = await renderSfc('src/site/components/collections/DetailCard.vue', {
		collection: cases,
		row: {
			id: 'c1',
			identifier: 'ZAAK-1',
			assignedGroupPublicName: '',
			endDate: null,
			receivedOutsideWorkingHours: false,
		},
		t,
		locale: 'nl',
	})
	assert.match(html, /Zaaknummer/)
	assert.match(html, /ZAAK-1/)
	assert.doesNotMatch(html, /Behandeld door/)
	assert.doesNotMatch(html, /endDate/)
	// A false value is a fact, not an absence.
	assert.match(html, /Ontvangen buiten kantoortijd/)

	const named = await renderSfc('src/site/components/collections/DetailCard.vue', {
		collection: cases,
		row: { id: 'c2', identifier: 'ZAAK-2', assignedGroupPublicName: 'Team Woo' },
		t,
		locale: 'nl',
	})
	assert.match(named, /Behandeld door/)
	assert.match(named, /Team Woo/)
})
