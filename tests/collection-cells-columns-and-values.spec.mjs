#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// collection-cells-columns-and-values.spec.mjs: every branch of how a
// collection table (src/site/components/collections/cells.js) finds a row's
// id, its columns and detail fields, a field's config, how a scalar value and
// a labelled value read, and which addresses a QR cell takes; pinned before
// these were split into named steps.
//
// Usage:
//   node --test tests/collection-cells-columns-and-values.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	deriveColumns,
	detailFields,
	fieldConfigOf,
	qrHref,
	readable,
	rowIdOf,
	valueLabel,
} from '../src/site/components/collections/cells.js'

const UUID = '0f8fad5b-d9cb-469f-a165-70867728950e'

test('a row id is its own, its uuid or its envelope id', () => {
	assert.equal(rowIdOf({ id: 'a', uuid: 'b' }), 'a')
	assert.equal(rowIdOf({ id: '', uuid: 'b' }), 'b')
	assert.equal(rowIdOf({ '@self': { id: 'c' } }), 'c')
	assert.equal(rowIdOf({ '@self': null }), undefined)
	assert.equal(rowIdOf({ id: 0 }), undefined)
	assert.equal(rowIdOf({}), undefined)
	assert.equal(rowIdOf(null), undefined)
	assert.equal(rowIdOf('row'), undefined)
})

test('declared columns keep their own label, render and value labels, else the field config', () => {
	const collection = {
		columns: [
			{ field: 'status', label: 'Stand', render: 'badge' },
			{ field: 'kind', label: '  ', valueLabels: { a: 'Aanvraag' } },
			{ field: 'naam' },
			{ field: '' },
			{ label: 'zonder veld' },
			null,
		],
		fieldConfigs: { kind: { label: 'Soort', valueLabels: { b: 'B' } }, naam: { label: 'Naam', valueLabels: { x: 'X' } } },
	}
	assert.deepEqual(deriveColumns(collection, []), [
		{ field: 'status', label: 'Stand', render: 'badge' },
		{ field: 'kind', label: 'Soort', render: 'text', valueLabels: { a: 'Aanvraag' } },
		{ field: 'naam', label: 'Naam', render: 'text', valueLabels: { x: 'X' } },
	])
})

test('without declared columns every field of the rows shows, minus the envelope and identifier-only fields', () => {
	const rows = [
		{ id: UUID, '@self': {}, titel: 'Aanvraag', ref: UUID, mix: UUID, lijst: [UUID, ''] },
		{ titel: 'Melding', datum: '2026-01-01', ref: null, mix: 'gewoon', lijst: [UUID], leeg: '' },
		null,
	]
	assert.deepEqual(deriveColumns({ fieldConfigs: { titel: { label: 'Titel' } } }, rows).map((c) => [c.field, c.label, c.render]), [
		['titel', 'Titel', 'text'],
		['mix', 'Mix', 'text'],
		['datum', 'Datum', 'text'],
		['leeg', 'Leeg', 'text'],
	])
	assert.deepEqual(deriveColumns({ columns: 'x' }, null), [])
	assert.deepEqual(deriveColumns(null, [{ a: 1 }]).map((c) => c.field), ['a'])
	assert.deepEqual(deriveColumns({ columns: [] }, [{ leeg: '' }, { leeg: null }]).map((c) => c.field), ['leeg'], 'a field never filled holds no identifier, so it stays')
})

test('detail fields follow the declared list, else the row, with column or config labels', () => {
	const collection = {
		columns: [{ field: 'status', label: 'Stand', render: 'badge', valueLabels: { o: 'Open' } }, { label: 'x' }, null],
		detail: { fields: ['status', 'naam', '', 7] },
		fieldConfigs: { naam: { label: 'Naam', valueLabels: { j: 'Jan' } }, status: { valueLabels: { c: 'Dicht' } } },
	}
	const row = { status: 'o', naam: 'j', _columns: { status: { render: 'text', linkLabel: 'Bekijk' } } }
	assert.deepEqual(detailFields(collection, row), [
		{ field: 'status', label: 'Stand', render: 'text', linkLabel: 'Bekijk', valueLabels: { o: 'Open' }, declared: true },
		{ field: 'naam', label: 'Naam', render: 'text', linkLabel: undefined, valueLabels: { j: 'Jan' }, declared: true },
	])
	assert.deepEqual(detailFields({ columns: 'x' }, { '@self': {}, id: 'a', titel: 'T' }).map((f) => [f.field, f.declared]), [['titel', false]])
	assert.deepEqual(detailFields({ detail: { fields: [] } }, null), [])
	assert.deepEqual(detailFields(null, { a: 1 })[0], { field: 'a', label: 'A', render: 'text', linkLabel: undefined, valueLabels: undefined, declared: false })
})

