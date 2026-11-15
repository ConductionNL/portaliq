#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// embed-theme-saves-steps.spec.mjs: what the embed frame says after sending
// (the reference, the server's refusals, a destination that could not be
// reached, a failure) and what the theme widget says after saving a house
// style (saved, contrast findings, refused, unknown, failed), pinned before
// both were split into named steps.
//
// Usage:
//   node --test tests/embed-theme-saves-steps.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createEmbedTranslator } from '../src/embed/strings.js'
import { loadSfc } from './support/render-sfc.mjs'

/**
 * Browser storage for the packages that read it when they load.
 *
 * @return {object} The storage.
 */
function memory() {
	const items = new Map()
	return {
		getItem: (key) => (items.has(key) ? items.get(key) : null),
		setItem: (key, value) => items.set(key, String(value)),
		removeItem: (key) => items.delete(key),
		clear: () => items.clear(),
		key: (index) => [...items.keys()][index] ?? null,
		get length() {
			return items.size
		},
	}
}
globalThis.window ??= { localStorage: memory(), sessionStorage: memory(), OC: {} }
globalThis.t = (app, text, vars) => (vars ? `t:${text}|${JSON.stringify(vars)}` : `t:${text}`)

const en = createEmbedTranslator('en-GB')
const FRAME = await loadSfc('src/embed/EmbedFrame.vue')

/**
 * An embed frame whose submitter answers, or none so the request is sent.
 *
 * @param {Function|null} answer The submitter's answer.
 * @return {object} The frame's state.
 */
function frame(answer) {
	return {
		...FRAME.data(),
		payload: { route: 'aanvraag' },
		submitUrl: '/submit',
		t: en,
		submitter: answer ? async () => answer() : null,
	}
}

test('the embed frame: the server\'s field refusals, a destination that could not be reached, a silent refusal', async () => {
	const list = frame(() => ({ errors: ['Too late.', 'Wrong.'] }))
	await FRAME.methods.send.call(list, {})
	assert.deepEqual(list.messages, ['Too late.', 'Wrong.'])
	assert.deepEqual(list.fieldErrors, {})
	const later = frame(() => ({ error: 'try_again_later' }))
	await FRAME.methods.send.call(later, {})
	assert.deepEqual(later.messages, [en('Your application could not be sent. Please try again later.')])
	const silent = frame(() => null)
	await FRAME.methods.send.call(silent, {})
	assert.deepEqual(silent.messages, [en('Your application could not be sent.')])
	assert.equal(silent.busy, false)
})

test('the embed frame sends to its submit route without a submitter, with the honeypot', async () => {
	const sent = []
	globalThis.fetch = async (url, init) => {
		sent.push({ url, body: JSON.parse(init.body) })
		return { json: async () => ({ reference: 7 }) }
	}
	try {
		const vm = frame(null)
		vm.messages = ['old']
		vm.fieldErrors = { a: 'old' }
		await FRAME.methods.send.call(vm, { a: 1 }, 'bot')
		assert.equal(vm.reference, '7')
		assert.deepEqual(vm.messages, [])
		assert.deepEqual(vm.fieldErrors, {})
		await FRAME.methods.send.call(frame(null), { a: 2 })
	} finally {
		delete globalThis.fetch
	}
	assert.deepEqual(sent, [
		{ url: '/submit', body: { route: 'aanvraag', answers: { a: 1 }, _hp: 'bot' } },
		{ url: '/submit', body: { route: 'aanvraag', answers: { a: 2 } } },
	])
})

const THEME = await loadSfc('src/widgets/PortalTheme.vue', {
	'@nextcloud/axios': 'export default { get: async () => ({ data: {} }), put: async () => ({ data: {} }) }',
	'@nextcloud/router': 'export const generateUrl = (path) => path',
	'@nextcloud/vue': 'export const NcButton = {}, NcCheckboxRadioSwitch = {}, NcLoadingIcon = {}, NcNoteCard = {}',
})

/**
 * The theme widget's state with an api whose save answers once.
 *
 * @param {object} result What the save answers.
 * @return {object} The widget's state and the saves.
 */
function theme(result) {
	const saves = []
	return {
		...THEME.data.call({}),
		slug: 'school',
		chosen: 'rood',
		current: 'blauw',
		currentResolves: false,
		findings: ['old'],
		notice: 'old',
		noticeType: 'success',
		saves,
		api: { save: async (...args) => { saves.push(args); return result } },
	}
}

test('the theme widget: saved, contrast findings, refused, unknown and failed', async () => {
	const saved = theme({ outcome: 'saved', data: { current: 'groen' } })
	await THEME.methods.save.call(saved, true)
	assert.deepEqual(saved.saves, [['school', 'rood', true]])
	assert.equal(saved.saving, false)
	assert.deepEqual(saved.findings, [])
	assert.equal(saved.noticeType, 'success')
	assert.equal(saved.notice, 't:The house style is saved.')
	assert.equal(saved.current, 'groen')
	assert.equal(saved.currentResolves, true)

	const kept = theme({ outcome: 'saved', data: null })
	await THEME.methods.save.call(kept, false)
	assert.equal(kept.current, 'rood')

	const contrast = theme({ outcome: 'contrast', verdict: { findings: [{ pair: 'a' }] } })
	await THEME.methods.save.call(contrast, false)
	assert.deepEqual(contrast.findings, [{ pair: 'a' }])
	assert.equal(contrast.notice, '')
	assert.equal(contrast.current, 'blauw')
	const noFindings = theme({ outcome: 'contrast', verdict: { findings: 'x' } })
	await THEME.methods.save.call(noFindings, false)
	assert.deepEqual(noFindings.findings, [])

	for (const [result, notice] of [
		[{ outcome: 'refused', refusal: 'no fonts' }, 't:The theme app refused this house style: {reason}|{"reason":"no fonts"}'],
		[{ outcome: 'unknown' }, 't:The theme app no longer offers this house style.'],
		[{ outcome: 'failed' }, 't:The house style could not be saved.'],
	]) {
		const vm = theme(result)
		await THEME.methods.save.call(vm, false)
		assert.equal(vm.noticeType, 'error')
		assert.equal(vm.notice, notice)
		assert.deepEqual(vm.findings, [])
		assert.equal(vm.current, 'blauw')
		assert.equal(vm.saving, false)
	}
})
