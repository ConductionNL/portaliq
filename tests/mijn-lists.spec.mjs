#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// mijn-lists.spec.mjs: a collection block's first rows in its order
// (`limit`, `sort`), a calendar's range (`day`, `week`, `month`), and the
// acting-for bar (site-mijn-omgeving-components T10 and T12: REQ-SMO-021,
// REQ-SMO-008).
//
// Usage:
//   node --test tests/mijn-lists.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	itemsInRange,
	listOrder,
	rangeSpan,
	sortRows,
	windowRows,
} from '../src/shared/listWindow.js'
import { actingFor } from '../src/site/components/e/actingFor.js'
import { instance, t } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const ActingForBar = await loadSfc('src/site/components/mijn/ActingForBar.vue')
const ContributionPage = await loadSfc(
	'src/site/pages/collections/ContributionPage.vue',
)

/** Friday 2 October 2026, the day of the spec's scenarios. */
const FRIDAY = new Date(2026, 9, 2, 12, 0, 0)

const GRADES = [1, 2, 3, 4, 5, 6, 7, 8].map((n) => ({
	id: `g${n}`,
	vak: `Vak ${n}`,
	gradedAt: `2026-09-${String(10 + n).padStart(2, '0')}`,
}))

test('the three newest grades, and a link to all of them', () => {
	// REQ-SMO-021 scenario "The three newest grades".
	const shown = windowRows(GRADES, {
		limit: 3,
		sort: { field: 'gradedAt', direction: 'desc' },
	})
	assert.deepEqual(
		shown.rows.map((row) => row.id),
		['g8', 'g7', 'g6'],
	)
	assert.equal(shown.more, true)
	assert.deepEqual(windowRows(GRADES.slice(0, 2), { limit: 3 }).more, false)
	assert.equal(windowRows(GRADES, {}).rows.length, 8, 'no limit shows every row')
})

test('a sort orders dates, numbers and words, and rows without a value come last', () => {
	const rows = [
		{ id: 'a', n: 2 },
		{ id: 'b' },
		{ id: 'c', n: 10 },
		{ id: 'd', n: 1 },
	]
	assert.deepEqual(
		sortRows(rows, { field: 'n', direction: 'asc' }).map((r) => r.id),
		['d', 'a', 'c', 'b'],
	)
	assert.deepEqual(
		sortRows(rows, { field: 'n', direction: 'desc' }).map((r) => r.id),
		['c', 'a', 'd', 'b'],
	)
	assert.deepEqual(
		sortRows([{ w: 'banaan' }, { w: 'Appel' }], {
			field: 'w',
			direction: 'asc',
		}).map((r) => r.w),
		['Appel', 'banaan'],
	)
	assert.deepEqual(
		sortRows(rows).map((r) => r.id),
		['a', 'b', 'c', 'd'],
		'no sort keeps the order',
	)
	assert.notEqual(sortRows(rows), rows, 'a copy, never the list it was given')
})

test('this week only: Monday 28 September to Sunday 4 October', () => {
	// REQ-SMO-021 scenario "This week only".
	const day = (d) => new Date(2026, 8, d, 9, 0, 0)
	const items = [
		{ title: 'zondag ervoor', start: day(27), end: day(27) },
		{ title: 'maandag', start: day(28), end: day(28) },
		{
			title: 'zondag',
			start: new Date(2026, 9, 4, 18, 0, 0),
			end: new Date(2026, 9, 4, 19, 0, 0),
		},
		{
			title: 'maandag erna',
			start: new Date(2026, 9, 5, 9, 0, 0),
			end: new Date(2026, 9, 5, 9, 0, 0),
		},
		{ title: 'loopt door', start: day(20), end: day(29) },
	]
	assert.deepEqual(
		itemsInRange(items, 'week', FRIDAY).map((i) => i.title),
		['maandag', 'zondag', 'loopt door'],
	)
	const span = rangeSpan('week', FRIDAY)
	assert.equal(span.from.getDay(), 1, 'from a Monday')
	assert.equal(span.from.getDate(), 28)
})