test('a field config is the collection\'s own object for that field, else none', () => {
	assert.deepEqual(fieldConfigOf({ fieldConfigs: { a: { label: 'A' } } }, 'a'), { label: 'A' })
	assert.deepEqual(fieldConfigOf({ fieldConfigs: { a: 'x' } }, 'a'), {})
	assert.deepEqual(fieldConfigOf({ fieldConfigs: { a: null } }, 'a'), {})
	assert.deepEqual(fieldConfigOf({ fieldConfigs: {} }, 'toString'), {}, 'only its own fields')
	assert.deepEqual(fieldConfigOf({ fieldConfigs: 'x' }, 'a'), {})
	assert.deepEqual(fieldConfigOf({}, 'a'), {})
	assert.deepEqual(fieldConfigOf(null, 'a'), {})
})

test('a scalar reads as yes or no, a number, a date, nothing for an identifier, else itself', () => {
	const context = { locale: 'nl-NL', t: (key) => `«${key}»` }
	assert.equal(readable(true, context), '«Yes»')
	assert.equal(readable(false, context), '«No»')
	assert.equal(readable(1234.5, context), '1.234,5')
	assert.equal(readable(Number.NaN, context), '')
	assert.equal(readable(Infinity, context), '')
	assert.equal(readable(` ${UUID} `, context), '')
	assert.equal(readable('2026-01-30', context), '30-1-2026')
	assert.notEqual(readable('2026-01-30T10:15:00', context), '2026-01-30T10:15:00')
	assert.equal(readable(' gewoon ', context), ' gewoon ')
	assert.equal(readable(undefined, context), '')
	assert.equal(readable(null, context), '')
	assert.equal(readable(Symbol.iterator, context), '')
})

test('a value label is the declared non-blank label of a string or number value', () => {
	const labels = { a: 'Aanvraag', 1: 'Een', leeg: '  ', n: 5 }
	assert.equal(valueLabel('a', labels), 'Aanvraag')
	assert.equal(valueLabel(1, labels), 'Een')
	assert.equal(valueLabel('leeg', labels), undefined)
	assert.equal(valueLabel('n', labels), undefined)
	assert.equal(valueLabel('b', labels), undefined)
	assert.equal(valueLabel('toString', labels), undefined)
	assert.equal(valueLabel(true, { true: 'Ja' }), undefined)
	assert.equal(valueLabel(null, labels), undefined)
	assert.equal(valueLabel('a', null), undefined)
	assert.equal(valueLabel('a', 'x'), undefined)
})

test('a QR cell takes wallet and web addresses, makes a path absolute, and nothing else', () => {
	assert.equal(qrHref(' openid-credential-offer://?x=1 '), 'openid-credential-offer://?x=1')
	assert.equal(qrHref('openid4vp://authorize?a=b'), 'openid4vp://authorize?a=b')
	assert.equal(qrHref('openid4vp://a b'), '')
	assert.equal(qrHref('https://s.nl/a'), 'https://s.nl/a')
	assert.equal(qrHref('/apps/x', 'https://s.nl/'), 'https://s.nl/apps/x')
	assert.equal(qrHref('/apps/x'), '')
	assert.equal(qrHref('/apps/x', ''), '')
	assert.equal(qrHref('javascript:alert(1)', 'https://s.nl'), '')
	assert.equal(qrHref(42, 'https://s.nl'), '')
	assert.equal(qrHref('', 'https://s.nl'), '')
})
