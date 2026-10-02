#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// record-page.spec.mjs: a record page opens one row of a collection and
// narrows the page's blocks to it; the kpi, calendar and news blocks render
// what belongs to that record (contribution-record-page).
//
// Usage:
//   node --test tests/record-page.spec.mjs
//
// The fixtures follow learniq's parent contribution: a guardian with two
// children, Vera (Groep 7) and Daan (Groep 4), at one school.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	calendarItems,
	dayKey,
	itemsOnDay,
	monthWeeks,
	narrowToRecord,
	newsForRecord,
	pickRow,
	recordGroups,
	recordTitle,
	upcomingItems,
} from '../src/shared/recordPage.js'
import { collectionIdsFor } from '../src/site/pages/collections/collectionLoader.js'
import { resolveBlocks } from '../src/site/pages/collections/pageBlocks.js'
import { collectionsTranslator } from '../src/site/pages/collections/translate.js'
import { renderSfc } from './support/render-sfc.mjs'

const t = (key) => key
const VERA = 'ee010008-0000-4000-8000-000000000415'
const DAAN = 'ee010008-0000-4000-8000-000000000416'
const SCHOOL = 'ee010001-0000-4000-8000-000000000001'
const GROEP7 = 'ee010002-0000-4000-8000-000000000007'
const GROEP4 = 'ee010002-0000-4000-8000-000000000004'

const vera = {
	id: VERA,
	givenName: 'Vera',
	familyName: 'Hulstkamp',
	schoolId: SCHOOL,
}
const daan = {
	id: DAAN,
	givenName: 'Daan',
	familyName: 'Hulstkamp',
	schoolId: SCHOOL,
}

const contribution = {
	app: 'learniq',
	label: 'Learniq',
	collections: [
		{
			id: 'parentChildren',
			register: 'learniq',
			schema: 'learner-profile',
			label: 'Mijn kinderen',
			columns: [
				{ field: 'givenName', label: 'Voornaam' },
				{ field: 'familyName', label: 'Achternaam' },
			],
		},
		{
			id: 'parentReportCards',
			register: 'learniq',
			schema: 'report-card',
			label: 'Rapporten',
			groupByField: 'learnerRef',
			columns: [{ field: 'periodName', label: 'Periode' }],
		},
		{
			id: 'parentAttendanceSummary',
			register: 'learniq',
			schema: 'attendance-summary',
			label: 'Aanwezigheid',
		},
		{
			id: 'parentSchoolEvents',
			register: 'learniq',
			schema: 'school-event',
			label: 'Schoolactiviteiten',
		},
		{
			id: 'parentSchoolCalendar',
			register: 'learniq',
			schema: 'report-period',
			label: 'Vakanties',
		},
		{
			id: 'parentGroupMemberships',
			register: 'learniq',
			schema: 'enrolment',
			groupByField: 'learnerRef',
			listable: false,
		},
	],
	actions: [],
	guardianAudience: {
		children: 'parentChildren',
		schoolField: 'schoolId',
		groups: { collection: 'parentGroupMemberships', field: 'cohortId' },
	},
}

const kpiBlock = {
	type: 'kpi',
	collection: 'parentAttendanceSummary',
	label: 'Dit schooljaar',
	recordField: 'learnerRef',
	pick: { field: 'schoolYear', direction: 'desc' },
	cards: [
		{
			field: 'absentDays',
			label: 'Afwezig',
			unit: 'dagen',
			details: [
				{ field: 'absentAuthorisedDays', label: 'met toestemming' },
				{ field: 'absentUnauthorisedDays', label: 'zonder toestemming' },
			],
		},
		{
			field: 'lateCount',
			label: 'Te laat',
			unit: 'keer',
			details: [{ field: 'lateMinutes', label: 'minuten' }],
		},
		{
			field: 'absentUnauthorisedDays',
			label: 'Ongeoorloofd afwezig',
			unit: 'dagen',
			highlight: true,
		},
	],
}