test('today only, this month, and no range', () => {
	// REQ-SMO-021 scenario "Today only".
	const items = [
		{
			title: 'vandaag',
			start: new Date(2026, 9, 2, 8, 30),
			end: new Date(2026, 9, 2, 9, 15),
		},
		{
			title: 'morgen',
			start: new Date(2026, 9, 3, 8, 30),
			end: new Date(2026, 9, 3, 9, 15),
		},
		{
			title: 'vorige maand',
			start: new Date(2026, 8, 30, 8, 30),
			end: new Date(2026, 8, 30, 9, 15),
		},
	]
	assert.deepEqual(
		itemsInRange(items, 'day', FRIDAY).map((i) => i.title),
		['vandaag'],
	)
	assert.deepEqual(
		itemsInRange(items, 'month', FRIDAY).map((i) => i.title),
		['vandaag', 'morgen'],
	)
	assert.equal(itemsInRange(items, undefined, FRIDAY).length, 3)
	assert.equal(rangeSpan('year', FRIDAY), null)
})

test('a limited table shows its first rows and leads to all of them', () => {
	const contribution = {
		app: 'learniq',
		collections: [{ id: 'parentGrades', label: 'Cijfers' }],
	}
	const overview = {
		key: 'learniq:overview',
		label: 'Overzicht',
		contribution,
		page: {
			id: 'overview',
			blocks: [
				{
					type: 'collection',
					collection: 'parentGrades',
					limit: 3,
					sort: { field: 'gradedAt', direction: 'desc' },
				},
			],
		},
	}
	const grades = {
		key: 'learniq:parentGrades',
		label: 'Cijfers',
		contribution,
		page: {
			id: 'parentGrades',
			blocks: [{ type: 'collection', collection: 'parentGrades' }],
		},
	}
	const ctx = instance(ContributionPage, {
		entry: overview,
		nav: [overview, grades],
		t,
		locale: 'nl',
		initialData: { parentGrades: { loading: false, objects: GRADES } },
	})
	const item = ctx.blocks[0]
	assert.deepEqual(
		ctx.tableWindow(item).rows.map((row) => row.id),
		['g8', 'g7', 'g6'],
	)
	assert.equal(
		ctx.allRouteOf(item),
		'/mijn/learniq/parentGrades',
		"the collection's own page",
	)
	assert.equal(ctx.seeAll(item), 'Bekijk alle cijfers')

	const alone = instance(ContributionPage, {
		entry: overview,
		nav: [overview],
		t,
		locale: 'nl',
		initialData: { parentGrades: { loading: false, objects: GRADES } },
	})
	assert.equal(
		alone.allRouteOf(alone.blocks[0]),
		'',
		'no other page: the rest opens here',
	)
	alone.expanded = { 0: true }
	assert.equal(alone.tableWindow(alone.blocks[0]).rows.length, 8)
	assert.equal(
		alone.tableWindow(alone.blocks[0]).rows[0].id,
		'g8',
		'still in its order',
	)
})

