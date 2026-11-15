#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// submission-detail.spec.mjs: a submission's page follows the
// portaliq/PtInzending board. The main column holds the submission's facts and
// then, in a card of its own, what the resident sent (the whitelisted copy the
// domain stored, `payloadCopy`). The Forms card and the history stand beside
// it. The copy is shown once: the facts card leaves it out.
//
// Usage:
//   node --test tests/submission-detail.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const SUBMISSION = JSON.parse(read('lib/Settings/portaliq_register.json')).components.schemas.portalSubmission.properties
const NL = JSON.parse(read('l10n/nl.json')).translations
const DETAIL = MANIFEST.pages.find((page) => page.id === 'PortalSubmissionDetail').config
const widget = (id) => DETAIL.widgets.find((w) => w.id === id)

test('what the resident sent has its own card over the stored copy', () => {
	const sent = widget('submission-payload')
	assert.ok(sent, 'no submission-payload widget')
	assert.equal(sent.type, 'data')
	assert.deepEqual(sent.content.include, ['payloadCopy'])
	assert.equal(SUBMISSION.payloadCopy.type, 'object')
	assert.ok(NL[sent.title], `"${sent.title}" is not in the Dutch catalogue`)
})

test('the facts card leaves the copy out, so it is shown once', () => {
	const facts = widget('submission-data').content
	const shown = Array.isArray(facts.include) ? facts.include.includes('payloadCopy') : !(facts.exclude || []).includes('payloadCopy')
	assert.equal(shown, false, 'the facts card shows the copy too')
})

test('the main column reads facts, then what was sent; Forms and history stand beside it', () => {
	const main = DETAIL.layout.map((cell) => cell.widgetId).filter((id) => id !== 'submission-receipt-failed')
	assert.deepEqual(main, ['submission-data', 'submission-payload'])
	const [facts, sent] = DETAIL.layout.filter((cell) => main.includes(cell.widgetId))
	assert.ok(sent.gridY >= facts.gridY + facts.gridHeight, 'the copy card overlaps the facts card')
	assert.deepEqual(DETAIL.sideColumn, ['submission-forms', 'submission-history'])
})
