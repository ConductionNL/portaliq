#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// rows-and-actions-steps.spec.mjs: the inbox rows and when a message came
// in, and an action's day, days left and history lines, pinned before each
// was split into named steps.
//
// Usage:
//   node --test tests/rows-and-actions-steps.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { inboxRows, receivedInWords } from '../src/site/components/mijn/rows.js'
import { daysLeftLine, dayText, historyText } from '../src/site/lib/actions.js'

const tr = (key, vars) => (vars ? `${key}|${JSON.stringify(vars)}` : key)

test('inboxRows: the block\'s collection, unread first, newest first, at most its limit', () => {
	const messages = [
		{ id: 1, read: true, receivedAt: '2026-03-03T10:00:00Z', _source: { appId: 'school', collection: 'inbox' } },
		{ id: 2, read: false, receivedAt: '2026-03-01T10:00:00Z', _source: { appId: 'school', collection: 'inbox' } },
		{ id: 3, read: false, receivedAt: '2026-03-02T10:00:00Z', _source: { appId: 'school', collection: 'other' } },
		null,
		{ id: 4, read: false, receivedAt: 'nope', _source: { appId: 'school', collection: 'inbox' } },
		{ id: 5, read: false, receivedAt: '2026-03-01T10:00:00Z', _source: { appId: 'zorg', collection: 'inbox' } },
		{ id: 6, read: false, _source: { appId: 'school', collection: 'inbox' } },
		{ id: 7, read: false, receivedAt: '2026-03-01T10:00:00Z', _source: { appId: 'school', collection: 'inbox' } },
	]
	assert.deepEqual(inboxRows(messages, { collection: 'inbox' }, 'school').map((m) => m.id), [2, 7, 4, 6, 1])
	assert.deepEqual(inboxRows(messages, {}, 'school').map((m) => m.id), [3, 2, 5, 7, 4], 'five by default')
	assert.deepEqual(inboxRows(messages, { limit: 2 }, 'school').map((m) => m.id), [3, 2])
	assert.deepEqual(inboxRows(null, null, 'x'), [])
})

test('receivedInWords: today at a time, else the day and the time, in Dutch or English', () => {
	const today = new Date(2026, 2, 10, 9, 0)
	const todayAt = new Date(2026, 2, 10, 14, 20).toISOString()
	const earlier = new Date(2026, 2, 3, 8, 5).toISOString()
	const lastYear = new Date(2025, 11, 31, 23, 59).toISOString()
	assert.equal(receivedInWords(todayAt, today, tr), 'Today at {time}|{"time":"14.20"}')
	assert.equal(receivedInWords(todayAt, today, tr, 'en'), 'Today at {time}|{"time":"14:20"}')
	assert.match(receivedInWords(earlier, today, tr), /^\{date\} at \{time\}\|\{"date":"3 maart","time":"08\.05"\}$/)
	assert.match(receivedInWords(earlier, today, tr, 'en-GB'), /^\{date\} at \{time\}\|\{"date":"3 March","time":"08:05"\}$/)
	assert.match(receivedInWords(lastYear, today, tr), /2025/)
	assert.equal(receivedInWords('', today, tr), '')
	assert.equal(receivedInWords('nope', today, tr), '')
	assert.equal(receivedInWords(todayAt, 'nope', tr), '')
})

test('dayText: a day in words, with or without the year, Dutch or English', () => {
	assert.equal(dayText('2026-10-14', 'nl'), '14 oktober 2026')
	assert.equal(dayText('2026-10-14T08:00:00', 'EN-us'), '14 October 2026')
	assert.equal(dayText('2026-10-14', 'nl', false), '14 oktober')
	assert.equal(dayText('', 'nl'), '')
	assert.equal(dayText('14-10-2026', 'nl'), '')
	assert.equal(dayText('2026-10-14', null), '14 oktober 2026')
})

const WORDS = {
	overdue: 'verlopen',
	today: 'vandaag',
	dayLeft: 'nog 1 dag',
	daysLeft: 'nog {days} dagen',
	created: 'Aangemaakt',
	createdDue: 'Aangemaakt, klaar op {date}',
	statusChange: 'Status van {from} naar {to}',
	fileAdded: 'Bestand {name} toegevoegd',
	fileRemoved: 'Bestand {name} verwijderd',
	assigneeChange: 'Toegewezen aan {to}',
	fieldChange: '{field} gewijzigd',
	todo: 'Te doen',
	busy: 'Bezig',
	fieldTitle: 'Titel',
	endDate: 'Einddatum',
}

test('daysLeftLine: done, no date, overdue, today, one day, more days and an unreadable date', () => {
	assert.equal(daysLeftLine({ status: 'done', endDate: '2026-03-10' }, '2026-03-01', WORDS), '')
	assert.equal(daysLeftLine({ status: 'open', endDate: '' }, '2026-03-01', WORDS), '')
	assert.equal(daysLeftLine({ status: 'open', endDate: '2026-03-10' }, '', WORDS), '')
	assert.equal(daysLeftLine({ status: 'open', endDate: '2026-02-28' }, '2026-03-01', WORDS), 'verlopen')
	assert.equal(daysLeftLine({ status: 'open', endDate: '2026-03-01T12:00' }, '2026-03-01', WORDS), 'vandaag')
	assert.equal(daysLeftLine({ status: 'open', endDate: '2026-03-02' }, '2026-03-01', WORDS), 'nog 1 dag')
	assert.equal(daysLeftLine({ status: 'open', endDate: '2026-03-07' }, '2026-03-01', WORDS), 'nog 6 dagen')
	assert.equal(daysLeftLine({ status: 'open', endDate: 'nope' }, '2026-03-01', WORDS), '')
})

test('historyText: every kind of line', () => {
	assert.equal(historyText({ kind: 'created', to: '' }, WORDS, 'nl'), 'Aangemaakt')
	assert.equal(historyText({ kind: 'created', to: '2026-10-14' }, WORDS, 'nl'), 'Aangemaakt, klaar op 14 oktober 2026')
	assert.equal(historyText({ kind: 'status', from: 'todo', to: 'busy' }, WORDS, 'nl'), 'Status van Te doen naar Bezig')
	assert.equal(historyText({ kind: 'status', from: 'x', to: 'y' }, WORDS, 'nl'), 'Status van x naar y')
	assert.equal(historyText({ kind: 'file', to: 'a.pdf' }, WORDS, 'nl'), 'Bestand a.pdf toegevoegd')
	assert.equal(historyText({ kind: 'fileRemoved', from: 'a.pdf' }, WORDS, 'nl'), 'Bestand a.pdf verwijderd')
	assert.equal(historyText({ kind: 'field', field: 'assignee', to: 'Kees' }, WORDS, 'nl'), 'Toegewezen aan Kees')
	assert.equal(historyText({ kind: 'field', field: 'title' }, WORDS, 'nl'), 'Titel gewijzigd')
	assert.equal(historyText({ kind: 'field', field: 'colour' }, WORDS, 'nl'), 'colour gewijzigd')
	assert.equal(historyText({ kind: 'constructor', field: 'endDate' }, WORDS, 'nl'), 'Einddatum gewijzigd')
})
