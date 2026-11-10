#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// assistant-and-banner-steps.spec.mjs: when the assistant block asks, what it
// sends and keeps, and how the install dialog keeps the keyboard inside it,
// pinned before both were split into named steps.
//
// Usage:
//   node --test tests/assistant-and-banner-steps.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { loadSfc } from './support/render-sfc.mjs'

const BLOCK = await loadSfc('src/site/components/AssistantBlock.vue')
const BANNER = await loadSfc('src/site/components/f/InstallBanner.vue')

/**
 * The assistant block's state.
 *
 * @param {object} state What differs.
 * @return {object} The state.
 */
function block(state) {
	return {
		question: ' Wanneer? ',
		busy: false,
		failed: true,
		removed: false,
		reply: 'old',
		conversationId: 'c0',
		portal: 'z',
		locale: 'en-GB',
		askOverride: null,
		...state,
	}
}

test('the assistant asks nothing without a question or while busy', async () => {
	let asked = 0
	for (const state of [{ question: '   ' }, { question: null }, { busy: true }]) {
		const vm = block({ ...state, askOverride: async () => { asked++ } })
		await BLOCK.methods.ask.call(vm)
		assert.equal(vm.reply, 'old')
	}
	assert.equal(asked, 0)
})

test('the assistant sends the trimmed question, keeps the conversation, and counts only with a client', async () => {
	const sent = []
	const vm = block({ askOverride: async (body) => { sent.push(body); return { status: 'abstained', removed: 'yes' } } })
	globalThis.window = {}
	try {
		await BLOCK.methods.ask.call(vm)
	} finally {
		delete globalThis.window
	}
	assert.deepEqual(sent, [{ portal: 'z', question: 'Wanneer?', locale: 'en', conversationId: 'c0' }])
	assert.equal(vm.conversationId, 'c0', 'a reply without one keeps the conversation')
	assert.equal(vm.removed, false)
	assert.equal(vm.failed, false)
	assert.equal(vm.busy, false)
	assert.deepEqual(vm.reply, { status: 'abstained', removed: 'yes' })

	const counted = []
	globalThis.window = { portaliqTraffic: { track: 'not a function' } }
	try {
		await BLOCK.methods.ask.call(block({ askOverride: async () => ({}) }))
		globalThis.window.portaliqTraffic = { track: (name) => counted.push(name) }
		await BLOCK.methods.ask.call(block({ askOverride: async () => ({}) }))
	} finally {
		delete globalThis.window
	}
	assert.deepEqual(counted, ['assistant_asked'])
})

test('without an override the assistant asks the content API', async () => {
	const calls = []
	globalThis.window = {
		fetch: async (url, init) => {
			calls.push({ url, body: JSON.parse(init.body) })
			return { ok: true, json: async () => ({ status: 'answered', answer: 'Ja', sources: [{ title: 'A', url: '/a' }], conversationId: 'c1' }) }
		},
	}
	const vm = block({ locale: 'nl' })
	try {
		await BLOCK.methods.ask.call(vm)
	} finally {
		delete globalThis.window
	}
	assert.equal(calls.length, 1)
	assert.match(calls[0].url, /assistant/)
	assert.deepEqual(calls[0].body, { portal: 'z', question: 'Wanneer?', locale: 'nl', conversationId: 'c0' })
	assert.equal(vm.conversationId, 'c1')
	assert.equal(vm.reply.status, 'answered')
})

test('the install dialog: other keys pass, Tab inside moves freely, Shift+Tab from the dialog wraps, no refs no trap', () => {
	const focused = []
	let prevented = 0
	const first = { focus: () => focused.push('first') }
	const last = { focus: () => focused.push('last') }
	const middle = {}
	const dialog = {}
	const vm = { $refs: { first, last, dialog }, win: { document: { activeElement: middle } }, dismiss: () => focused.push('dismiss') }
	const key = (name, shiftKey = false) => ({ key: name, shiftKey, preventDefault: () => prevented++ })

	BANNER.methods.onKey.call(vm, key('Enter'))
	BANNER.methods.onKey.call(vm, key('Tab'))
	BANNER.methods.onKey.call(vm, key('Tab', true))
	assert.deepEqual(focused, [])
	assert.equal(prevented, 0)
	vm.win.document.activeElement = dialog
	BANNER.methods.onKey.call(vm, key('Tab', true))
	assert.deepEqual(focused, ['last'])
	BANNER.methods.onKey.call(vm, key('Tab', false))
	assert.deepEqual(focused, ['last'], 'Tab from the dialog itself is the browser\'s')
	vm.win.document.activeElement = last
	BANNER.methods.onKey.call(vm, key('Tab', true))
	assert.equal(prevented, 1)
	BANNER.methods.onKey.call({ $refs: { first }, win: null }, key('Tab'))
	BANNER.methods.onKey.call({ $refs: { first, last }, win: null }, key('Tab'))
	assert.deepEqual(focused, ['last'])
	BANNER.methods.onKey.call(vm, key('Escape'))
	assert.deepEqual(focused, ['last', 'dismiss'])
	assert.equal(prevented, 2)
})
