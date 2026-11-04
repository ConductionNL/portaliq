#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// case-type-columns.spec.mjs: a case row reads its columns the way the
// portal's administrator set them for its case type (`_columns`), and the
// admin screen saves them per case type (case-type-columns-in-my-cases).
//
// Usage:
//   node --test tests/case-type-columns.spec.mjs
//
// @spec openspec/changes/case-type-columns-in-my-cases/specs/portal-case-type-visibility/spec.md

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { columnsToSave, columnsUrl, editableColumns } from '../src/lib/caseTypeColumns.js'
import { deriveColumns, detailFields, rowColumn } from '../src/site/components/collections/cells.js'
import { renderSfc } from './support/render-sfc.mjs'

const TABLE = 'src/site/components/collections/CollectionTable.vue'
const OFFER = 'https://zuiddrecht.nl/wallet/aanbod/8f2c1d'
const COLLECTION = {
	kind: 'cases',
	columns: [
		{ field: 'title', label: 'Zaak' },
		{ field: 'permitProof', label: 'Vergunningsbewijs', render: 'link' },
	],
}
const SET = { permitProof: { field: 'permitProof', render: 'qr', linkLabel: 'Toevoegen aan wallet' } }

test('a row with settings for its case type reads the column as set', () => {
	const column = rowColumn(COLLECTION.columns[1], { _columns: SET })
	assert.equal(column.render, 'qr')
	assert.equal(column.linkLabel, 'Toevoegen aan wallet')
	assert.equal(column.label, 'Vergunningsbewijs', 'the label stays the app\'s')
	assert.equal(rowColumn(COLLECTION.columns[1], {}), COLLECTION.columns[1], 'a row without settings reads as declared')
	assert.equal(rowColumn(COLLECTION.columns[0], { _columns: SET }), COLLECTION.columns[0], 'another column reads as declared')
})

test('the record page follows the settings, and `_columns` is not a field', () => {
	const fields = detailFields(COLLECTION, { title: 'Parkeervergunning', permitProof: OFFER, _columns: SET })
	assert.deepEqual(fields.map((f) => f.field), ['title', 'permitProof'], '`_columns` is envelope')
	const proof = fields.find((f) => f.field === 'permitProof')
	assert.equal(proof.render, 'qr')
	assert.equal(proof.linkLabel, 'Toevoegen aan wallet')
	const derived = deriveColumns({ kind: 'cases' }, [{ title: 'x', _columns: SET }])
	assert.ok(!derived.some((c) => c.field === '_columns'), 'no column is made of `_columns`')
})

test('one table draws a QR toggle on the set row and a link on the other', async () => {
	const html = await renderSfc(TABLE, {
		collection: COLLECTION,
		objects: [
			{ id: 'a', title: 'Parkeren', permitProof: OFFER, _columns: SET },
			{ id: 'b', title: 'Afval', permitProof: OFFER },
		],
		locale: 'nl',
	})
	const [first, second] = html.split('data-testid="collection-table-row"').slice(1)
	assert.match(first, /aria-expanded="false"/, 'the set row offers the code')
	assert.match(first, /Toevoegen aan wallet/)
	assert.doesNotMatch(second, /aria-expanded/, 'the other row has no code')
	assert.match(second, /class="utrecht-link"[^>]*href="https:\/\/zuiddrecht\.nl\/wallet\/aanbod\/8f2c1d"/)
})

test('the admin rows start from what is stored and a save sends only chosen columns', () => {
	const fields = [{ field: 'title', label: 'Zaak' }, { field: 'permitProof', label: 'Vergunningsbewijs' }, { field: 'startDate', label: '' }]
	const rows = editableColumns(fields, [{ field: 'permitProof', render: 'qr', linkLabel: 'Toevoegen aan wallet' }])
	assert.deepEqual(rows[0], { field: 'title', label: 'Zaak', render: '', linkLabel: '' }, 'unset reads as the case app shows it')
	assert.equal(rows[1].render, 'qr')
	assert.equal(rows[2].label, 'startDate', 'a field without a label is named by itself')
	rows[2].render = 'date'
	rows[2].linkLabel = 'ignored'
	assert.deepEqual(columnsToSave(rows), [
		{ field: 'permitProof', render: 'qr', linkLabel: 'Toevoegen aan wallet' },
		{ field: 'startDate', render: 'date' },
	])
	assert.equal(
		columnsUrl({ slug: 'mijn zuiddrecht', typeId: 'a/b' }, (path) => path),
		'/apps/portaliq/api/portals/mijn%20zuiddrecht/case-types/a%2Fb/columns',
	)
})

test('the admin section previews a QR column with nextcloud-vue\'s CnQrCode', () => {
	const source = readFileSync('src/widgets/CaseTypeColumns.vue', 'utf8')
	assert.match(source, /import \{ CnQrCode \} from '@conduction\/nextcloud-vue'/)
	assert.match(source, /<CnQrCode\s+v-if="row\.render === 'qr'"/)
	assert.match(readFileSync('src/widgets/PortalCaseTypes.vue', 'utf8'), /<CaseTypeColumns/)
})
