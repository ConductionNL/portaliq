#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-messages-per-record.spec.mjs: the messages page groups conversations
// per record, writes to a contact the app names and replies in a thread
// (site-messages-per-record), as the plain functions it draws from and the
// page itself over a fake api.
//
// Usage:
//   node --test tests/site-messages-per-record.spec.mjs
//
// @spec openspec/changes/site-messages-per-record/specs/portal-contribution-contract/spec.md

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	contactOptions,
	conversationStrings,
	conversationTranslator,
	initialsOf,
	recordTabs,
	threadCard,
	threadsInTab,
} from '../src/site/pages/inbox/conversations.js'
import { instance, inState, t } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const MessagesPage = await loadSfc('src/site/pages/inbox/MessagesPage.vue')
const tr = conversationTranslator((key) => key, 'nl')

// Fatima Hulstkamp: Vera in Groep 7 with Meester Daan, Sami in Groep 4 with Juf Esra.
const CONTACTS = [
	{ staffRef: 'po-leerkracht-09', name: 'Meester Daan', role: 'Leerkracht', recordRef: 'enr-vera', recordLabel: 'Vera, Groep 7' },
	{ staffRef: 'po-leerkracht-07', name: 'Juf Esra', role: 'Leerkracht', recordRef: 'enr-sami', recordLabel: 'Sami, Groep 4' },
]
const THREADS = [
	{
		id: 't-old',
		kind: 'direct',
		title: 'Antwoord op uw vraag over het huiswerk',
		staffName: 'Meester Daan',
		recordRef: 'enr-vera',
		recordLabel: 'Vera, Groep 7',
		createdAt: '2026-09-28T08:00:00+02:00',
		summary: { unread: 0, lastBody: 'Oefenen met de kaart is genoeg.', lastSentAt: '2026-09-28T09:00:00+02:00', lastFromMe: false },
	},
	{
		id: 't-new',
		kind: 'direct',
		title: 'Beterschap voor Sami',
		staffName: 'Juf Esra',
		recordRef: 'enr-sami',
		recordLabel: 'Sami, Groep 4',
		createdAt: '2026-10-05T08:12:00+02:00',
		summary: { unread: 1, lastBody: 'Ik heb uw melding gezien.', lastSentAt: '2026-10-05T08:12:00+02:00', lastFromMe: false },
	},
]

test('a tab per record, after "Alle berichten"; one record draws no tabs', () => {
	assert.deepEqual(
		recordTabs(THREADS, CONTACTS, tr).map((tab) => [tab.key, tab.label]),
		[
			['', 'Alle berichten'],
			['enr-vera', 'Over Vera'],
			['enr-sami', 'Over Sami'],
		],
	)
	assert.deepEqual(recordTabs([THREADS[0]], [CONTACTS[0]], tr), [])
})

test('a tab shows its own record, newest first', () => {
	assert.deepEqual(threadsInTab(THREADS, '').map((thread) => thread.id), ['t-new', 't-old'])
	assert.deepEqual(threadsInTab(THREADS, 'enr-vera').map((thread) => thread.id), ['t-old'])
})

test('a card says who, about whom, the subject, the newest message and whether it is new', () => {
	const card = threadCard(THREADS[1], tr)
	assert.equal(card.who, 'Juf Esra')
	assert.equal(card.initials, 'JE')
	assert.equal(card.about, 'over Sami')
	assert.equal(card.title, 'Beterschap voor Sami')
	assert.equal(card.preview, 'Ik heb uw melding gezien.')
	assert.equal(card.isNew, true)
	assert.equal(threadCard(THREADS[0], tr).isNew, false)
	assert.equal(threadCard({ kind: 'group' }, (k) => k).title, 'Group conversation')
	assert.equal(initialsOf('Sanne Kramer'), 'SK')
})

test('the "to" field reads "Meester Daan, over Vera (Groep 7)" and follows the open tab', () => {
	const all = contactOptions(CONTACTS, tr)
	assert.deepEqual(all.map((o) => o.label), ['Meester Daan, over Vera (Groep 7)', 'Juf Esra, over Sami (Groep 4)'])
	assert.equal(all[0].value, 'enr-vera|po-leerkracht-09')
	assert.deepEqual(contactOptions(CONTACTS, tr, 'enr-sami').map((o) => o.staffRef), ['po-leerkracht-07'])
})

test('every word exists in Dutch and English', () => {
	assert.deepEqual(Object.keys(conversationStrings.nl).sort(), Object.keys(conversationStrings.en).sort())
})

