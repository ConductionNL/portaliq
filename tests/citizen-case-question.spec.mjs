#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// citizen-case-question.spec.mjs: the case page answers the open question of
// the case app in its banner (woo-dossier-in-my-cases REQ-WDM-001). Sending
// goes through the row forward; a 2xx reads the sentence and offers no second
// form, a question the handler closed says so and reads the case again.
//
// Usage:
//   node --test tests/citizen-case-question.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	answerQuestion,
	caseQuestions,
	QUESTION_CLOSED,
	QUESTION_FAILED,
	QUESTION_SENT,
	questionComplete,
	questionFields,
	questionOutcome,
} from '../src/shared/caseQuestions.js'
import { mountSfc } from './support/mount-sfc.mjs'
import { t } from './support/page-instance.mjs'
import { renderSfc } from './support/render-sfc.mjs'

const VUE = 'src/site/components/e/CitizenCase.vue'

const ACTION = {
	id: 'beantwoordVraag',
	endpoint: '/index.php/apps/dossiq/api/portal/vragen/antwoord',
	rowField: 'requestId',
	fields: ['antwoord'],
	requiredFields: ['antwoord'],
	fieldConfigs: { antwoord: { label: 'Uw antwoord', maxLength: 4000 } },
	submitLabel: 'Antwoord versturen',
}

const QUESTION = {
	id: 'vraag-1',
	collection: { id: 'vragenAanU', register: 'dossiq', schema: 'aanvullingsverzoek' },
	summary: 'Over welke speeltuinen en welke jaren gaat uw verzoek?',
	hersteltermijn: '2026-11-28',
	action: ACTION,
}

/**
 * The case read the server answers, with the given questions.
 *
 * @param {Array<object>} questions The questions.
 * @return {object} The read.
 */
function caseRead(questions) {
	return {
		case: { id: 'c-87' },
		writableSet: { fields: {}, writable: [], window: { open: false, reason: '' }, documents: { open: false, reason: '' }, status: { label: 'In behandeling' } },
		documents: [],
		withdrawal: { declared: false },
		questions,
	}
}

/**
 * The props of the case screen over one read.
 *
 * @param {object} api The portal api.
 * @param {object} [extra] More props.
 * @return {object} The props.
 */
function props(api, extra = {}) {
	return {
		api,
		t,
		locale: 'nl',
		collection: { id: 'cases', register: 'dossiq', schema: 'zaak' },
		row: { id: 'c-87' },
		taskCollections: [],
		initialTaskReads: [],
		initialData: caseRead([QUESTION]),
		...extra,
	}
}

/**
 * A portal api whose row forward answers `result`, recording each call.
 *
 * @param {object} result The forward's answer.
 * @param {Array<object>} [reads] What each later case read answers.
 * @return {object} The api, with `calls` and `reads`.
 */
function api(result, reads = []) {
	const calls = []
	return {
		calls,
		reads: 0,
		async forwardRowAction(...args) {
			calls.push(args)
			return result
		},
		async fetchCitizenCase() {
			this.reads++
			return reads.shift() || caseRead([])
		},
		async fetchCollection() {
			return []
		},
	}
}

test('questionOutcome: a 2xx is sent, 404 and 409 are closed, anything else failed', () => {
	assert.equal(questionOutcome({ ok: true, status: 200 }), QUESTION_SENT)
	assert.equal(questionOutcome({ ok: false, status: 404 }), QUESTION_CLOSED)
	assert.equal(questionOutcome({ ok: false, status: 409 }), QUESTION_CLOSED)
	assert.equal(questionOutcome({ ok: false, status: 403 }), QUESTION_FAILED)
	assert.equal(questionOutcome({ ok: false, status: 0 }), QUESTION_FAILED)
	assert.equal(questionOutcome(null), QUESTION_FAILED)
})

test('caseQuestions keeps only questions with a row, a collection and an action', () => {
	assert.deepEqual(caseQuestions(caseRead([QUESTION, { id: '', collection: QUESTION.collection, action: ACTION }, { id: 'x' }, null])), [QUESTION])
	assert.deepEqual(caseQuestions(null), [])
	assert.deepEqual(caseQuestions({ questions: 'nope' }), [])
})

