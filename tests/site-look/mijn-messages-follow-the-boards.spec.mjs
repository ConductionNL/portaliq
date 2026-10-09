#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// mijn-messages-follow-the-boards.spec.mjs: the conversations page as the
// Berichten boards draw it: reachable before the first message, titled with
// the menu's word, "Nieuw bericht" beside the title, the organisation's own
// messages among the conversations with a way to what they are about,
// "Antwoorden" on a conversation, a form that names a teacher at once, and no
// raw identifier as a title (mijn-messages-follow-the-boards).
//
// Usage:
//   node --test tests/site-look/mijn-messages-follow-the-boards.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { caseTitle } from '../../src/shared/myCases.js'
import { buildNav, shellSections } from '../../src/shared/portalNav.js'
import { withLayoutLabel } from '../../src/site/lib/residentMenu.js'
import {
	conversationTranslator,
	messageItems,
	noticeCard,
	whenWords,
} from '../../src/site/pages/inbox/conversations.js'
import { pageOwnsHeading } from '../../src/site/pages/registry.js'
import { instance, inState, t } from '../support/page-instance.mjs'
import { loadSfc, renderComponent } from '../support/render-sfc.mjs'

import '../../src/site/pages/inbox/index.js'

const MessagesPage = await loadSfc('src/site/pages/inbox/MessagesPage.vue')
const ct = conversationTranslator((key) => key, 'nl')

const CONTACTS = [
	{
		staffRef: 'daan',
		staffName: 'Meester Daan',
		recordRef: 'vera',
		recordLabel: 'Vera, Groep 7',
	},
	{
		staffRef: 'esra',
		staffName: 'Juf Esra',
		recordRef: 'sami',
		recordLabel: 'Sami, Groep 4',
	},
]

test('the conversations show in the menu once there is someone to write to', () => {
	const none = shellSections({ threads: [], contacts: [] })
	assert.equal(none.messages, false)
	assert.equal(shellSections({ threads: [], contacts: CONTACTS }).messages, true)
	assert.equal(shellSections({ threads: [{ id: 't' }] }).messages, true)
	const nav = buildNav([], t, { messages: true })
	assert.ok(nav.some((entry) => entry.special === 'messages'))
})

test('the page carries the menu word for it, as title and in the breadcrumb', () => {
	const entry = { key: '__messages__', label: 'Gesprekken', special: 'messages' }
	const layout = [
		{
			title: 'Mijn Wilgenboom',
			items: ['overview', { item: 'messages', label: 'Berichten' }],
		},
	]
	assert.equal(withLayoutLabel(entry, layout).label, 'Berichten')
	assert.equal(withLayoutLabel(entry, null).label, 'Gesprekken')
	assert.equal(withLayoutLabel(null, layout), null)
	assert.equal(pageOwnsHeading(entry), true)
})

test('the organisation messages stand among the conversations, newest first; a record tab holds conversations only', () => {
	const threads = [
		{ id: 't1', summary: { lastSentAt: '2026-10-05T08:40:00+02:00' } },
		{ id: 't2', summary: { lastSentAt: '2026-09-28T10:00:00+02:00' } },
	]
	const notices = [
		{
			id: 'm1',
			subject: 'Uw afwezigheidsmelding is goedgekeurd',
			receivedAt: '2026-10-01T09:00:00+02:00',
		},
		{ id: 'm2', subject: '', receivedAt: '2026-10-06T09:00:00+02:00' },
	]
	assert.deepEqual(
		messageItems(threads, notices, '').map((item) => item.key),
		['thread:t1', 'notice:m1', 'thread:t2'],
	)
	assert.deepEqual(
		messageItems(threads, notices, 'vera').map((item) => item.key),
		['thread:t1', 'thread:t2'],
	)
	const card = noticeCard(
		{
			subject: 'Uw afwezigheidsmelding is goedgekeurd',
			body: 'Meester Daan heeft de melding goedgekeurd.',
			read: false,
		},
		'De Wilgenboom',
	)
	assert.equal(card.who, 'De Wilgenboom')
	assert.equal(card.initials, 'D')
	assert.equal(card.isNew, true)
})

