#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// public-records-board.spec.mjs: one public record on the PtStemgedrag board
// (site-member-voting-record-and-confidential-papers T3). The heading the page
// writes around the title, filters on the record's own columns, a count line,
// pages of rows, and a side card with initials, the figures and links.
//
// Usage:
//   node --test tests/public-records-board.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	fillText,
	filteredRows,
	initialsOf,
	recordFilters,
	rowsPage,
} from '../src/site/lib/publicRecordsApi.js'
import { mountSfc } from './support/mount-sfc.mjs'

const BLOCK = 'src/site/components/PublicRecordsBlock.vue'

const COLUMNS = [
	{ key: 'date', label: 'Datum' },
	{ key: 'subject', label: 'Waarover' },
	{ key: 'choice', label: 'Stem' },
	{ key: 'result', label: 'Uitslag' },
]

/**
 * Twelve votes over three meetings, newest first.
 *
 * @return {Array<object>} The rows.
 */
function votes() {
	const rows = []
	for (let index = 0; index < 12; index++) {
		rows.push({
			date: ['1 okt 2026', '17 sep 2026', '3 sep 2026'][index % 3],
			subject: `Motie ${index + 1}`,
			choice: index % 4 === 0 ? 'Tegen' : 'Voor',
			result: 'Aangenomen',
		})
	}
	return rows
}

const RECORD = {
	title: 'Joost van Dalen',
	subtitle: 'Lokaal Zuiddrecht',
	summary: [
		{ label: 'Voor gestemd', value: '39', detail: '' },
		{ label: 'Tegen gestemd', value: '7', detail: '' },
		{ label: 'Afwezig', value: '2', detail: '' },
	],
	columns: COLUMNS,
	rows: votes(),
	note: 'Alleen openbare stemmingen.',
}

const PROPS = {
	app: 'decidiq',
	list: 'memberVotingRecords',
	recordParam: 'p1',
	locale: 'nl',
	backText: 'Alle raadsleden',
	recordHeading: 'Stemgedrag van {title}',
	filterBy: [
		{ key: 'date', label: 'Vergadering', all: 'Alle vergaderingen' },
		{ key: 'choice', label: 'Stem', all: 'Voor en tegen' },
		{ key: 'nope', label: 'Niet bestaand' },
	],
	filterButton: 'Toon stemmingen',
	countText: '{count} stemmingen, u ziet {from} tot {to}.',
	pageSize: 5,
	links: [
		{ label: 'Vergaderingen van de raad', href: '/bestuur/vergaderingen' },
		{ label: 'Kwaad', href: 'javascript:alert(1)' },
		{ label: 'Andere host', href: '//evil.example/x' },
	],
	apiOverride: {
		list: async () => [{ id: 'p1', title: 'Joost van Dalen' }],
		record: async () => RECORD,
	},
}

test('a filter offers the values its column holds, in order; an unknown column is no filter', () => {
	const filters = recordFilters(RECORD.rows, PROPS.filterBy, COLUMNS)
	assert.deepEqual(
		filters.map((filter) => filter.key),
		['date', 'choice'],
	)
	assert.deepEqual(filters[0].options, ['1 okt 2026', '17 sep 2026', '3 sep 2026'])
	assert.deepEqual(filters[1].options, ['Tegen', 'Voor'])
	assert.equal(filters[1].all, 'Voor en tegen')
	assert.deepEqual(
		recordFilters(RECORD.rows, [{ key: 'date' }], COLUMNS),
		[],
		'a filter needs a label',
	)
	assert.deepEqual(recordFilters(null, null, null), [])
})

test('filtered rows hold every chosen value; no choice keeps them all', () => {
	assert.equal(filteredRows(RECORD.rows, {}).length, 12)
	assert.equal(filteredRows(RECORD.rows, { date: '', choice: '' }).length, 12)
	assert.equal(filteredRows(RECORD.rows, { date: '1 okt 2026' }).length, 4)
	assert.deepEqual(
		filteredRows(RECORD.rows, { date: '1 okt 2026', choice: 'Tegen' }).map(
			(row) => row.subject,
		),
		['Motie 1'],
	)
})