test("a table reads in its collection's default order, and a block's own sort wins", () => {
	// site-tables-read-in-their-declared-order, scenario "The newest absence
	// first": seen on learniq's parentExcuseRequests, whose rows read 1, 5, 2
	// October and 25 September.
	const newestFirst = { field: 'dateFrom', direction: 'desc' }
	assert.deepEqual(listOrder({}, { defaultSort: newestFirst }), newestFirst)
	assert.deepEqual(
		listOrder(
			{ sort: { field: 'reason', direction: 'asc' } },
			{ defaultSort: newestFirst },
		),
		{ field: 'reason', direction: 'asc' },
		"the block's own sort wins",
	)
	assert.equal(listOrder({}, {}), undefined, 'no order declared')
	assert.equal(listOrder(undefined, undefined), undefined)

	const ABSENCES = [
		{ id: 'a1', learnerRef: 'vera', dateFrom: '2026-10-01' },
		{ id: 'a5', learnerRef: 'vera', dateFrom: '2026-10-05' },
		{ id: 's2', learnerRef: 'sami', dateFrom: '2026-10-02' },
		{ id: 'a2', learnerRef: 'vera', dateFrom: '2026-10-02' },
		{ id: 's6', learnerRef: 'sami', dateFrom: '2026-10-06' },
		{ id: 'a0', learnerRef: 'vera', dateFrom: '2026-09-25' },
	]
	const page = (collection, block = {}) => ({
		key: 'learniq/parentExcuseRequests',
		kind: 'page',
		app: 'learniq',
		contribution: { app: 'learniq', collections: [collection] },
		page: {
			id: 'parentExcuseRequests',
			blocks: [
				{ type: 'collection', collection: 'parentExcuseRequests', ...block },
			],
		},
	})
	const open = (collection, block, objects) => {
		const entry = page(collection, block)
		return instance(ContributionPage, {
			entry,
			nav: [entry],
			t,
			locale: 'nl',
			initialData: { parentExcuseRequests: { loading: false, objects } },
		})
	}
	const veras = ABSENCES.filter((row) => row.learnerRef === 'vera')
	const sorted = { id: 'parentExcuseRequests', defaultSort: newestFirst }

	const one = open(sorted, {}, veras)
	assert.deepEqual(
		one.tableWindow(one.blocks[0]).rows.map((row) => row.id),
		['a5', 'a2', 'a1', 'a0'],
		'newest first',
	)
	const plain = open({ id: 'parentExcuseRequests' }, {}, veras)
	assert.deepEqual(
		plain.tableWindow(plain.blocks[0]).rows.map((row) => row.id),
		['a1', 'a5', 'a2', 'a0'],
		'no order declared: as the rows arrived',
	)
	const oldest = open(
		sorted,
		{ sort: { field: 'dateFrom', direction: 'asc' } },
		veras,
	)
	assert.deepEqual(
		oldest.tableWindow(oldest.blocks[0]).rows.map((row) => row.id),
		['a0', 'a1', 'a2', 'a5'],
		"the block's own sort wins",
	)

	// A table per child reads in the same order inside each group.
	const grouped = open({ ...sorted, groupByField: 'learnerRef' }, {}, ABSENCES)
	assert.deepEqual(
		grouped
			.groupsOf(grouped.blocks[0])
			.map((group) => [group.value, group.rows.map((row) => row.id)]),
		[
			['sami', ['s6', 's2']],
			['vera', ['a5', 'a2', 'a1', 'a0']],
		],
	)
})

test('Linda acts for her father: a named bar with the way back to herself', async () => {
	// REQ-SMO-008 scenario "Linda acts for her father, DossiqPhone.dc.html".
	const mandates = [{ id: 'm1', label: 'uw vader, H. Bakker' }]
	const html = await renderComponent(ActingForBar, {
		mandates,
		value: 'm1',
		locale: 'nl',
	})
	assert.match(
		html,
		/^<section class="pq-acting-for-bar" aria-label="Namens wie u werkt"/,
	)
	assert.match(html, /U regelt nu zaken voor <span translate="no"[^>]*>(<!--\[-->)?uw vader, H\. Bakker/)
	assert.match(html, /<button type="button"[^>]*>Wissel naar uzelf<\/button>/)

	assert.doesNotMatch(
		await renderComponent(ActingForBar, { mandates, value: 'self' }),
		/pq-acting-for-bar/,
		'nothing for oneself',
	)
	assert.doesNotMatch(
		await renderComponent(ActingForBar, { mandates, value: 'gone' }),
		/pq-acting-for-bar/,
		'nothing for a mandate no longer held',
	)

	actingFor.id = 'm1'
	actingFor.mandates = mandates
	const bar = instance(ActingForBar, { locale: 'nl' })
	assert.equal(
		bar.party,
		'uw vader, H. Bakker',
		'it reads the shared acting-for store',
	)
	bar.backToSelf()
	assert.equal(actingFor.id, 'self')
	assert.deepEqual(bar.emitted, [['change', 'self']])
	actingFor.mandates = []
})
