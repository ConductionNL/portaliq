#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// publication-detail.spec.mjs: the reaction period line of the publication
// page (publication-detail-page-complete, REQ-PDC-005). The state is the one
// opencatalogi reports (CommentPeriodService::publicView(), REQ-PCP-004); the
// portal never computes one itself.
//
// Usage:
//   node --test tests/publication-detail.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { commentPeriodLine } from '../src/site/lib/publicationDetail.js'

const nl = JSON.parse(
	readFileSync(new URL('../src/shared/i18n/nl.json', import.meta.url), 'utf8'),
)
function t(key, vars = {}) {
	let text = nl[key] || key
	for (const [name, value] of Object.entries(vars)) {
		text = text.replace(`{${name}}`, String(value))
	}
	return text
}

// The keys of CommentPeriodService::publicView() on opencatalogi development.
function period(state, extra = {}) {
	return {
		commentPeriod: {
			publication: 'p-1',
			state,
			startDate: '2026-10-19',
			endDate: '2026-11-16',
			termDays: 28,
			legalRemedy: 'zienswijze',
			reactionFormUrl:
				state === 'open' ? 'https://gemeente.example/zienswijze' : null,
			announcementUrl: 'https://gemeente.example/bekendmaking',
			...extra,
		},
	}
}

test('upcoming says from', () => {
	assert.deepEqual(commentPeriodLine(period('upcoming'), t), {
		state: 'upcoming',
		text: 'Reageren kan vanaf 19 oktober 2026',
		formUrl: '',
	})
})

test('open says until and links the form', () => {
	assert.deepEqual(commentPeriodLine(period('open'), t), {
		state: 'open',
		text: 'Reageren kan tot en met 16 november 2026',
		formUrl: 'https://gemeente.example/zienswijze',
	})
})

test('closed links no form', () => {
	const line = commentPeriodLine(
		period('closed', { reactionFormUrl: 'https://gemeente.example/zienswijze' }),
		t,
	)
	assert.deepEqual(line, {
		state: 'closed',
		text: 'De reactietermijn is verlopen op 16 november 2026',
		formUrl: '',
	})
})

test('no reported state shows nothing', () => {
	assert.equal(commentPeriodLine({}, t), null)
	assert.equal(commentPeriodLine(null, t), null)
	assert.equal(
		commentPeriodLine({ commentPeriodError: 'unreadable-dates' }, t),
		null,
	)
	assert.equal(commentPeriodLine(period(''), t), null)
	assert.equal(commentPeriodLine(period('pending'), t), null)
	// A state whose date cannot be written says nothing rather than half a line.
	assert.equal(commentPeriodLine(period('open', { endDate: 'soon' }), t), null)
	// A form url that is not a link a visitor can follow is left out.
	assert.equal(
		commentPeriodLine(
			period('open', { reactionFormUrl: 'javascript:alert(1)' }),
			t,
		).formUrl,
		'',
	)
})