const calendarBlock = {
	type: 'calendar',
	label: 'Wat komt er aan',
	sources: [
		{
			collection: 'parentSchoolEvents',
			startField: 'startsAt',
			endField: 'endsAt',
			titleField: 'title',
			kind: 'Schoolactiviteit',
			recordField: 'learnerRefs',
		},
		{
			collection: 'parentSchoolCalendar',
			kind: 'Vakantie',
			recordField: 'schoolId',
			recordKey: 'schoolId',
			expand: {
				field: 'holidays',
				startField: 'startDate',
				endField: 'endDate',
				titleField: 'name',
			},
		},
	],
}

const page = {
	id: 'parentChildren',
	label: 'Mijn kinderen',
	record: {
		collection: 'parentChildren',
		titleFields: ['givenName', 'familyName'],
	},
	blocks: [
		{ type: 'collection', collection: 'parentChildren' },
		kpiBlock,
		{
			type: 'collection',
			collection: 'parentReportCards',
			recordField: 'learnerRef',
		},
		calendarBlock,
		{ type: 'news', label: 'Nieuws', limit: 3 },
	],
}
contribution.pages = [page]

const store = {
	parentChildren: { loading: false, objects: [vera, daan] },
	parentReportCards: {
		loading: false,
		objects: [
			{ id: 'rc1', learnerRef: VERA, periodName: 'Rapport 1 van Vera' },
			{ id: 'rc2', learnerRef: DAAN, periodName: 'Rapport 1 van Daan' },
		],
	},
	parentAttendanceSummary: {
		loading: false,
		objects: [
			{
				id: 's1',
				learnerRef: VERA,
				schoolYear: '2025-2026',
				absentDays: 9,
				absentAuthorisedDays: 9,
				absentUnauthorisedDays: 0,
				lateCount: 1,
				lateMinutes: 5,
			},
			{
				id: 's2',
				learnerRef: VERA,
				schoolYear: '2026-2027',
				absentDays: 5,
				absentAuthorisedDays: 3,
				absentUnauthorisedDays: 2,
				lateCount: 4,
				lateMinutes: 35,
			},
			{
				id: 's3',
				learnerRef: DAAN,
				schoolYear: '2026-2027',
				absentDays: 1,
				absentAuthorisedDays: 1,
				absentUnauthorisedDays: 0,
				lateCount: 0,
				lateMinutes: 0,
			},
		],
	},
	parentSchoolEvents: {
		loading: false,
		objects: [
			{
				id: 'e1',
				title: 'Sportdag',
				startsAt: '2026-10-14',
				learnerRefs: [VERA, DAAN],
			},
			{
				id: 'e2',
				title: 'Schoolreis groep 7',
				startsAt: '2026-10-20T08:30:00+02:00',
				endsAt: '2026-10-20T16:00:00+02:00',
				learnerRefs: [VERA],
			},
			{
				id: 'e3',
				title: 'Kamp groep 4',
				startsAt: '2026-10-21',
				learnerRefs: [DAAN],
			},
			{
				id: 'e4',
				title: 'Studiedag vorig jaar',
				startsAt: '2026-03-11',
				learnerRefs: [VERA],
			},
		],
	},
	parentSchoolCalendar: {
		loading: false,
		objects: [
			{
				id: 'p1',
				schoolId: SCHOOL,
				holidays: [
					{
						name: 'Herfstvakantie',
						startDate: '2026-10-26',
						endDate: '2026-10-30',
					},
				],
			},
			{
				id: 'p2',
				schoolId: SCHOOL,
				holidays: [
					{
						name: 'Herfstvakantie',
						startDate: '2026-10-26',
						endDate: '2026-10-30',
					},
					{ name: 'Kerstvakantie', startDate: 'not a date', endDate: '' },
				],
			},
			{
				id: 'p3',
				schoolId: 'another-school',
				holidays: [
					{
						name: 'Elders vrij',
						startDate: '2026-10-12',
						endDate: '2026-10-12',
					},
				],
			},
		],
	},
	parentGroupMemberships: {
		loading: false,
		objects: [
			{ id: 'm1', learnerRef: VERA, cohortId: GROEP7 },
			{ id: 'm2', learnerRef: DAAN, cohortId: GROEP4 },
		],
	},
}

