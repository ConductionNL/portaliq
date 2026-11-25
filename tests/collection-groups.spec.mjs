#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// collection-groups.spec.mjs: a collection's rows grouped by its declared
// `groupByField` (collection-group-by-field T1, T3). A guardian with two
// children sees one table per child, headed by the child's name from the
// contribution's `guardianAudience.children` collection; one child, or no
// group field, renders as before. A collection that declares
// `groupOrder: 'rows'` keeps its groups where their first rows stand
// (collection-group-order T1). The wiring test reads the site's page.
//
// Usage:
//   node --test tests/collection-groups.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	anyGrouped,
	groupFieldOf,
	groupLabelCollection,
	groupOrderOf,
	groupRows,
	nameOf,
} from '../src/shared/collectionGroups.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

const children = [
	{ id: 'vera', givenName: 'Vera', familyName: 'Hulstkamp' },
	{ id: 'daan', givenName: 'Daan', familyName: 'Hulstkamp' },
]

test("rows are grouped per child, headed by the child's name", () => {
	const rows = [
		{ id: 'g1', learnerRef: 'vera', value: '7,5' },
		{ id: 'g2', learnerRef: 'daan', value: '6' },
		{ id: 'g3', learnerRef: 'vera', value: '8' },
	]
	const groups = groupRows(rows, 'learnerRef', children)
	assert.deepEqual(
		groups.map((group) => [group.label, group.rows.map((row) => row.id)]),
		[
			['Daan Hulstkamp', ['g2']],
			['Vera Hulstkamp', ['g1', 'g3']],
		],
	)
})

test('one child, or no group field, renders ungrouped', () => {
	const rows = [{ learnerRef: 'vera' }, { learnerRef: 'vera' }]
	assert.deepEqual(groupRows(rows, 'learnerRef', children), [])
	assert.deepEqual(
		groupRows([{ learnerRef: 'vera' }, { learnerRef: 'daan' }], '', children),
		[],
	)
	assert.deepEqual(groupRows([], 'learnerRef', children), [])
})

test('a value without a name shows as it is, and rows without a value go last', () => {
	const groups = groupRows(
		[
			{ learnerRef: '' },
			{ learnerRef: 'unknown-ref' },
			{ learnerRef: 'vera' },
			{},
		],
		'learnerRef',
		children,
	)
	assert.deepEqual(
		groups.map((group) => [group.value, group.label, group.rows.length]),
		[
			['unknown-ref', 'unknown-ref', 1],
			['vera', 'Vera Hulstkamp', 1],
			['', '', 2],
		],
	)
})

test('a name falls back from given and family name to name and title', () => {
	assert.equal(nameOf({ givenName: 'Vera', familyName: '' }), 'Vera')
	assert.equal(nameOf({ displayName: 'Kim' }), 'Kim')
	assert.equal(nameOf({ name: 'Groep 7' }), 'Groep 7')
	assert.equal(nameOf({ title: 'T' }), 'T')
	assert.equal(nameOf({}), '')
})

test("the names come from the contribution's guardianAudience children", () => {
	const contribution = {
		guardianAudience: { children: 'parentChildren' },
		collections: [
			{ id: 'parentGrades', groupByField: 'learnerRef' },
			{ id: 'parentChildren' },
		],
	}
	assert.equal(groupLabelCollection(contribution)?.id, 'parentChildren')
	assert.equal(groupLabelCollection({ collections: [] }), null)
	assert.equal(
		groupLabelCollection({
			guardianAudience: { children: 'missing' },
			collections: [],
		}),
		null,
	)
	assert.equal(groupFieldOf(contribution.collections[0]), 'learnerRef')
	assert.equal(groupFieldOf({ groupByField: 3 }), '')
	assert.equal(anyGrouped(contribution.collections), true)
	assert.equal(anyGrouped([{ id: 'x' }]), false)
})

test('the site page groups a collection that declares groupByField', () => {
	const page = readFileSync(
		join(ROOT, 'src/site/pages/collections/ContributionPage.vue'),
		'utf8',
	)
	assert.match(page, /from '\.\.\/\.\.\/\.\.\/shared\/collectionGroups\.js'/)
	assert.match(page, /groupRows\(/)
})

const courses = [
	{ id: 'c1', courseName: 'BRL 6000-21', expiresAt: '2028-03-01' },
	{ id: 'c2', courseName: 'F-gassen', expiresAt: '2027-01-15' },
	{ id: 'c3', courseName: 'BRL 6000-21', expiresAt: '2029-06-01' },
	{ id: 'c4', courseName: 'Asbest', expiresAt: '2027-09-30' },
]

test("groupOrder 'rows': the group of the first row comes first", () => {
	// The rows arrive sorted on expiresAt asc, as defaultSort asks.
	const sorted = [...courses].sort((a, b) => a.expiresAt.localeCompare(b.expiresAt))
	const groups = groupRows(sorted, 'courseName', [], 'rows')
	assert.deepEqual(
		groups.map((group) => [group.label, group.rows.map((row) => row.id)]),
		[
			['F-gassen', ['c2']],
			['Asbest', ['c4']],
			['BRL 6000-21', ['c1', 'c3']],
		],
	)
})

test('without groupOrder the groups read in the order of their headings', () => {
	const sorted = [...courses].sort((a, b) => a.expiresAt.localeCompare(b.expiresAt))
	assert.deepEqual(
		groupRows(sorted, 'courseName', []).map((group) => group.label),
		['Asbest', 'BRL 6000-21', 'F-gassen'],
	)
})

test("groupOrder 'rows' still puts rows without a value last", () => {
	const groups = groupRows(
		[{ courseName: '' }, { courseName: 'F-gassen' }, { courseName: 'Asbest' }],
		'courseName',
		[],
		'rows',
	)
	assert.deepEqual(
		groups.map((group) => group.value),
		['F-gassen', 'Asbest', ''],
	)
})

test("groupOrderOf reads 'rows' only, everything else is 'heading'", () => {
	assert.equal(groupOrderOf({ groupOrder: 'rows' }), 'rows')
	assert.equal(groupOrderOf({ groupOrder: 'heading' }), 'heading')
	assert.equal(groupOrderOf({ groupOrder: 'ROWS' }), 'heading')
	assert.equal(groupOrderOf({}), 'heading')
	assert.equal(groupOrderOf(null), 'heading')
})

test('the site page passes the collection group order to groupRows', () => {
	const page = readFileSync(
		join(ROOT, 'src/site/pages/collections/ContributionPage.vue'),
		'utf8',
	)
	assert.match(page, /groupOrderOf\(item\.collection\)/)
})
