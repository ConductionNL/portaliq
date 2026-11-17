#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// woo-dossier-in-my-cases.spec.mjs: the case page shows the sentence the case
// app wrote about the term (REQ-WDM-002) and links to where the result is
// public (REQ-WDM-003), as text and never as markup.
//
// Usage:
//   node --test tests/woo-dossier-in-my-cases.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	decisionDateRows,
	resultLinkOf,
	termNoteRows,
} from '../src/shared/casePage.js'
import { t } from './support/page-instance.mjs'
import { renderSfc } from './support/render-sfc.mjs'

const NOTE = 'De termijn staat stil sinds 6 oktober 2026. Hij loopt weer zodra wij uw antwoord hebben.'

/**
 * The resident's case screen over one case.
 *
 * @param {object} caseRow The case.
 * @return {Promise<string>} The rendered html.
 */
function render(caseRow) {
	return renderSfc('src/site/components/e/CitizenCase.vue', {
		api: {},
		t,
		locale: 'nl',
		collection: { id: 'cases', register: 'dossiq', schema: 'zaak' },
		row: { id: 'c-87' },
		taskCollections: [],
		initialTaskReads: [],
		initialData: {
			case: { id: 'c-87', ...caseRow },
			writableSet: { fields: {}, writable: [], window: { open: false, reason: '' }, documents: { open: false, reason: '' }, status: { label: 'In behandeling' } },
			documents: [],
			withdrawal: { declared: false },
		},
	})
}

test('termNoteRows: the sentence stands in for a missing date and under a set one', () => {
	assert.deepEqual(termNoteRows({ termNote: `  ${NOTE} ` }, t, false), [{ key: 'termNote', label: t('Ready by'), value: NOTE }])
	assert.deepEqual(termNoteRows({ termNote: NOTE }, t, true), [{ key: 'termNote', label: '', value: NOTE }])
	assert.deepEqual(termNoteRows({ termNote: '  ' }, t, false), [])
	assert.deepEqual(termNoteRows({ termNote: 7 }, t, false), [])
	assert.deepEqual(termNoteRows(null, t, false), [])
	assert.equal(termNoteRows({ termNote: 'x'.repeat(250) }, t, false)[0].value.length, 200)
})

test('decisionDateRows puts the sentence after the decision dates', () => {
	assert.deepEqual(
		decisionDateRows({ legalDecisionDate: '2026-11-01', termNote: NOTE }, t, 'nl').map((row) => [row.key, row.label]),
		[['legalDecisionDate', t('Ready by')], ['termNote', '']],
	)
	assert.deepEqual(
		decisionDateRows({ plannedDecisionDate: '2026-10-20', termNote: NOTE }, t, 'nl').map((row) => [row.key, row.label]),
		[['plannedDecisionDate', t('Expected decision')], ['termNote', t('Ready by')]],
	)
	assert.deepEqual(decisionDateRows({}, t, 'nl'), [])
})

test('site: a paused term shows the sentence where the date would be', async () => {
	const html = await render({ termNote: NOTE })
	assert.match(html, /data-testid="case-date-termNote"/)
	assert.match(html, /De termijn staat stil sinds 6 oktober 2026\./)
	assert.ok(html.includes(t('Ready by')))
})

test('site: markup in the sentence stays text', async () => {
	const html = await render({ termNote: '<b>x</b>' })
	assert.match(html, /&lt;b&gt;x&lt;\/b&gt;/)
	assert.doesNotMatch(html, /<b>x<\/b>/)
})

test('resultLinkOf: an https link with its label, the default words without one, nothing else', () => {
	assert.deepEqual(
		resultLinkOf({ resultLink: { label: 'Bekijk wat openbaar is gemaakt', url: 'https://zuiddrecht.nl/woo/publicaties/2026-0087' } }, t),
		{ label: 'Bekijk wat openbaar is gemaakt', url: 'https://zuiddrecht.nl/woo/publicaties/2026-0087' },
	)
	assert.deepEqual(resultLinkOf({ resultLink: { label: ' ', url: 'https://zuiddrecht.nl/x' } }, t), { label: t('See the result'), url: 'https://zuiddrecht.nl/x' })
	assert.equal(resultLinkOf({ resultLink: { url: 'http://example.com/x' } }, t), null)
	assert.equal(resultLinkOf({ resultLink: { url: 'javascript:alert(1)' } }, t), null)
	assert.equal(resultLinkOf({ resultLink: 'https://zuiddrecht.nl/x' }, t), null)
	assert.equal(resultLinkOf({}, t), null)
})

test('site: the case links to the publication, and shows no link without one', async () => {
	const html = await render({ resultLink: { label: 'Bekijk wat openbaar is gemaakt', url: 'https://zuiddrecht.nl/woo/publicaties/2026-0087' } })
	assert.match(html, /data-testid="case-result-link"/)
	assert.match(html, /href="https:\/\/zuiddrecht\.nl\/woo\/publicaties\/2026-0087"/)
	assert.match(html, /Bekijk wat openbaar is gemaakt/)

	const none = await render({})
	assert.doesNotMatch(none, /case-result-link/)
})

test('site: a link without words reads the default words', async () => {
	const html = await render({ resultLink: { url: 'https://zuiddrecht.nl/x' } })
	assert.ok(html.includes(t('See the result')))
})

test('the default link words are in the Dutch strings of the case screen', async () => {
	const { readFileSync } = await import('node:fs')
	const strings = readFileSync(new URL('../src/site/pages/e/strings.js', import.meta.url), 'utf8')
	assert.match(strings, /'See the result': 'Bekijk het resultaat'/)
})
