#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// nav-cards-cases-steps.spec.mjs: the step-up navigation entries, a
// conversation card and several "My cases" answers merged as one, pinned
// before each was split into named steps.
//
// Usage:
//   node --test tests/nav-cards-cases-steps.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { navEntryForRoute, stepUpEntries } from '../src/shared/portalNav.js'
import { mergedAnswer } from '../src/site/pages/e/caseParties.js'
import { threadCard } from '../src/site/pages/inbox/conversations.js'

test('stepUpEntries: one entry per collection, skipping taken and unnamed ones', () => {
	assert.deepEqual(stepUpEntries(null), [])
	assert.deepEqual(stepUpEntries('x', null), [])
	assert.deepEqual(
		stepUpEntries(
			[
				{ app: 'raadiq', collection: 'papers', label: 'Stukken', minTrust: 'high' },
				{ app: 'raadiq', collection: 'papers', label: 'Twice' },
				{ app: 'raadiq', collection: 'votes', label: '', minTrust: 3 },
				{ app: 'raadiq', collection: 'taken' },
				{ app: '', collection: 'x' },
				{ app: 'a', collection: '' },
				{ app: 5, collection: 'y' },
				null,
				{ app: 'b', collection: 'c', label: 7 },
			],
			[{ key: 'raadiq:taken' }],
		),
		[
			{ key: 'raadiq:papers', label: 'Stukken', icon: 'Lock', stepUp: { app: 'raadiq', collection: 'papers', minTrust: 'high' } },
			{ key: 'raadiq:votes', label: 'votes', icon: 'Lock', stepUp: { app: 'raadiq', collection: 'votes', minTrust: '' } },
			{ key: 'b:c', label: 'c', icon: 'Lock', stepUp: { app: 'b', collection: 'c', minTrust: '' } },
		],
	)
})

test('threadCard: titles, names, the record, the moment and the unread mark', () => {
	const tr = (key, vars) => (vars ? `${key}|${JSON.stringify(vars)}` : `t:${key}`)
	assert.deepEqual(threadCard(null, tr), {
		title: 't:Conversation with school',
		who: 't:School',
		initials: threadCard(null, tr).initials,
		about: '',
		when: '',
		preview: '',
		isNew: false,
	})
	assert.equal(threadCard({ kind: 'group', title: '  ' }, tr).title, 't:Group conversation')
	const card = threadCard(
		{
			title: ' Uitje ',
			staffName: ' Meester Daan ',
			recordLabel: 'Sanne, groep 5',
			createdAt: '2026-01-01',
			summary: { lastSentAt: '2026-02-02', lastBody: 'Tot morgen', unread: '2' },
		},
		tr,
	)
	assert.deepEqual(card, {
		title: 'Uitje',
		who: 'Meester Daan',
		initials: 'MD',
		about: 'about {name}|{"name":"Sanne"}',
		when: '2026-02-02',
		preview: 'Tot morgen',
		isNew: true,
	})
	assert.equal(threadCard({ createdAt: '2026-01-01', summary: { unread: 0 } }, tr).when, '2026-01-01')
	assert.equal(threadCard({ createdAt: '2026-01-01', summary: { unread: 0 } }, tr).isNew, false)
})

test('mergedAnswer: the first answer stands for a failure, later failures are left out, a case read twice stands once', () => {
	assert.equal(mergedAnswer([]), null)
	assert.equal(mergedAnswer([null, { ok: true, cases: [] }]), null)
	const refused = { ok: false, status: 409 }
	assert.equal(mergedAnswer([refused, { ok: true, cases: [] }]), refused)
	const own = { ok: true, status: 200, mandates: ['m'], cases: [{ id: 'c1', _source: { appId: 'zaak', collection: 'cases' } }, { title: 'loose' }] }
	const other = {
		ok: true,
		cases: [
			{ uuid: 'c1', _source: { appId: 'zaak', collection: 'cases' } },
			{ id: 'c2', _source: { appId: 'zaak', collection: 'cases' } },
			{ title: 'loose' },
			{ title: 'loose 2' },
		],
	}
	assert.deepEqual(mergedAnswer([own, null, { ok: false }, other, { ok: true, cases: 'x' }]), {
		ok: true,
		status: 200,
		mandates: ['m'],
		cases: [
			{ id: 'c1', _source: { appId: 'zaak', collection: 'cases' } },
			{ title: 'loose' },
			{ id: 'c2', _source: { appId: 'zaak', collection: 'cases' } },
			{ title: 'loose 2' },
		],
	})
})

test('navEntryForRoute: exact, a record of a record page, and none', () => {
	const records = { contribution: { app: 'school' }, page: { id: 'kinderen', records: true } }
	const record = { contribution: { app: 'school' }, page: { id: 'kind', record: true } }
	const plain = { contribution: { app: 'school' }, page: { id: 'nieuws' } }
	const special = { special: 'school' }
	const nav = [special, plain, records, record]
	assert.equal(navEntryForRoute(nav, '/elders'), null)
	assert.equal(navEntryForRoute(nav, '/mijn/school/nieuws'), plain)
	assert.equal(navEntryForRoute(nav, '/mijn/school/kinderen/k%201'), records)
	assert.equal(navEntryForRoute(nav, '/mijn/school/kind/k1'), record)
	assert.equal(navEntryForRoute(nav, '/mijn/school/nieuws/n1'), null, 'a page without records chooses none')
	assert.equal(navEntryForRoute([special], '/mijn/school/x/1'), null)
	assert.equal(navEntryForRoute(nav, '/mijn/school/onbekend'), null)
	assert.equal(navEntryForRoute(null, '/mijn/school/kind/k1'), null)
	assert.equal(navEntryForRoute(nav, '/mijn'), null)
})