const feed = [
	{
		id: 'n1',
		title: 'Nieuws voor de hele school',
		body: 'Sportdag',
		target: { schoolRef: SCHOOL },
	},
	{
		id: 'n2',
		title: 'Nieuws voor groep 7',
		body: 'Schoolreis',
		target: { groupRefs: [GROEP7] },
	},
	{
		id: 'n3',
		title: 'Nieuws voor groep 4',
		body: 'Kamp',
		target: { groupRefs: [GROEP4] },
	},
	{ id: 'n4', title: 'Over Vera', body: 'Vera', target: { childRefs: [VERA] } },
]

const TODAY = new Date(2026, 9, 2)

test('rows narrow to the open record, on a value or in a list', () => {
	const rows = store.parentReportCards.objects
	assert.deepEqual(
		narrowToRecord(rows, { recordField: 'learnerRef' }, vera).map((r) => r.id),
		['rc1'],
	)
	assert.deepEqual(
		narrowToRecord(
			store.parentSchoolEvents.objects,
			{ recordField: 'learnerRefs' },
			daan,
		).map((r) => r.id),
		['e1', 'e3'],
	)
	assert.equal(narrowToRecord(rows, { recordField: 'learnerRef' }, null).length, 2)
	assert.equal(narrowToRecord(rows, {}, vera).length, 2)
	assert.deepEqual(
		narrowToRecord(rows, { recordField: 'learnerRef' }, { givenName: 'no id' }),
		[],
		'a record without a value owns nothing',
	)
})

test('a kpi block reads the latest row of the open record', () => {
	const rows = narrowToRecord(
		store.parentAttendanceSummary.objects,
		kpiBlock,
		vera,
	)
	assert.equal(pickRow(rows, kpiBlock.pick).id, 's2')
	assert.equal(pickRow(rows, { field: 'schoolYear', direction: 'asc' }).id, 's1')
	assert.equal(pickRow(rows).id, 's1')
	assert.equal(pickRow([], kpiBlock.pick), null)
})

test('a record title joins its title fields', () => {
	assert.equal(recordTitle(vera, ['givenName', 'familyName']), 'Vera Hulstkamp')
	assert.equal(recordTitle({ name: 'Groep 7' }), 'Groep 7')
	assert.equal(recordTitle(null, ['givenName']), '')
})

test('calendar items: one per row or expanded element, narrowed, deduplicated and in date order', () => {
	const items = calendarItems(calendarBlock, store, vera)
	assert.deepEqual(
		items.map((item) => [dayKey(item.start), item.title, item.kind]),
		[
			['2026-03-11', 'Studiedag vorig jaar', 'Schoolactiviteit'],
			['2026-10-14', 'Sportdag', 'Schoolactiviteit'],
			['2026-10-20', 'Schoolreis groep 7', 'Schoolactiviteit'],
			['2026-10-26', 'Herfstvakantie', 'Vakantie'],
		],
	)
	const holiday = items[3]
	assert.equal(holiday.allDay, true)
	assert.equal(dayKey(holiday.end), '2026-10-30')
	assert.equal(items[2].allDay, false)

	const upcoming = upcomingItems(items, TODAY)
	assert.deepEqual(
		upcoming.map((item) => item.title),
		['Sportdag', 'Schoolreis groep 7', 'Herfstvakantie'],
	)
	assert.deepEqual(
		itemsOnDay(items, new Date(2026, 9, 28)).map((item) => item.title),
		['Herfstvakantie'],
	)
	// Without a record (the guardian's own calendar page) every child's items show.
	assert.ok(
		calendarItems(calendarBlock, store, null).some(
			(i) => i.title === 'Kamp groep 4',
		),
	)
})

test('a month starts on Monday', () => {
	const weeks = monthWeeks(2026, 9)
	assert.equal(weeks[0].length, 7)
	// 1 October 2026 is a Thursday.
	assert.deepEqual(weeks[0].slice(0, 3), [null, null, null])
	assert.equal(weeks[0][3].getDate(), 1)
	assert.equal(weeks.flat().filter(Boolean).length, 31)
})