test('questionFields and questionComplete read the action the case app declared', () => {
	assert.deepEqual(questionFields(ACTION, t), [{ name: 'antwoord', label: 'Uw antwoord', maxLength: 4000, required: true }])
	assert.deepEqual(questionFields({ fields: ['x'] }, t), [{ name: 'x', label: t('Your reply'), maxLength: null, required: false }])
	assert.equal(questionComplete(ACTION, { antwoord: '  ' }), false)
	assert.equal(questionComplete(ACTION, { antwoord: 'De speeltuinen in Oosthaven' }), true)
})

test('answerQuestion forwards only the declared fields of the proven row', async () => {
	const forward = api({ ok: true, status: 201 })
	const state = await answerQuestion(forward, QUESTION, { antwoord: ' De speeltuinen in Oosthaven, 2023 tot nu ', extra: 'x' })

	assert.equal(state, QUESTION_SENT)
	assert.deepEqual(forward.calls, [[QUESTION.collection, 'vraag-1', 'beantwoordVraag', { antwoord: 'De speeltuinen in Oosthaven, 2023 tot nu' }]])

	const scan = { name: 'scan.pdf' }
	const withFiles = api({ ok: true, status: 200 })
	await answerQuestion(withFiles, { ...QUESTION, action: { ...ACTION, files: { field: 'bijlagen' } } }, { antwoord: 'ja' }, [scan])
	assert.deepEqual(withFiles.calls[0].slice(4), ['', { field: 'bijlagen', files: [scan] }])

	assert.equal(await answerQuestion(null, QUESTION, { antwoord: 'ja' }), QUESTION_FAILED)
})

test('site: the question stands in the warning banner with its term and form', async () => {
	const html = await renderSfc(VUE, props({}))
	assert.match(html, /utrecht-alert--warning pq-case-question/)
	assert.match(html, /Over welke speeltuinen en welke jaren gaat uw verzoek\?/)
	assert.match(html, /data-testid="case-question-term"/)
	assert.match(html, /data-testid="case-question-form"/)
	assert.match(html, /Antwoord versturen/)
	assert.match(html, /maxlength="4000"/)
})

test('site: a sent question reads the sentence and offers no second form', async () => {
	const html = await renderSfc(VUE, props({}, { initialQuestionStates: { 'vraag-1': QUESTION_SENT } }))
	assert.ok(html.includes(t('We have your answer. The handler is looking at it.')))
	assert.doesNotMatch(html, /case-question-form/)
})

test('site: the resident answers the question on the case', async () => {
	const forward = api({ ok: true, status: 200 })
	const page = await mountSfc(VUE, props(forward))

	await page.fire(page.find('case-question-input-antwoord'), 'input', { value: 'De speeltuinen in Oosthaven, 2023 tot nu' })
	await page.fire(page.find('case-question-form'), 'submit')

	assert.equal(forward.calls.length, 1)
	assert.deepEqual(forward.calls[0].slice(0, 4), [QUESTION.collection, 'vraag-1', 'beantwoordVraag', { antwoord: 'De speeltuinen in Oosthaven, 2023 tot nu' }])
	assert.ok(page.text().includes(t('We have your answer. The handler is looking at it.')))
	assert.equal(page.find('case-question-form'), null)
})

test('site: an empty answer is not sent', async () => {
	const forward = api({ ok: true, status: 200 })
	const page = await mountSfc(VUE, props(forward))

	await page.fire(page.find('case-question-form'), 'submit')

	assert.equal(forward.calls.length, 0)
})

test('site: a refused answer says the question was dealt with and reads the case again', async () => {
	const forward = api({ ok: false, status: 404, body: {} }, [caseRead([])])
	const page = await mountSfc(VUE, props(forward))

	await page.fire(page.find('case-question-input-antwoord'), 'input', { value: 'Te laat' })
	await page.fire(page.find('case-question-form'), 'submit')

	assert.equal(forward.reads, 1)
	assert.ok(page.text().includes(t('This question has already been dealt with.')))
	assert.equal(page.find('case-question'), null)
})

test('site: an answer that did not arrive keeps the form and says so', async () => {
	const page = await mountSfc(VUE, props(api({ ok: false, status: 0, body: {} })))

	await page.fire(page.find('case-question-input-antwoord'), 'input', { value: 'Ja' })
	await page.fire(page.find('case-question-form'), 'submit')

	assert.ok(page.text().includes(t('Your answer could not be sent. Please try again.')))
	assert.notEqual(page.find('case-question-form'), null)
})