test('a message says when it came in the board words', () => {
	const now = new Date(2026, 9, 5, 12, 0)
	assert.equal(whenWords('2026-10-05T08:40:00', now, 'nl', ct), 'vandaag 8.40 uur')
	assert.equal(
		whenWords('2026-10-04T16:05:00', now, 'nl', ct),
		'gisteren 16.05 uur',
	)
	assert.equal(whenWords('2026-10-01T09:00:00', now, 'nl', ct), '1 oktober')
	assert.equal(whenWords('', now, 'nl', ct), '')
})

test('the page loads the organisation messages and names a teacher in the form at once', async () => {
	const api = {
		async fetchThreads() {
			return []
		},
		async getDetails() {
			return {}
		},
		async fetchInbox() {
			return [{ id: 'm1', subject: 'Uw afwezigheidsmelding is goedgekeurd' }]
		},
		async messaging(method, path) {
			return path === '/contacts'
				? { ok: true, data: { contacts: CONTACTS } }
				: { ok: false }
		},
	}
	const page = instance(MessagesPage, { api, t, locale: 'nl' })
	await page.load()
	assert.equal(page.notices.length, 1)
	assert.equal(page.contacts.length, 2)
	// The watcher's rule, as Vue would run it once the choices are known.
	MessagesPage.watch.options.call(page)
	assert.equal(page.draft.to, page.options[0].value)
})

test('the page draws the board: title with "Nieuw bericht", the organisation message with "Bekijken", a conversation with "Antwoorden", no subject field', async () => {
	const html = await renderComponent(
		inState(MessagesPage, {
			threads: [
				{
					id: 't1',
					staffName: 'Meester Daan',
					title: 'De gesprekstijd is bevestigd',
					recordRef: 'vera',
					recordLabel: 'Vera, Groep 7',
					summary: { unread: 1, lastBody: 'Tot dan!' },
				},
			],
			notices: [
				{
					id: 'm1',
					subject: 'Uw afwezigheidsmelding is goedgekeurd',
					body: 'Meester Daan heeft de melding goedgekeurd.',
					recordLink: {
						app: 'learniq',
						collection: 'parentExcuseRequests',
						id: 'e1',
					},
				},
			],
			contacts: CONTACTS,
			draft: { to: 'x', title: '', body: '' },
		}),
		{
			api: {},
			t,
			locale: 'nl',
			entry: { key: '__messages__', label: 'Berichten', special: 'messages' },
			portal: { title: 'De Wilgenboom' },
			nav: buildNav(
				[
					{
						app: 'learniq',
						collections: [{ id: 'parentExcuseRequests' }],
						pages: [
							{
								id: 'absence',
								label: 'Afwezigheid',
								blocks: [
									{
										type: 'collection',
										collection: 'parentExcuseRequests',
									},
								],
							},
						],
					},
				],
				t,
				{ messages: true },
			),
		},
	)
	assert.match(
		html,
		/<div class="pq-messages__head"><h1 id="site-account-title"[^>]*>Berichten<\/h1><button[^>]*data-testid="messages-new"><svg/,
	)
	assert.match(
		html,
		/data-testid="messages-notice"[\s\S]*De Wilgenboom[\s\S]*Uw afwezigheidsmelding is goedgekeurd/,
	)
	assert.match(html, /data-testid="messages-notice-open">\s*Bekijken/)
	assert.match(
		html,
		/pq-thread--new[\s\S]*Nieuw[\s\S]*Meester Daan[\s\S]*over Vera/,
	)
	assert.match(html, /data-testid="messages-reply-open">\s*Antwoorden/)
	assert.equal(html.includes('pq-compose-subject'), false, 'no subject field')
	assert.match(html, /<details class="pq-messages__language"/)
})

test('a case never takes a uuid as its title', () => {
	assert.equal(caseTitle({ id: '3f2a9c1e-1b2c-4d5e-8f90-123456789abc' }), '')
	assert.equal(
		caseTitle({
			id: '3f2a9c1e-1b2c-4d5e-8f90-123456789abc',
			_caseTypeName: 'Verlof',
		}),
		'Verlof',
	)
	assert.equal(caseTitle({ id: 'ZAAK-7' }), 'ZAAK-7')
})
