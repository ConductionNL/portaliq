#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// inbox-reply.spec.mjs: a resident answers a message from the organisation in
// the inbox, with files (inbox-reply-with-attachments REQ-IRA-001 to 003).
// The reply control shows only on a message whose collection declares one, the
// form asks what the action asks (less what the server carries over), and a
// file that fails after the text arrived is named while the reply stays.
//
// Usage:
//   node --test tests/inbox-reply.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { createPortalApi } from '../src/shared/portalApi.js'
import { replyFileFields, replyOf, replyQuestions, replyStart, sendReply, sentMessage } from '../src/site/pages/inbox/reply.js'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'

const REPLY = {
	action: {
		id: 'replyToMessage',
		label: 'Antwoorden',
		register: 'dossiq',
		schema: 'portaalBericht',
		fields: ['subject', 'content', 'attachments', 'caseId'],
		fieldConfigs: {
			subject: { label: 'Onderwerp' },
			content: { label: 'Uw antwoord' },
			attachments: { type: 'file', multiple: true, accept: 'application/pdf,image/*', maxSizeMb: 10, label: 'Bijlagen' },
		},
	},
	carried: ['caseId'],
	subjectFrom: 'subject',
}
const MESSAGE = {
	id: 'm1',
	subject: 'Besluit op uw verzoek',
	_source: { appId: 'dossiq', register: 'dossiq', schema: 'portaalBericht', collection: 'berichten', reply: REPLY },
}

test('only a message whose collection declares a reply can be answered', () => {
	assert.equal(replyOf(MESSAGE), REPLY)
	assert.equal(replyOf({ id: 'own', _source: { appId: 'portaliq' } }), null)
	assert.equal(replyOf({ _source: { reply: { action: {} } } }), null)
	assert.equal(replyOf(null), null)
})

test('the form asks the text and subject, not the case the server carries over, and offers the file field', () => {
	assert.deepEqual(replyQuestions(REPLY).map((q) => [q.name, q.label, q.long]), [
		['subject', 'Onderwerp', false],
		['content', 'Uw antwoord', true],
	])
	assert.deepEqual(replyFileFields(REPLY), [
		{ name: 'attachments', label: 'Bijlagen', multiple: true, accept: 'application/pdf,image/*', maxSizeMb: 10 },
	])
})

test('the subject starts as "Re:" and the message subject, once', () => {
	assert.deepEqual(replyStart(MESSAGE, REPLY), { subject: 'Re: Besluit op uw verzoek', content: '' })
	assert.equal(replyStart({ subject: 'Re: Besluit' }, REPLY).subject, 'Re: Besluit')
	assert.equal(replyStart({}, REPLY).subject, '')
	assert.equal(replyStart(MESSAGE, { ...REPLY, subjectFrom: undefined }).subject, '')
})

/** An api that records what is sent, and lets some uploads fail. */
function fakeApi({ failUpload = [] } = {}) {
	const log = { replies: [], uploads: [] }
	return {
		log,
		replyToMessage: async (message, body) => {
			log.replies.push({ message: message.id, body })
			return { ok: true, status: 200, object: { id: 'r1' } }
		},
		uploadFieldFile: async (action, id, field, file) => {
			log.uploads.push({ action: action.id, id, field, file: file.name })
			return failUpload.includes(file.name) ? { ok: false, status: 400, error: 'type' } : { ok: true, file: {}, value: [] }
		},
	}
}

test('the reply is sent without its files, then each file goes into the new reply', async () => {
	const api = fakeApi()
	const result = await sendReply(api, MESSAGE, REPLY, { subject: 'Re: Besluit', content: 'Dank u.' }, {
		attachments: [{ name: 'foto.jpg' }, { name: 'brief.pdf' }],
	})

	assert.deepEqual(result, { ok: true, errors: {}, failed: [] })
	assert.deepEqual(api.log.replies, [{ message: 'm1', body: { subject: 'Re: Besluit', content: 'Dank u.' } }])
	assert.deepEqual(api.log.uploads.map((u) => [u.id, u.field, u.file]), [['r1', 'attachments', 'foto.jpg'], ['r1', 'attachments', 'brief.pdf']])
})

