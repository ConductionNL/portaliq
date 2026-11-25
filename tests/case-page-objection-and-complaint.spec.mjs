#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// case-page-objection-and-complaint.spec.mjs: what the case screen draws from
// the server's `caseActions`, the `onCase` value a submit carries, and the
// receipt and refusal lines.
//
// Usage:
//   node --test tests/case-page-objection-and-complaint.spec.mjs
//
// @spec openspec/changes/archive/2026-11-24-case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-case-page-offers-the-actions-the-server-lists-req-coc-002

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	caseActionsView,
	closedRefusal,
	onCaseTarget,
	receiptLines,
} from '../src/site/components/e/caseActions.js'

function t(key, vars = {}) {
	return key.replace(/\{(\w+)\}/g, (_, name) => vars[name] ?? '')
}

test('an open action is a button with its form, a closed one its sentence', () => {
	const form = { id: 'createKlacht', fields: ['toelichting'] }
	const view = caseActionsView({
		caseActions: [
			{
				app: 'dossiq',
				action: 'createBezwaar',
				label: 'Bezwaar maken',
				open: false,
				closedText: 'De termijn voor bezwaar is voorbij.',
			},
			{
				app: 'dossiq',
				action: 'createKlacht',
				label: 'Klacht indienen',
				open: true,
				form,
			},
		],
	})

	assert.deepEqual(view, [
		{
			key: 'dossiq:createBezwaar',
			label: 'Bezwaar maken',
			open: false,
			closedText: 'De termijn voor bezwaar is voorbij.',
			form: null,
		},
		{
			key: 'dossiq:createKlacht',
			label: 'Klacht indienen',
			open: true,
			closedText: '',
			form,
		},
	])
})

test('a case without case actions draws nothing', () => {
	assert.deepEqual(caseActionsView({}), [])
	assert.deepEqual(caseActionsView(null), [])
	assert.deepEqual(
		caseActionsView({ caseActions: [{ label: 'no action id' }] }),
		[],
	)
})

test('an open entry without a form is not drawn as a button', () => {
	const [entry] = caseActionsView({
		caseActions: [{ app: 'dossiq', action: 'createKlacht', open: true }],
	})
	assert.equal(entry.open, false)
})

test('the onCase value names the case', () => {
	assert.equal(
		onCaseTarget({ register: 'zaken', schema: 'zaak' }, 'zaak-1'),
		'zaken/zaak/zaak-1',
	)
	assert.equal(onCaseTarget({ register: 'zaken' }, 'zaak-1'), '')
	assert.equal(onCaseTarget({ register: 'zaken', schema: 'zaak' }, ''), '')
})

test('the receipt gives the reference and where the new case is', () => {
	assert.deepEqual(receiptLines(t, { reference: 'BZ-2026-0007' }), [
		'We have received it. Reference BZ-2026-0007.',
		'You will find it as a case of its own in My cases.',
	])
	assert.equal(receiptLines(t, {})[0], 'We have received it.')
})

test('a closed window shows the server sentence, any other refusal nothing', () => {
	assert.equal(
		closedRefusal({
			status: 409,
			error: 'case_action_closed',
			message: 'De termijn voor bezwaar is voorbij.',
		}),
		'De termijn voor bezwaar is voorbij.',
	)
	assert.equal(closedRefusal({ status: 403, error: 'cross_ref_refused' }), '')
})