test('news narrows to the record: its school, its group or the record itself', () => {
	const groups = recordGroups(contribution, store, vera)
	assert.deepEqual(groups, [GROEP7])
	assert.deepEqual(
		newsForRecord(feed, vera, contribution, groups).map((item) => item.id),
		['n1', 'n2', 'n4'],
	)
	assert.deepEqual(
		newsForRecord(
			feed,
			daan,
			contribution,
			recordGroups(contribution, store, daan),
		).map((item) => item.id),
		['n1', 'n3'],
	)
	assert.equal(newsForRecord(feed, null, contribution, []).length, 4)
	assert.deepEqual(
		newsForRecord(
			[{ id: 'x', title: 'No target' }],
			vera,
			contribution,
			groups,
		).map((i) => i.id),
		['x'],
		'an item without a target is the subject’s own feed and stays',
	)
})

test('the record page loads its record collection and every block source', () => {
	assert.deepEqual(collectionIdsFor(page), [
		'parentChildren',
		'parentAttendanceSummary',
		'parentReportCards',
		'parentSchoolEvents',
		'parentSchoolCalendar',
	])
	const kinds = resolveBlocks(page, contribution).map((item) => item.kind)
	assert.deepEqual(kinds, ['table', 'kpi', 'table', 'calendar', 'news'])
	const missing = resolveBlocks(
		{
			blocks: [
				{ type: 'kpi', collection: 'nope', cards: [] },
				{ type: 'calendar', sources: [{ collection: 'nope' }] },
			],
		},
		contribution,
	)
	assert.deepEqual(
		missing.map((item) => item.kind),
		['none', 'none'],
	)
})

/**
 * Render the record page as the guardian sees it.
 *
 * @param {object} extra Props to add.
 * @return {Promise<string>}
 */
function renderPage(extra = {}) {
	return renderSfc('src/site/pages/collections/ContributionPage.vue', {
		entry: {
			key: 'learniq:parentChildren',
			label: 'Mijn kinderen',
			page,
			contribution,
		},
		api: {},
		t,
		locale: 'nl',
		initialData: store,
		initialFeed: feed,
		today: TODAY,
		...extra,
	})
}

test('with two children the page opens on the list and asks to open one', async () => {
	const html = await renderPage()
	assert.match(html, /data-testid="record-hint"/)
	assert.match(html, /Open een naam om alles daarover te zien\./)
	assert.match(html, /data-testid="collection-table"/)
	assert.doesNotMatch(html, /data-testid="kpi-block"|data-testid="calendar-block"/)
	assert.doesNotMatch(html, /data-testid="record-head"/)
})

test("an open record shows the child's name, the way back and only her blocks", async () => {
	const html = await renderPage({ initialSelected: { parentChildren: vera } })
	assert.match(
		html,
		/<h2[^>]*class="utrecht-heading-2 pq-record__title"[^>]*>\s*Vera Hulstkamp\s*<\/h2>/,
	)
	assert.match(html, /data-testid="record-back"[^>]*>\s*Terug naar Mijn kinderen/)
	assert.doesNotMatch(html, /data-testid="record-hint"/)
	// The list itself is gone, the record's own blocks show.
	assert.doesNotMatch(html, />Voornaam</)
	assert.match(html, /Rapport 1 van Vera/)
	assert.doesNotMatch(html, /Rapport 1 van Daan/)
	// Sections sit one level below the child's name.
	assert.match(html, /<h3[^>]*class="utrecht-heading-3">Rapporten<\/h3>/)
	assert.doesNotMatch(
		html,
		/data-testid="contribution-page-group"/,
		'one child needs no child headings',
	)
})