test('a file that fails after the text arrived is named, and the reply stays', async () => {
	const api = fakeApi({ failUpload: ['brief.pdf'] })
	const result = await sendReply(api, MESSAGE, REPLY, { subject: 'x', content: 'y' }, { attachments: [{ name: 'foto.jpg' }, { name: 'brief.pdf' }] })

	assert.equal(result.ok, true)
	assert.deepEqual(result.failed, ['brief.pdf'])
	const words = { sent: 'Uw antwoord is verstuurd.', partial: 'Uw antwoord is verstuurd, maar {name} kon niet worden toegevoegd. Voeg het opnieuw toe bij de zaak.' }
	assert.equal(sentMessage([], words), 'Uw antwoord is verstuurd.')
	assert.equal(sentMessage(result.failed, words), 'Uw antwoord is verstuurd, maar brief.pdf kon niet worden toegevoegd. Voeg het opnieuw toe bij de zaak.')
})

test('a refused reply uploads nothing and returns the server\'s errors', async () => {
	const api = fakeApi()
	api.replyToMessage = async () => ({ ok: false, status: 400, object: null, error: '', errors: { content: 'Dit veld is verplicht.' } })

	const result = await sendReply(api, MESSAGE, REPLY, { subject: 'x', content: '' }, { attachments: [{ name: 'a.pdf' }] })

	assert.equal(result.ok, false)
	assert.deepEqual(result.errors, { content: 'Dit veld is verplicht.' })
	assert.deepEqual(api.log.uploads, [])
})

test('the api posts to the message\'s reply route with its collection', async () => {
	const asked = []
	globalThis.fetch = async (url, init) => {
		asked.push({ url, method: init.method, body: JSON.parse(init.body), auth: init.headers.Authorization })
		return { ok: true, status: 200, json: async () => ({ object: { id: 'r1' }, action: 'replyToMessage' }) }
	}
	try {
		const api = createPortalApi({ apiBase: '/portal/api' }, { getToken: () => 'tok', setToken: () => {} })
		const result = await api.replyToMessage(MESSAGE, { content: 'Dank u.' })
		assert.equal(result.ok, true)
		assert.deepEqual(result.object, { id: 'r1' })
		assert.equal((await api.replyToMessage({ _source: {} }, {})).ok, false)
	} finally {
		delete globalThis.fetch
	}
	assert.equal(asked.length, 1)
	assert.equal(asked[0].url, '/portal/api/inbox/dossiq/portaalBericht/m1/reply?collection=berichten')
	assert.equal(asked[0].method, 'POST')
	assert.equal(asked[0].auth, 'Bearer tok')
})

test('the reply control renders closed, and sending closes it with the confirmation', async () => {
	const closed = await renderSfc('src/site/components/inbox/InboxReply.vue', { message: MESSAGE, reply: REPLY, api: fakeApi(), locale: 'en' })
	assert.match(closed, /inbox-reply-open/)
	assert.match(closed, />\s*Reply\s*</)
	assert.doesNotMatch(closed, /inbox-reply-send/)

	const component = await loadSfc('src/site/components/inbox/InboxReply.vue')
	const api = fakeApi({ failUpload: ['brief.pdf'] })
	const vm = {
		message: MESSAGE,
		reply: REPLY,
		api,
		locale: 'en',
		open: true,
		sending: false,
		failed: false,
		notice: '',
		errors: {},
		values: { subject: 'x', content: 'y' },
		files: { attachments: [{ name: 'brief.pdf' }] },
	}
	vm.words = component.computed.words.call(vm)
	await component.methods.send.call(vm)
	assert.equal(vm.open, false)
	assert.equal(vm.notice, 'Your reply has been sent, but brief.pdf could not be added. Add it again from the case.')
	assert.equal(vm.sending, false)
})

test('the inbox page offers the reply under a message', () => {
	const page = readFileSync('src/site/pages/inbox/InboxPage.vue', 'utf8')
	assert.match(page, /<InboxReply/)
	assert.match(page, /replyOf\(message\) && api/)
})