test('a page counts from one, and a page past the end shows the last one', () => {
	assert.deepEqual(
		{ ...rowsPage(RECORD.rows, 1, 5), rows: undefined },
		{ rows: undefined, page: 1, pages: 3, from: 1, to: 5, total: 12 },
	)
	const last = rowsPage(RECORD.rows, 9, 5)
	assert.equal(last.page, 3)
	assert.equal(last.rows.length, 2)
	assert.equal(last.from, 11)
	assert.equal(last.to, 12)
	assert.equal(
		rowsPage(RECORD.rows, 1, 0).rows.length,
		12,
		'no page size shows every row',
	)
	assert.deepEqual(
		{ ...rowsPage([], 1, 5), rows: undefined },
		{ rows: undefined, page: 1, pages: 1, from: 0, to: 0, total: 0 },
	)
})

test('initials and sentences', () => {
	assert.equal(initialsOf('Joost van Dalen'), 'JD')
	assert.equal(initialsOf('sanne'), 'S')
	assert.equal(initialsOf('  '), '')
	assert.equal(
		fillText('Stemgedrag van {title}', { title: 'Joost' }),
		'Stemgedrag van Joost',
	)
	assert.equal(
		fillText('{count} van {total}', { count: 3 }),
		'3 van {total}',
		'an unknown placeholder stays',
	)
})

test('the record follows the board: heading, filters, count, first page, pager and side card', async () => {
	const block = await mountSfc(BLOCK, PROPS)
	await block.flush()
	assert.match(block.text(), /Stemgedrag van Joost van Dalen/)
	assert.ok(block.find('public-record-filter-date'))
	assert.ok(block.find('public-record-filter-choice'))
	assert.ok(!block.find('public-record-filter-nope'))
	assert.match(
		block.textOf(block.find('public-record-filter-date')),
		/^Alle vergaderingen 1 okt 2026 17 sep 2026 3 sep 2026$/,
	)
	assert.equal(
		block.textOf(block.find('public-record-filter-apply')),
		'Toon stemmingen',
	)
	assert.equal(
		block.textOf(block.find('public-record-count')),
		'12 stemmingen, u ziet 1 tot 5.',
	)
	assert.match(block.text(), /Motie 5/)
	assert.doesNotMatch(block.text(), /Motie 6\b/)
	assert.match(
		block.textOf(block.find('public-record-pager')),
		/^1 tot 5 van 12 1 2 3 Volgende$/,
	)

	const side = block.find('public-record-side')
	assert.match(block.textOf(side), /^JD Joost van Dalen Lokaal Zuiddrecht/)
	assert.match(block.textOf(side), /Voor gestemd 39/)
	assert.match(block.textOf(side), /Alle raadsleden Vergaderingen van de raad$/)
	const links = block.findAll(
		(node) => node.props?.['data-testid'] === 'public-record-link',
	)
	assert.equal(links.length, 1, 'a script address or another host is never a link')
})

test('the next page, then a filter, which starts again at page one', async () => {
	const block = await mountSfc(BLOCK, PROPS)
	await block.flush()
	await block.fire(block.find('public-record-page-next'), 'click')
	await block.flush()
	assert.equal(
		block.textOf(block.find('public-record-count')),
		'12 stemmingen, u ziet 6 tot 10.',
	)
	assert.match(
		block.textOf(block.find('public-record-pager')),
		/^6 tot 10 van 12 Vorige 1 2 3 Volgende$/,
	)

	await block.fire(block.find('public-record-filter-date'), 'change', {
		value: '1 okt 2026',
	})
	await block.flush()
	assert.equal(
		block.textOf(block.find('public-record-count')),
		'12 stemmingen, u ziet 6 tot 10.',
		'a choice waits for the button',
	)
	await block.fire(block.find('public-record-filters'), 'submit')
	await block.flush()
	assert.equal(
		block.textOf(block.find('public-record-count')),
		'4 stemmingen, u ziet 1 tot 4.',
	)
	assert.ok(!block.find('public-record-pager'), 'one page has no pager')
})

test('a page without the board settings keeps the plain record', async () => {
	const block = await mountSfc(BLOCK, {
		app: 'decidiq',
		list: 'l',
		recordParam: 'p1',
		locale: 'nl',
		apiOverride: PROPS.apiOverride,
	})
	await block.flush()
	assert.ok(!block.find('public-record-filters'))
	assert.ok(!block.find('public-record-count'))
	assert.ok(!block.find('public-record-pager'))
	assert.match(block.text(), /Motie 12/, 'every row')
	assert.match(block.text(), /^Joost van Dalen/)
})
