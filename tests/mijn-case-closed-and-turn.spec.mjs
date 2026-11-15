#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// mijn-case-closed-and-turn.spec.mjs: when a case card counts a case as
// closed and which sentence says whose turn it is
// (src/site/components/mijn/cases.js); pinned on every branch before both
// were split into named steps.
//
// Usage:
//   node --test tests/mijn-case-closed-and-turn.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { isClosedCase, turnSentence } from '../src/site/components/mijn/cases.js'

test('a case is closed by the server\'s mark, else by a filled closed field', () => {
	const collection = { closedField: 'closedAt' }
	assert.equal(isClosedCase({ _closed: true }, {}), true)
	assert.equal(isClosedCase({ _closed: false, closedAt: '2026-01-01' }, collection), false, 'the mark wins')
	assert.equal(isClosedCase({ _closed: 'yes', closedAt: '' }, collection), false)
	assert.equal(isClosedCase({ closedAt: '2026-01-01' }, collection), true)
	assert.equal(isClosedCase({ closedAt: 0 }, collection), true)
	assert.equal(isClosedCase({ closedAt: true }, collection), true)
	for (const value of [undefined, null, '', false]) {
		assert.equal(isClosedCase({ closedAt: value }, collection), false, String(value))
	}
	assert.equal(isClosedCase({ closedAt: 'x' }, {}), false, 'no closed field, never closed')
	assert.equal(isClosedCase({ closedAt: 'x' }, null), false)
	assert.equal(isClosedCase(null, collection), false)
})

test('the turn reads in the words the collection gives its value, else nothing', () => {
	const collection = { turnField: 'turn', fieldConfigs: { turn: { valueLabels: { resident: 'U bent aan de beurt', 1: 'Wij', toString: 7 } } } }
	assert.equal(turnSentence({ turn: 'resident' }, collection), 'U bent aan de beurt')
	assert.equal(turnSentence({ turn: 1 }, collection), 'Wij')
	assert.equal(turnSentence({ turn: 'toString' }, collection), '')
	assert.equal(turnSentence({ turn: 'municipality' }, collection), '')
	assert.equal(turnSentence({ turn: null }, collection), '')
	assert.equal(turnSentence({}, collection), '')
	assert.equal(turnSentence({ turn: 'resident' }, { turnField: 'turn' }), '', 'a raw value is a code, not a sentence')
	assert.equal(turnSentence({ turn: 'resident' }, { ...collection, turnField: '' }), '')
	assert.equal(turnSentence(null, collection), '')
	assert.equal(turnSentence({ turn: 'resident' }, null), '')
})
