#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// date-times-to-the-minute.spec.mjs: a date and time on the site reads to the
// minute, in the page's language (date-times-read-without-seconds). The
// Vergaderkalender showed "24-9-2026, 19:30:00".
//
// Usage:
//   node --test tests/date-times-to-the-minute.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { formatCell } from '../src/site/components/collections/cells.js'
import {
	formatDateTime,
	formatMessageMoment,
} from '../src/site/pages/inbox/inbox.js'

const MOMENT = '2026-09-24T19:30:05'

test('a datetime cell reads to the minute', () => {
	const dutch = formatCell(MOMENT, 'datetime', { locale: 'nl', t: (key) => key })
	assert.equal(dutch, '24-9-2026, 19:30')
	const english = formatCell(MOMENT, 'datetime', { locale: 'en-GB', t: (key) => key })
	assert.equal(english, '24/09/2026, 19:30')
})

test('an inbox date reads to the minute', () => {
	assert.equal(formatDateTime(MOMENT, 'nl'), '24-9-2026, 19:30')
	assert.equal(formatDateTime(MOMENT, 'en'), '24/09/2026, 19:30')
})

test('a message in the inbox reads its day with the month in words, to the minute (board Berichten)', () => {
	assert.equal(formatMessageMoment('2026-10-04T13:59:42', 'nl'), '4 oktober 2026, 13:59')
	assert.equal(formatMessageMoment('2026-10-04T13:59:42', 'en'), '4 October 2026, 13:59')
	assert.equal(formatMessageMoment('', 'nl'), '')
	assert.equal(formatMessageMoment('not a date', 'nl'), 'not a date')
})
