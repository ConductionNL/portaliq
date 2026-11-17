#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// case-outcome.spec.mjs: an ended case shows its outcome with the
// organisation's explanation above the status steps (case-outcome-on-the-case-
// page, REQ-COC-001). A label and an explanation, a label alone, a uuid in the
// label field, a running case and a collection without a declaration.
//
// Usage:
//   node --test tests/case-outcome.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { caseOutcome, outcomeText } from '../src/shared/caseOutcome.js'
import { t } from './support/page-instance.mjs'
import { renderSfc } from './support/render-sfc.mjs'

const SITE_CASE = 'src/site/components/e/CitizenCase.vue'
const DECLARATION = { labelField: 'resultPublicLabel', descriptionField: 'resultPublicDescription' }
const GRANTED = {
	id: 'c-7',
	resultPublicLabel: 'Vergunning verleend',
	resultPublicDescription: 'Uw aanvraag voldoet aan het bestemmingsplan.',
}

function render({ caseRow = GRANTED, outcome = DECLARATION, ended = true } = {}) {
	return renderSfc(SITE_CASE, {
		api: {},
		t,
		locale: 'nl',
		collection: { id: 'cases', register: 'dossiq', schema: 'zaak' },
		row: { id: caseRow.id },
		initialTaskReads: [],
		initialData: {
			case: caseRow,
			writableSet: {
				fields: {},
				writable: [],
				window: { open: false, reason: '' },
				documents: { open: false, reason: '' },
				status: { label: 'Afgerond' },
				ended,
			},
			documents: [],
			withdrawal: { declared: false },
			outcome,
		},
	})
}

test('an ended case shows the label and the explanation above the status steps', async () => {
	const html = await render()
	assert.match(html, /data-testid="case-outcome"/)
	assert.match(html, /Outcome/)
	assert.match(html, /<strong>Vergunning verleend<\/strong>/)
	assert.match(html, /data-testid="case-outcome-description"[^>]*>\s*Uw aanvraag voldoet aan het bestemmingsplan\./)
	assert.ok(html.indexOf('data-testid="case-outcome"') < html.indexOf('data-testid="case-status"'))
})

test('a label without an explanation shows the label alone', async () => {
	const html = await render({ caseRow: { id: 'c-8', resultPublicLabel: 'Aanvraag afgewezen' } })
	assert.match(html, /<strong>Aanvraag afgewezen<\/strong>/)
	assert.doesNotMatch(html, /case-outcome-description/)
	assert.deepEqual(caseOutcome({ resultPublicLabel: 'Afgewezen' }, { labelField: 'resultPublicLabel' }, true), {
		label: 'Afgewezen',
		description: '',
	})
})

test('a reference is never an outcome: a uuid, a URL or a $ref object', async () => {
	const html = await render({ caseRow: { id: 'c-9', resultPublicLabel: '3f1c2a9e-8b7d-4c1e-9a2b-5d6e7f8a9b0c' } })
	assert.doesNotMatch(html, /case-outcome/)
	assert.equal(caseOutcome({ resultPublicLabel: 'https://zaken.example/resultaten/1' }, DECLARATION, true), null)
	assert.equal(caseOutcome({ resultPublicLabel: { $ref: '#/resultaat/1' } }, DECLARATION, true), null)
	assert.equal(outcomeText('www.example.nl/x'), '')
	assert.equal(outcomeText('  Verleend  '), 'Verleend')
	// A reference in the explanation drops the explanation, not the label.
	assert.deepEqual(
		caseOutcome({ resultPublicLabel: 'Verleend', resultPublicDescription: '3f1c2a9e-8b7d-4c1e-9a2b-5d6e7f8a9b0c' }, DECLARATION, true),
		{ label: 'Verleend', description: '' },
	)
})

test('a running case has no outcome yet', async () => {
	const html = await render({ ended: false })
	assert.doesNotMatch(html, /case-outcome/)
	assert.match(html, /data-testid="case-status"/)
})

test('a collection without an outcome declaration shows none', async () => {
	const html = await render({ outcome: null })
	assert.doesNotMatch(html, /case-outcome/)
	assert.equal(caseOutcome(GRANTED, {}, true), null)
	assert.equal(caseOutcome(GRANTED, undefined, true), null)
})
