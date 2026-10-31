#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// submission-detail-board.spec.mjs: a submission's page follows the portaliq/
// PtInzending board. It is titled by the action the resident submitted, a
// failed receipt shows an error notice above the facts, the facts card shows
// the board's seven facts, and the history beside it reads "Bewijslog".
//
// Usage:
//   node --test tests/submission-detail-board.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const SUBMISSION = JSON.parse(read('lib/Settings/portaliq_register.json')).components.schemas.portalSubmission.properties
const NL = JSON.parse(read('l10n/nl.json')).translations
const DETAIL = MANIFEST.pages.find((page) => page.id === 'PortalSubmissionDetail').config
const widget = (id) => DETAIL.widgets.find((w) => w.id === id)

test('the page is titled by the submitted action', () => {
	assert.equal(DETAIL.titleField, 'actionId')
})

test('a failed receipt shows an error notice above the facts', () => {
	const notice = widget('submission-receipt-failed')
	assert.equal(notice.type, 'banner')
	assert.deepEqual(notice.visibleWhen, { field: 'deliveryStatus', op: 'eq', value: 'failed' })
	assert.ok(SUBMISSION.deliveryStatus)
	assert.equal(notice.content.variant, 'error')
	assert.equal(NL[notice.content.lead], 'De ontvangstbevestiging kwam niet aan.')
	assert.ok(NL[notice.content.text])
	const cell = DETAIL.layout.find((entry) => entry.widgetId === notice.id)
	const facts = DETAIL.layout.find((entry) => entry.widgetId === 'submission-data')
	assert.equal(cell.gridY, 0)
	assert.ok(facts.gridY >= cell.gridY + cell.gridHeight)
})

test('the facts card shows the board\'s facts and the history reads Bewijslog', () => {
	const facts = widget('submission-data')
	assert.deepEqual(facts.content.include, ['actionId', 'appId', 'submittedAt', 'deliveryStatus', 'subjectRef', 'organisation', 'receiptMessageRef'])
	for (const key of facts.content.include) {
		assert.ok(SUBMISSION[key], `the submission has no "${key}"`)
	}
	assert.equal(NL[facts.title], 'Gegevens')
	assert.equal(NL[widget('submission-history').title], 'Bewijslog')
})
