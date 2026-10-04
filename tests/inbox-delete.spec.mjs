#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// inbox-delete.spec.mjs: a resident deletes their own inbox messages, one
// or several at once, after a confirmation on the page itself
// (inbox-delete-own-messages). Only rows the server marks deletable offer
// it, and a message the server refuses stays.
//
// Usage:
//   node --test tests/inbox-delete.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { canDelete, withoutMessages } from '../src/site/pages/inbox/inbox.js'
import { withStrings } from '../src/site/pages/inbox/translate.js'
import { instance, inState, t } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const InboxPage = await loadSfc('src/site/pages/inbox/InboxPage.vue')

function own(id, subject, read = false) {
	return {
		id,
		subject,
		read,
		receivedAt: '2026-10-03T09:00:00+02:00',
		_source: {
			appId: 'portaliq',
			register: 'portaliq',
			schema: 'portalMessage',
			collection: 'portalMessages',
			deletable: true,
		},
	}
}

const MESSAGES = [
	own('m1', 'Ziekmelding ontvangen'),
	own('m2', 'Ziekmelding ontvangen', true),
	{
		id: 'd1',
		subject: 'Besluit',
		read: false,
		_source: {
			appId: 'dossiq',
			register: 'zaken',
			schema: 'bericht',
			collection: 'berichten',
		},
	},
]

/**
 * An api that answers each delete as told and records it.
 *
 * @param {Set<string>} refuse Ids the server refuses.
 * @return {object}
 */
function fakeApi(refuse = new Set()) {
	const calls = []
	return {
		calls,
		async fetchInbox() {
			return MESSAGES
		},
		async deleteMessage(message) {
			calls.push(message.id)
			return refuse.has(message.id) ? { ok: false, status: 404 } : { ok: true }
		},
	}
}

test('only a row the server marks deletable can be deleted', () => {
	assert.deepEqual(MESSAGES.map(canDelete), [true, true, false])
	assert.equal(canDelete({ id: 'x', _source: { deletable: 'yes' } }), false)
	assert.equal(
		canDelete({ _source: { deletable: true } }),
		false,
		'no id, nothing to delete',
	)
	assert.deepEqual(
		withoutMessages(MESSAGES, ['m1', 'd1']).map((m) => m.id),
		['m2'],
	)
})

test('a deletable row has a delete button and a checkbox with a label; another row has neither', async () => {
	const html = await renderComponent(
		inState(InboxPage, { loading: false, messages: MESSAGES }),
		{ api: {}, t: withStrings(null, 'nl'), locale: 'nl' },
	)
	assert.equal((html.match(/data-testid="inbox-delete"/g) || []).length, 2)
	assert.equal(
		(html.match(/type="checkbox"/g) || []).length,
		3,
		'two rows and select all',
	)
	assert.match(html, />\s*Verwijderen\s*</)
	assert.match(html, /Alles selecteren/)
	assert.doesNotMatch(
		html,
		/data-testid="inbox-delete-confirm"/,
		'no question before one is asked',
	)
})

test('deleting one message asks first on the page, and Cancel deletes nothing', async () => {
	const api = fakeApi()
	const page = instance(InboxPage, { api, t, locale: 'en' })
	await page.load()
	page.askDelete([page.messages[0]])
	assert.deepEqual(page.confirming, ['m1'])
	assert.deepEqual(api.calls, [], 'nothing is deleted before the answer')

	const html = await renderComponent(
		inState(InboxPage, {
			loading: false,
			messages: MESSAGES,
			confirming: ['m1'],
		}),
		{ api, t: withStrings(null, 'nl'), locale: 'nl' },
	)
	assert.match(
		html,
		/data-testid="inbox-delete-confirm"[^]*Dit bericht verwijderen\? Dit kunt u niet ongedaan maken\.[^]*Ja, verwijderen[^]*Annuleren/,
	)
	assert.doesNotMatch(html, /window\.confirm|confirm\(/)

	page.cancelDelete()
	assert.deepEqual(page.confirming, [])
	assert.deepEqual(api.calls, [])
	assert.equal(page.messages.length, 3)
})

test('deleting the selected messages removes them, updates the count and says so', async () => {
	const api = fakeApi()
	const page = instance(InboxPage, { api, t, locale: 'en' })
	await page.load()
	page.toggleSelected('m1')
	page.toggleSelected('m2')
	page.toggleSelected('d1')
	assert.deepEqual(
		page.selected,
		['m1', 'm2'],
		'a row that cannot be deleted is never selected',
	)
	page.askDelete(page.selectedMessages)

	const nl = await renderComponent(
		inState(InboxPage, {
			loading: false,
			messages: MESSAGES,
			confirming: ['m1', 'm2'],
			selected: ['m1', 'm2'],
		}),
		{ api, t: withStrings(null, 'nl'), locale: 'nl' },
	)
	assert.match(nl, /2 berichten verwijderen\? Dit kunt u niet ongedaan maken\./)
	assert.match(nl, /Geselecteerde verwijderen \(2\)/)

	await page.confirmDelete()
	assert.deepEqual(api.calls, ['m1', 'm2'])
	assert.deepEqual(
		page.messages.map((m) => m.id),
		['d1'],
	)
	assert.deepEqual(page.selected, [])
	assert.deepEqual(page.confirming, [])
	assert.equal(page.notice, '2 messages are deleted.')
	assert.deepEqual(page.emitted.at(-1), ['unread', 1])
})

test('select all picks every deletable row, and a refused delete keeps that message', async () => {
	const api = fakeApi(new Set(['m2']))
	const page = instance(InboxPage, { api, t, locale: 'en' })
	await page.load()
	page.toggleAll()
	assert.deepEqual(page.selected, ['m1', 'm2'])
	page.askDelete(page.selectedMessages)
	await page.confirmDelete()
	assert.deepEqual(
		page.messages.map((m) => m.id),
		['m2', 'd1'],
	)
	assert.equal(page.failed, true)
	assert.deepEqual(page.selected, ['m2'], 'what is left stays chosen')

	const html = await renderComponent(
		inState(InboxPage, {
			loading: false,
			messages: page.messages,
			failed: true,
		}),
		{ api, t: withStrings(null, 'nl'), locale: 'nl' },
	)
	assert.match(
		html,
		/role="alert"[^>]*>\s*Niet elk bericht kon worden verwijderd\. Probeer het opnieuw\./,
	)

	page.toggleAll()
	assert.deepEqual(
		page.selected,
		[],
		'select all when all are chosen clears the choice',
	)
})

test('one deleted message reads singular', async () => {
	const api = fakeApi()
	const page = instance(InboxPage, {
		api,
		t: withStrings(null, 'nl'),
		locale: 'nl',
	})
	await page.load()
	page.askDelete([page.messages[1]])
	await page.confirmDelete()
	assert.equal(page.notice, 'Het bericht is verwijderd.')
})
