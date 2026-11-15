#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// form-binding-preview-edges.spec.mjs: the edges of the "Which form?" and
// "Insert form" row actions (an empty answer, findings with only a code or
// nothing, missing names, unknown reasons, a row that is no object), pinned
// before both handlers shared their request step.
//
// Usage:
//   node --test tests/form-binding-preview-edges.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createFormBindingEmbed } from '../src/lib/formBindingEmbed.js'
import { createFormBindingPreview, noFormSentence } from '../src/lib/formBindingPreview.js'

const echo = (text, vars) => `${text}|${JSON.stringify(vars ?? null)}`

/**
 * The preview handler over recording collaborators.
 *
 * @param {unknown} response What `post` resolves with.
 * @return {{previewFormBinding: Function, calls: Array}}
 */
function preview(response) {
	const calls = []
	const record = (kind) => (text) => calls.push([kind, text])
	const { previewFormBinding } = createFormBindingPreview({
		post: async (url, body) => (calls.push(['post', url, body]), response),
		generateUrl: (path) => `nc:${path}`,
		notify: record('notice'),
		notifyWarning: record('warning'),
		notifyError: record('error'),
		translate: echo,
	})
	return { previewFormBinding, calls }
}

test('the preview over odd answers', async () => {
	const cases = [
		[null, 'warning'],
		[{}, 'warning'],
		[{ data: null }, 'warning'],
		[{ data: { state: 'resolved' } }, 'notice'],
		[{ data: { state: 'resolved', findings: 'x' } }, 'notice'],
		[{ data: { state: 'resolved', formName: 'F', findings: [{ code: 'c1' }, null, { message: '' }, { message: 'M' }] } }, 'warning'],
		[{ data: { state: 'resolved', findings: [null] } }, 'warning'],
		[{ data: { state: 'external' } }, 'notice'],
		[{ data: { state: 'none', reason: 'toString' } }, 'warning'],
	]
	for (const [response, kind] of cases) {
		const { previewFormBinding, calls } = preview(response)
		assert.equal(await previewFormBinding({ item: { id: 1, uuid: 2, '@self': {}, route: '/r', formName: 'F' } }), true)
		assert.deepEqual(calls[0], ['post', 'nc:/apps/portaliq/api/form-bindings/preview', { binding: { route: '/r', formName: 'F' } }])
		assert.equal(calls.length, 2)
		assert.equal(calls[1][0], kind, JSON.stringify(response))
	}
	const { previewFormBinding, calls } = preview({ data: { state: 'resolved', formName: 'F', findings: [{ code: 'c1' }, null, { message: '' }, { message: 'M' }] } })
	await previewFormBinding({ item: null })
	assert.deepEqual(calls, [
		['post', 'nc:/apps/portaliq/api/form-bindings/preview', { binding: {} }],
		['warning', 'This entry opens "{form}", but it cannot be published yet: {findings}|{"form":"F","findings":"c1 M"}'],
	])
})

test('the sentence for every reason a binding opens no form', () => {
	for (const reason of ['external_without_address', 'hidden_case_type', 'unsupported_condition', 'named_form_not_published', 'other', undefined]) {
		assert.match(noFormSentence({ reason, askedFor: 'Aanvraag' }, echo), /\|/)
	}
	assert.equal(
		noFormSentence({ reason: 'named_form_not_published' }, echo),
		'This entry opens no form today. It asks for "{form}", and no form of that name is published to its audience.|{"form":""}',
	)
	assert.equal(
		noFormSentence(null, echo),
		'This entry opens no form today. No published form matches its case type and audience.|null',
	)
	assert.equal(noFormSentence({ reason: 'hidden_case_type' }, echo).startsWith('This entry opens no form: this portal'), true)
})

test('the embed over odd answers and rows', async () => {
	const calls = []
	const { openFormBindingEmbed } = createFormBindingEmbed({
		post: async (url, body) => (calls.push(['post', url, body]), null),
		generateUrl: (path) => `nc:${path}`,
		openDialog: (props) => calls.push(['dialog', props]),
		notifyError: (text) => calls.push(['error', text]),
		translate: echo,
	})
	assert.equal(await openFormBindingEmbed({ item: undefined }), true)
	assert.deepEqual(calls, [
		['post', 'nc:/apps/portaliq/api/form-bindings/embed-snippet', { binding: {} }],
		['dialog', { formName: '', route: '', description: {} }],
	])
})