/**
 * A fake api: the threads, the contacts and every messaging call it got.
 *
 * @param {object} [options] What the server answers.
 * @return {object}
 */
function fakeApi({ sendOk = true } = {}) {
	const calls = []
	return {
		calls,
		async fetchThreads() {
			return THREADS
		},
		async getDetails() {
			return {}
		},
		async fetchThreadMessages() {
			return [{ id: 'm1', senderRef: 'po-leerkracht-07', body: 'Ik heb uw melding gezien.' }]
		},
		async messaging(method, path, body) {
			calls.push([method, path, body])
			if (path === '/contacts') {
				return { ok: true, status: 200, data: { composeLabel: 'Een bericht aan de leerkracht', composeHint: 'De leerkracht antwoordt meestal binnen twee schooldagen.', contacts: CONTACTS } }
			}
			if (path === '/threads') {
				return sendOk ? { ok: true, status: 200, data: { id: 't-9' } } : { ok: false, status: 403, data: { error: 'forbidden' } }
			}
			return { ok: true, status: 204, data: null }
		},
	}
}

test('the page writes to the chosen contact about the chosen record, and opens the new conversation', async () => {
	const api = fakeApi()
	const page = instance(MessagesPage, { api, t, locale: 'nl', session: { subjectRef: 'fatima' } })
	await page.load()
	assert.equal(page.hasCompose, true)
	assert.equal(page.composeLabel, 'Een bericht aan de leerkracht')

	page.draft.to = 'enr-vera|po-leerkracht-09'
	page.draft.body = '  Moet Vera de kaart uit het hoofd kennen?  '
	await page.sendNew()

	assert.deepEqual(api.calls.find((call) => call[1] === '/threads'), [
		'POST',
		'/threads',
		{ staffRef: 'po-leerkracht-09', recordRef: 'enr-vera', title: '', body: 'Moet Vera de kaart uit het hoofd kennen?' },
	])
	assert.equal(page.composeNotice, 'Uw bericht is verstuurd.')
	assert.equal(page.activeId, 't-9')
	assert.ok(api.calls.some((call) => call[1] === '/threads/t-9/read'), 'an opened conversation is marked read')
})

test('a refused or empty message says so and sends nothing twice', async () => {
	const api = fakeApi({ sendOk: false })
	const page = instance(MessagesPage, { api, t, locale: 'nl', session: { subjectRef: 'fatima' } })
	await page.load()

	page.draft.to = 'enr-vera|po-leerkracht-09'
	page.draft.body = '   '
	await page.sendNew()
	assert.equal(page.composeNotice, 'Schrijf eerst een bericht.')
	assert.equal(api.calls.filter((call) => call[1] === '/threads').length, 0)

	page.draft.body = 'Hallo'
	await page.sendNew()
	assert.equal(page.composeNotice, 'Uw bericht kon niet worden verstuurd. Probeer het opnieuw.')
	assert.equal(page.draft.body, 'Hallo', 'a refused message stays in the form')
})

test('a reply goes to the open conversation', async () => {
	const api = fakeApi()
	const page = instance(MessagesPage, { api, t, locale: 'nl', session: { subjectRef: 'fatima' } })
	await page.load()
	await page.choose('t-new')
	page.reply = 'Dank u wel!'
	await page.sendReply()
	assert.ok(api.calls.some((call) => call[1] === '/threads/t-new/messages' && call[2].body === 'Dank u wel!'))
	assert.equal(page.reply, '')
})

test('the page draws the tabs, the "Nieuw" card and the form with its own words', async () => {
	const html = await renderComponent(
		inState(MessagesPage, { threads: THREADS, contacts: CONTACTS, composeLabel: 'Een bericht aan de leerkracht' }),
		{ api: fakeApi(), t, locale: 'nl', session: { subjectRef: 'fatima' } },
	)
	assert.match(html, />\s*Over Vera\s*</)
	assert.match(html, />\s*Nieuw\s*</)
	assert.match(html, /Een bericht aan de leerkracht/)
	assert.match(html, /Meester Daan, over Vera \(Groep 7\)/)
	assert.match(html, /<label class="utrecht-form-label" for="pq-compose-body">/)

	const alone = await renderComponent(inState(MessagesPage, { threads: [] }), { api: fakeApi(), t, locale: 'nl' })
	assert.doesNotMatch(alone, /data-testid="messages-compose"/, 'nobody to write to: no form')
})