test('kpi cards read the latest school year, with details and a worded mark', async () => {
	const html = await renderPage({ initialSelected: { parentChildren: vera } })
	const cards = html.match(/<li class="pq-kpi__card[^"]*"[^]*?<\/li>/g) || []
	assert.equal(cards.length, 3)
	assert.match(
		cards[0],
		/Afwezig[^]*>5<\/span>[^]*dagen[^]*3 met toestemming, 2 zonder toestemming/,
	)
	assert.match(cards[1], /Te laat[^]*>4<\/span>[^]*keer[^]*35 minuten/)
	assert.match(cards[2], /pq-kpi__card--highlight/)
	assert.match(
		cards[2],
		/Ongeoorloofd afwezig[^]*>2<\/span>[^]*data-testid="kpi-attention"[^>]*>\s*Let op/,
	)
	assert.doesNotMatch(cards[0], /kpi-attention/)
})

test('a kpi block without a row says there are no figures yet', async () => {
	const html = await renderPage({
		initialSelected: { parentChildren: vera },
		initialData: {
			...store,
			parentAttendanceSummary: { loading: false, objects: [] },
		},
	})
	assert.match(
		html,
		/data-testid="kpi-empty"[^>]*>\s*<em>Nog geen cijfers\.<\/em>/,
	)
})

test("the calendar lists the child's coming items per month, with her school's holidays", async () => {
	const html = await renderPage({ initialSelected: { parentChildren: vera } })
	const items = html.match(/data-testid="calendar-item"[^]*?<\/li>/g) || []
	assert.equal(items.length, 3)
	assert.match(items[0], /Sportdag[^]*Schoolactiviteit/)
	assert.match(items[1], /Schoolreis groep 7/)
	assert.match(items[2], /t\/m[^]*Herfstvakantie[^]*Vakantie/)
	assert.doesNotMatch(html, /Kamp groep 4|Elders vrij|Studiedag vorig jaar/)
	assert.match(html, /aria-pressed="true"[^>]*data-testid="calendar-view-list"/)
	assert.match(html, /<h4[^>]*>\s*oktober 2026\s*<\/h4>/)
})

test('the month view is a table with weekday headers, today marked', async () => {
	const html = await renderSfc(
		'src/site/components/collections/CalendarBlock.vue',
		{
			items: calendarItems(calendarBlock, store, vera),
			t: collectionsTranslator(t, 'nl'),
			locale: 'nl',
			today: TODAY,
			initialView: 'month',
		},
	)
	assert.match(html, /data-testid="calendar-month"/)
	assert.match(html, /<th scope="col" abbr="maandag">\s*ma\s*<\/th>/)
	assert.match(
		html,
		/aria-current="date">(<!--\[-->)?<span class="pq-calendar__date">2<\/span>/,
	)
	assert.match(html, /data-testid="calendar-previous"[^>]*>\s*Vorige maand/)
	assert.match(html, /ma 26 okt t\/m vr 30 okt/)
	assert.equal((html.match(/data-testid="calendar-month-item"/g) || []).length, 3)
})

test('the news block shows the news for this child only', async () => {
	const html = await renderPage({ initialSelected: { parentChildren: vera } })
	assert.match(html, /Nieuws voor de hele school/)
	assert.match(html, /Nieuws voor groep 7/)
	assert.match(html, /Over Vera/)
	assert.doesNotMatch(html, /Nieuws voor groep 4/)
	assert.match(html, /data-testid="news-block-all"[^>]*>\s*Al het nieuws/)
})

test('one child opens at once, without a way back', async () => {
	const html = await renderPage({
		initialData: {
			...store,
			parentChildren: { loading: false, objects: [vera] },
		},
	})
	assert.match(html, /Vera Hulstkamp/)
	assert.doesNotMatch(html, /data-testid="record-back"/)
	assert.match(html, /data-testid="kpi-block"/)
})

test("another child's record does not open", async () => {
	const stranger = {
		id: 'ee010008-0000-4000-8000-000000009999',
		givenName: 'Iemand',
		familyName: 'Anders',
	}
	const html = await renderPage({ initialSelected: { parentChildren: stranger } })
	assert.doesNotMatch(html, /Iemand Anders/)
	assert.doesNotMatch(html, /data-testid="kpi-block"/)
	assert.match(html, /data-testid="record-hint"/)
})
