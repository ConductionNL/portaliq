#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// catalogue-cards.spec.mjs: the catalogue block's result cards, dated rows,
// tables and the query a page block's source stands for, pinned in a golden
// file before they were split into named steps.
//
// Usage:
//   node --test tests/catalogue-cards.spec.mjs
//   GOLDEN_RECORD=1 node --test tests/catalogue-cards.spec.mjs

import { test } from 'node:test'
import {
	eventItemsOf,
	resultCard,
	sourceQuery,
	tableOf,
} from '../src/site/widgets/nlCatalogue/catalogue.js'
import { golden } from './support/golden.mjs'

const FULL = {
	id: 'learniq:course:1',
	type: 'course',
	kind: 'Cursus',
	title: 'F-gassen',
	summary: 'Een dag',
	date: '2026-10-08',
	meta: ['1 dag'],
	note: 'Nog 1 plek',
	noteTone: 'warning',
	badge: 'Nieuw',
	href: '/cursus/1',
}

test('result cards', () => {
	const file = golden('catalogue-result-cards')
	file.check('full', resultCard(FULL, { lang: 'nl', newsRoute: '/nieuws' }))
	file.check('positive tone, external', resultCard({ ...FULL, noteTone: 'positive', href: 'https://example.org/x' }, { lang: 'en' }))
	file.check('unknown tone, no href', resultCard({ ...FULL, noteTone: 'danger', href: '', meta: 'x' }, { lang: 'nl' }))
	file.check('news with id', resultCard({ type: 'news', newsId: 'n 1', href: '/ignored', kind: 'x' }, { lang: 'nl', newsRoute: '/school/nieuws/' }))
	file.check('news without route', resultCard({ type: 'news', newsId: 'n1' }, { lang: 'en' }))
	file.check('news without id', resultCard({ type: 'news', href: '/n' }, { lang: 'en', newsRoute: '/x' }))
	file.check('nothing', resultCard(null, { lang: 'nl' }))
	file.save()
})

test('source queries', () => {
	const file = golden('catalogue-source-queries')
	file.check('null', sourceQuery(null))
	file.check('no kind', sourceQuery({ app: 'learniq', kind: '  ' }))
	file.check('no app', sourceQuery({ app: 3, kind: 'course' }))
	file.check('bare', sourceQuery({ app: ' learniq ', kind: ' course ' }))
	file.check('full', sourceQuery({
		app: 'learniq',
		kind: 'course',
		categories: ['a', '', 3, 'b'],
		range: 'schoolYear',
		limit: '7.9',
		filters: { Plaats: ['Hal', '', 2], Niveau: 'B1', Leeg: [], Ook: '' },
	}))
	file.check('limits', [0, -3, 1, 50, 51, 'x', null].map((limit) => sourceQuery({ app: 'a', kind: 'k', limit }).limit))
	file.check('other range, categories not a list, filters empty', sourceQuery({ app: 'a', kind: 'k', range: 'month', categories: 'a', filters: {} }))
	file.save()
})

test('dated rows and tables', () => {
	const file = golden('catalogue-rows-tables')
	file.check('event rows', eventItemsOf([
		FULL,
		{ date: '2026-10-08T10:00:00Z', endDate: '2026-10-09T12:00:00Z', dateLabel: 'Wo', title: 'T', meta: ['a', 'b'], noteTone: 'positive' },
		{ date: '2026-10-08' },
		{ title: 'no date' },
		null,
	]))
	file.check('event rows of nothing', eventItemsOf('x'))
	file.check('table', tableOf(
		[{ cells: { a: 'A', b: 2 } }, null, { cells: { b: null } }],
		['a', { key: 'b', label: 'Bee' }, { key: 'c' }, { label: 'no key' }, null],
	))
	file.check('table of nothing', tableOf(null, null))
	file.save()
})
