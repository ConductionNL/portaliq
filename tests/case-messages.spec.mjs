#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// case-messages.spec.mjs: a resident asks a question from their case page and
// finds the answer there (capability-rows-said-and-planned T07, dossiq
// REQ-PORTAL-007, row cmp-act-ask-question-case).
//
// Usage:
//   node --test tests/case-messages.spec.mjs
//
// Dossiq declares `messages: {label: 'Berichten', provider: 'caseMessages'}`
// on "Mijn zaken" and the create action `askAboutCase` with `recordField:
// 'caseId'`. These pin the portal half: the API asks the scoped messages
// route of the open case, a messages block resolves with its ask action, the
// ask button is a cta that carries the open case, and the messages render
// newest first with who wrote them, none dropped.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createPortalApi } from '../src/shared/portalApi.js'
import {
	askBlockOf,
	newestMessagesFirst,
	writerOf,
} from '../src/site/components/mijn/caseMessages.js'
import { mijnTranslator } from '../src/site/components/mijn/rows.js'
import { resolveBlocks } from '../src/site/pages/collections/pageBlocks.js'
import { instance } from './support/page-instance.mjs'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const MESSAGES = [
	{
		id: 'q1',
		subject: 'Vraag over uw zaak',
		body: 'Wanneer hoor ik iets?',
		receivedAt: '2026-11-01T09:00:00+00:00',
		fromResident: true,
		senderName: 'J. Jansen',
		read: true,
	},
	{
		id: 'a1',
		subject: 'Antwoord',
		body: 'U hoort volgende week van ons.',
		receivedAt: '2026-11-03T10:00:00+00:00',
		fromResident: false,
		senderName: 'Gemeente Zuiddrecht',
		read: false,
	},
]

const ZAKEN = {
	id: 'mijnZaken',
	register: 'dossiq',
	schema: 'case',
	messages: { label: 'Berichten', provider: 'caseMessages' },
}

const ASK = {
	id: 'askAboutCase',
	type: 'create',
	recordField: 'caseId',
	label: 'Stel een vraag over deze zaak',
}

const nl = mijnTranslator(null, 'nl')

test('the API asks the scoped messages route of the open case', async () => {
	const calls = []
	globalThis.window = {
		localStorage: { getItem: () => 'token-1', setItem() {}, removeItem() {} },
	}
	globalThis.fetch = async (url, init) => {
		calls.push({ url, init })
		return {
			ok: true,
			json: async () => ({ label: 'Berichten', messages: MESSAGES }),
		}
	}
	const api = createPortalApi({ apiBase: '/apps/portaliq/portal/api' })

	const answer = await api.fetchCaseMessages(ZAKEN, 'case 1')

	assert.equal(calls.length, 1)
	assert.equal(
		calls[0].url,
		'/apps/portaliq/portal/api/collections/dossiq/case/case%201/messages?collection=mijnZaken',
	)
	assert.equal(calls[0].init.headers.Authorization, 'Bearer token-1')
	assert.deepEqual(answer.messages, MESSAGES)
})

test('a refused or failed messages read is null, not a case without messages', async () => {
	globalThis.fetch = async () => ({
		ok: false,
		status: 404,
		json: async () => ({ error: 'not_found' }),
	})
	const api = createPortalApi({ apiBase: '/apps/portaliq/portal/api' })

	assert.equal(await api.fetchCaseMessages(ZAKEN, 'x'), null)
})

test('a messages block resolves on its collection with the ask action it names', () => {
	const contribution = { collections: [ZAKEN], actions: [ASK] }
	const page = {
		blocks: [
			{ type: 'messages', collection: 'mijnZaken', askAction: 'askAboutCase' },
			{ type: 'messages', collection: 'mijnZaken', askAction: 'elsewhere' },
			{ type: 'messages', collection: 'unknown' },
		],
	}

	const items = resolveBlocks(page, contribution)

	assert.deepEqual(
		items.map((item) => [item.kind, item.collection?.id, item.action?.id]),
		[
			['messages', 'mijnZaken', 'askAboutCase'],
			['messages', 'mijnZaken', undefined],
			['none', undefined, undefined],
		],
	)
})

test('the ask button is a cta that carries the open case, worded per language', () => {
	assert.deepEqual(askBlockOf({ askAction: 'askAboutCase' }, nl), {
		type: 'cta',
		action: 'askAboutCase',
		withRecord: true,
		label: 'Stel een vraag over deze zaak',
	})
	assert.equal(
		askBlockOf({ askAction: 'askAboutCase' }, mijnTranslator(null, 'en')).label,
		'Ask a question about this case',
	)
	assert.equal(
		askBlockOf({ askAction: 'askAboutCase', askLabel: 'Vraag stellen' }, nl).label,
		'Vraag stellen',
	)
	assert.equal(askBlockOf({ type: 'messages' }, nl), null)
	assert.equal(askBlockOf(null, nl), null)
})

test('messages run newest first, undated ones last, none dropped or changed', () => {
	const given = [{ id: 'undated', body: 'x' }, ...MESSAGES]
	const copy = structuredClone(given)

	assert.deepEqual(
		newestMessagesFirst(given).map((m) => m.id),
		['a1', 'q1', 'undated'],
	)
	assert.deepEqual(given, copy, 'the list handed in is not reordered')
	assert.deepEqual(newestMessagesFirst(null), [])
	assert.deepEqual(newestMessagesFirst(['text', null, MESSAGES[0]]), [MESSAGES[0]])
})

test('each message says who wrote it', () => {
	assert.equal(writerOf(MESSAGES[0], nl), 'U schreef')
	assert.equal(writerOf(MESSAGES[1], nl), 'Gemeente Zuiddrecht schreef')
	assert.equal(writerOf({ fromResident: false }, nl), 'De organisatie schreef')
})

test('the block renders the messages under the heading, newest first, the answer marked unread', async () => {
	const html = await renderSfc('src/site/components/mijn/MessagesBlock.vue', {
		block: { type: 'messages', collection: 'mijnZaken' },
		collection: ZAKEN,
		record: { id: 'case-1' },
		locale: 'nl',
		initialAnswer: { label: 'Berichten', messages: MESSAGES },
	})

	assert.match(html, /<h2 id="pq-messages-mijnZaken" class="utrecht-heading-3">Berichten<\/h2>/)
	const answerAt = html.indexOf('U hoort volgende week van ons.')
	const questionAt = html.indexOf('Wanneer hoor ik iets?')
	assert.ok(answerAt > -1 && questionAt > -1, 'both messages are shown')
	assert.ok(answerAt < questionAt, 'newest first')
	assert.equal((html.match(/data-testid="case-message-unread"/g) || []).length, 1)
	assert.match(html, /datetime="2026-11-03T10:00:00\+00:00"/)
})

test('a case without messages says so; a failed read says it failed', async () => {
	const empty = await renderSfc('src/site/components/mijn/MessagesBlock.vue', {
		block: { type: 'messages', collection: 'mijnZaken' },
		collection: ZAKEN,
		record: { id: 'case-1' },
		locale: 'nl',
		initialAnswer: { label: 'Berichten', messages: [] },
	})
	assert.match(empty, /Er zijn nog geen berichten over deze zaak\./)

	const MessagesBlock = await loadSfc('src/site/components/mijn/MessagesBlock.vue')
	const block = instance(MessagesBlock, {
		block: { type: 'messages', collection: 'mijnZaken' },
		collection: ZAKEN,
		record: { id: 'case-1' },
		api: { fetchCaseMessages: async () => null },
	})
	await block.load()
	assert.equal(block.failed, true)
	assert.equal(block.answer, null)
})
