#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// search-assistant.spec.mjs: the "ask a question" widget
// (search-assistant-from-public-content). The disclosure and the input note
// are there before anything is typed, the call carries no session, an answer
// without a source is never shown, and the widget is offered only while the
// portal turned it on.
//
// Usage:
//   node --test tests/search-assistant.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { askAssistant, assistantUrl } from '../src/site/lib/assistantApi.js'
import { assistantAvailable, setAssistantAvailable } from '../src/site/lib/assistantAvailable.js'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const BLOCK = 'src/site/components/AssistantBlock.vue'

/** A fetch that answers `body` and records what it was asked. */
function fetcher(body, ok = true, status = 200) {
	const calls = []
	const fn = async (url, init) => {
		calls.push({ url, init })
		return { ok, status, json: async () => body }
	}
	fn.calls = calls
	return fn
}

test('the disclosure and the input note are visible before anything is typed', async () => {
	const nl = await renderSfc(BLOCK, { locale: 'nl' })
	assert.match(nl, /Antwoorden komen van een AI-assistent en kunnen onjuist zijn/)
	assert.match(nl, /Typ geen persoonsgegevens, zoals uw burgerservicenummer/)
	const en = await renderSfc(BLOCK, { locale: 'en' })
	assert.match(en, /Answers come from an AI assistant and can be wrong\. Check the page it links to\./)
	assert.match(en, /Do not type personal details such as your citizen service number\./)
	assert.doesNotMatch(en, /assistant-answer|assistant-abstained/)
})

test('the call carries no bearer and no cookies', async () => {
	const send = fetcher({ status: 'answered', answer: 'Een dag later.', sources: [{ title: 'Afval', url: '/afval' }] })
	await askAssistant('/apps/portaliq/api/content', { portal: 'z', question: 'Afval?', locale: 'nl' }, send)

	assert.equal(send.calls[0].url, '/apps/portaliq/api/assistant/ask')
	assert.equal(send.calls[0].init.credentials, 'omit')
	assert.equal(send.calls[0].init.headers.Authorization, undefined)
	assert.doesNotMatch(JSON.stringify(send.calls[0].init.body), /token|subject/i)
	assert.equal(assistantUrl('/api/content'), '/api/assistant/ask')
})

test('an answer without a source is not shown, whatever the server sent', async () => {
	const send = fetcher({ status: 'answered', answer: 'Verzonnen.', sources: [] })
	const reply = await askAssistant('/api/content', { question: 'x' }, send)
	assert.equal(reply.status, 'abstained')
	assert.equal(reply.answer, null)

	const good = await askAssistant('/api/content', { question: 'x' }, fetcher({ status: 'answered', answer: 'Kort.', sources: [{ title: 'A', url: '/a' }], removed: true, conversationId: 'c1' }))
	assert.equal(good.status, 'answered')
	assert.equal(good.removed, true)
	assert.equal(good.conversationId, 'c1')
})

test('a refusal is an error, not an empty answer', async () => {
	await assert.rejects(askAssistant('/api/content', { question: 'x' }, fetcher({}, false, 400)))
})

test('asking shows the reply, keeps the conversation and counts a use without its text', async () => {
	const screen = await loadSfc(BLOCK)
	const tracked = []
	globalThis.window = { portaliqTraffic: { track: (name, params) => tracked.push([name, params]) } }
	try {
		const vm = {
			question: 'Wanneer wordt het afval opgehaald?',
			busy: false,
			failed: false,
			removed: false,
			reply: null,
			conversationId: '',
			portal: 'z',
			locale: 'nl',
			askOverride: async (body) => {
				assert.equal(body.question, 'Wanneer wordt het afval opgehaald?')
				return { status: 'answered', answer: 'Een dag later.', sources: [{ title: 'Afval', url: '/afval' }], removed: true, conversationId: 'c9' }
			},
		}
		await screen.methods.ask.call(vm)

		assert.equal(vm.reply.status, 'answered')
		assert.equal(vm.removed, true)
		assert.equal(vm.conversationId, 'c9')
		assert.deepEqual(tracked, [['assistant_asked', {}]])
		assert.equal(vm.busy, false)

		const failing = { ...vm, askOverride: async () => { throw new Error('down') }, reply: null }
		await screen.methods.ask.call(failing)
		assert.equal(failing.failed, true)
		assert.equal(failing.reply, null)
	} finally {
		delete globalThis.window
	}
})

test('the widget is offered only while the portal turned it on', () => {
	setAssistantAvailable(false)
	assert.equal(assistantAvailable(), false)
	setAssistantAvailable('true')
	assert.equal(assistantAvailable(), false, 'only a real true counts')
	setAssistantAvailable(true)
	assert.equal(assistantAvailable(), true)
	setAssistantAvailable(false)

	const grid = readFileSync('src/site/components/WidgetGrid.vue', 'utf8')
	assert.match(grid, /assistant: AssistantBlock/)
	assert.match(grid, /key === 'assistant' && !assistantAvailable\(\)\) \{\s*return null/)
	assert.match(grid, /\(key\) => key !== 'assistant' \|\| assistantAvailable\(\)/)
	const app = readFileSync('src/site/App.vue', 'utf8')
	assert.match(app, /setAssistantAvailable\(site\?\.assistantEnabled === true\)/)
})
