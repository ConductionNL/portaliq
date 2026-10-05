#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-school-displays.spec.mjs: the Mijn omgeving displays of the school
// portals (site-school-blocks wave 2), as the plain functions they draw from.
//
// Usage:
//   node --test tests/site-school-displays.spec.mjs
//
// @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	barRows,
	cardParts,
	chipRows,
	dateRows,
	markText,
	numberOf,
	segmentsOf,
	textOf,
	toneOf,
} from '../src/site/components/mijn/displays.js'
import { firstNameOf, greetingFor } from '../src/site/components/mijn/greeting.js'
import { homeGreets } from '../src/site/components/mijn/home.js'
import { resolveBlocks } from '../src/site/pages/collections/pageBlocks.js'
import { calendarItems } from '../src/shared/recordPage.js'

const COLLECTION = {
	id: 'meldingen',
	fieldConfigs: {
		reden: { valueLabels: { sick: 'Ziek' } },
		status: { valueLabels: { seen: 'Gezien door de leerkracht' } },
	},
}

test('a value reads as its word, a missing field as nothing', () => {
	assert.equal(textOf({ reden: 'sick' }, 'reden', COLLECTION), 'Ziek')
	assert.equal(textOf({ reden: 'other' }, 'reden', COLLECTION), 'other')
	assert.equal(textOf({ count: 3 }, 'count'), '3')
	assert.equal(textOf({ nested: { a: 1 } }, 'nested'), '')
	assert.equal(textOf({}, 'missing'), '')
	assert.equal(textOf({ a: 'x' }, ''), '')
})

test('dated rows carry every part the block names, with the status tone', () => {
	const block = {
		dateField: 'datum',
		titleFields: ['kind', 'reden'],
		quoteField: 'toelichting',
		statusField: 'status',
		statusNoteField: 'gezienDoor',
		statusTones: { seen: 'success' },
	}
	const [row] = dateRows(
		[
			{
				id: 'r1',
				datum: '2026-10-05',
				kind: 'Sami',
				reden: 'sick',
				toelichting: 'Buikgriep',
				status: 'seen',
				gezienDoor: 'Juf Esra, 8.12 uur',
			},
		],
		block,
		COLLECTION,
	)
	assert.equal(row.key, 'r1')
	assert.equal(row.date, '2026-10-05')
	assert.equal(row.title, 'Sami · Ziek')
	assert.equal(row.quote, 'Buikgriep')
	assert.equal(row.status, 'Gezien door de leerkracht')
	assert.equal(row.tone, 'success')
	assert.equal(row.statusNote, 'Juf Esra, 8.12 uur')
	assert.equal(
		toneOf({ status: 'open' }, block),
		'neutral',
		'a value without a tone is neutral',
	)
	assert.equal(
		toneOf({ status: 'seen' }, { ...block, statusTones: { seen: 'purple' } }),
		'neutral',
	)
})

test('a card gains a sub line, a status with its note and what is coming up', () => {
	const parts = cardParts(
		{
			groep: 'Groep 7',
			leerkracht: 'Meester Daan',
			vandaag: 'Op school',
			notitie: 'Gym om 13.15 uur',
			binnenkort: 'Woensdag: kinderboerderij',
		},
		{
			subtitleFields: ['groep', 'leerkracht'],
			statusField: 'vandaag',
			noteField: 'notitie',
			soonField: 'binnenkort',
			statusTones: { 'Op school': 'success' },
		},
	)
	assert.deepEqual(parts, {
		subtitle: 'Groep 7 · Meester Daan',
		status: 'Op school',
		tone: 'success',
		note: 'Gym om 13.15 uur',
		soon: 'Woensdag: kinderboerderij',
	})
})

test('marks read with a decimal comma in Dutch and bars are a share of the maximum', () => {
	assert.equal(numberOf('7,9'), 7.9)
	assert.equal(numberOf(''), null)
	assert.equal(numberOf('acht'), null)
	assert.equal(markText(7.9, 'nl'), '7,9')
	assert.equal(markText(8, 'en'), '8.0')
	const bars = barRows(
		[
			{ vak: 'Rekenen', cijfer: 7.9 },
			{ vak: 'Taal', cijfer: '8,3' },
			{ vak: 'Gym', cijfer: null },
			{ vak: '', cijfer: 6 },
		],
		{ labelField: 'vak', valueField: 'cijfer' },
		'nl',
	)
	assert.deepEqual(
		bars.map((bar) => [bar.label, bar.text, bar.width]),
		[
			['Rekenen', '7,9', '79%'],
			['Taal', '8,3', '83%'],
		],
	)
	assert.equal(
		barRows([{ vak: 'X', cijfer: 30 }], {
			labelField: 'vak',
			valueField: 'cijfer',
			max: 20,
		})[0].width,
		'100%',
		'a bar never runs past its track',
	)
})

test('a mark below the pass mark is marked low, and so is a low average', () => {
	const [row] = chipRows(
		[{ vak: 'Wiskunde A', cijfers: [5.2, '6,1', 4.7, 'x'], gemiddelde: 5.4 }],
		{
			labelField: 'vak',
			valuesField: 'cijfers',
			averageField: 'gemiddelde',
			lowBelow: 5.5,
		},
		'nl',
	)
	assert.deepEqual(row.marks, [
		{ text: '5,2', low: true },
		{ text: '6,1', low: false },
		{ text: '4,7', low: true },
	])
	assert.equal(row.average, '5,4')
	assert.equal(row.averageLow, true)
	assert.equal(
		chipRows([{ vak: 'X', cijfers: [3] }], {
			labelField: 'vak',
			valuesField: 'cijfers',
		})[0].marks[0].low,
		false,
		'no pass mark, nothing is low',
	)
})

test('a segmented figure shares out its total, from a field or a fixed target', () => {
	const block = {
		segments: [
			{ field: 'goedgekeurd', label: 'Goedgekeurd', tone: 'positive' },
			{ field: 'wachtend', label: 'Wacht', tone: 'waiting' },
			{ field: 'terug', label: 'Terug', tone: 'warning' },
		],
		target: 480,
	}
	const figure = segmentsOf({ goedgekeurd: 96, wachtend: 16, terug: 8 }, block)
	assert.equal(figure.total, 480)
	assert.deepEqual(
		figure.segments.map((segment) => segment.width),
		['20.00%', '3.33%', '1.67%'],
	)
	assert.equal(
		segmentsOf(
			{ goedgekeurd: 10, wachtend: 10, terug: 0, doel: 40 },
			{ ...block, totalField: 'doel', target: undefined },
		).total,
		40,
	)
	assert.equal(
		segmentsOf(
			{ goedgekeurd: 3, wachtend: 1, terug: 0 },
			{ segments: block.segments },
		).total,
		4,
		'without a total, the parts are the whole',
	)
	assert.equal(segmentsOf(null, block), null)
})

test('the greeting goes by the hour and uses only a real first name', () => {
	const tr = (key, vars) => key.replace('{name}', vars?.name ?? '')
	assert.equal(
		greetingFor(
			{ displayName: 'Fatima Hulstkamp' },
			new Date(2026, 9, 5, 8, 30),
			tr,
		),
		'Good morning, Fatima',
	)
	assert.equal(
		greetingFor(
			{ displayName: 'Fatima Hulstkamp' },
			new Date(2026, 9, 5, 14),
			tr,
		),
		'Good afternoon, Fatima',
	)
	assert.equal(
		greetingFor(
			{ displayName: 'Fatima Hulstkamp' },
			new Date(2026, 9, 5, 19),
			tr,
		),
		'Good evening, Fatima',
	)
	assert.equal(
		greetingFor({ displayName: '123456782' }, new Date(2026, 9, 5, 9), tr),
		'Good morning',
	)
	assert.equal(firstNameOf({ name: 'ref-1', subjectRef: 'ref-1' }), '')
	assert.equal(firstNameOf(null), '')
})

test('/mijn hands its heading to a greeting only on one home page that has one', () => {
	const greeting = { page: { blocks: [{ type: 'greeting' }, { type: 'tasks' }] } }
	assert.equal(homeGreets([greeting]), true)
	assert.equal(homeGreets([greeting, greeting]), false)
	assert.equal(homeGreets([{ page: { blocks: [{ type: 'tasks' }] } }]), false)
	assert.equal(homeGreets([]), false)
})

test('a greeting resolves its action like a cta, and an unknown action is none', () => {
	const contribution = {
		actions: [{ id: 'createExcuseRequest', type: 'create' }],
		collections: [],
	}
	const [known] = resolveBlocks(
		{
			blocks: [
				{
					type: 'greeting',
					action: 'createExcuseRequest',
					label: 'Afwezig melden',
				},
			],
		},
		contribution,
	)
	assert.equal(known.kind, 'greeting')
	assert.equal(known.action.id, 'createExcuseRequest')
	const [plain] = resolveBlocks({ blocks: [{ type: 'greeting' }] }, contribution)
	assert.equal(plain.kind, 'greeting')
	assert.equal(plain.action, null)
})

test('a calendar item carries its source meta line', () => {
	const items = calendarItems(
		{
			sources: [
				{
					collection: 'agenda',
					startField: 'start',
					titleField: 'titel',
					metaField: 'wie',
				},
			],
		},
		{
			agenda: {
				objects: [
					{
						id: 'e1',
						start: '2026-10-07',
						titel: 'Schoolfotograaf',
						wie: 'Woensdag, Vera en Sami',
					},
				],
			},
		},
		null,
	)
	assert.equal(items[0].meta, 'Woensdag, Vera en Sami')
	const plain = calendarItems(
		{
			sources: [
				{ collection: 'agenda', startField: 'start', titleField: 'titel' },
			],
		},
		{ agenda: { objects: [{ id: 'e1', start: '2026-10-07', titel: 'X' }] } },
		null,
	)
	assert.equal(plain[0].meta, '')
})
