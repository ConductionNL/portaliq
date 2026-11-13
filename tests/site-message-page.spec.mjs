// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// A resident opens one message on its own page (a-resident-opens-one-message):
// the route a message opens on and the collection it names, the rows of
// "Over dit bericht", the case card, the attachments, and the mark-read on
// open, once.
//
// @spec openspec/changes/a-resident-opens-one-message/tasks.md#T04
// @spec openspec/changes/a-resident-opens-one-message/tasks.md#T05
// @spec openspec/changes/a-resident-opens-one-message/tasks.md#T07

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { navEntryForRoute } from '../src/shared/portalNav.js'
import {
	aboutRows,
	fileDetails,
	inboxSource,
	markOnOpen,
	messageOfRoute,
	messageRoute,
	receivedParts,
} from '../src/site/pages/inbox/message.js'
import { instance, inState, t } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const MessagePage = await loadSfc('src/site/pages/inbox/MessagePage.vue')
const InboxPage = await loadSfc('src/site/pages/inbox/InboxPage.vue')

const CONTRIBUTIONS = {
	unreadCount: 3,
	contributions: [
		{
			app: 'dossiq',
			label: 'Gemeente Zuiddrecht',
			collections: [
				{ id: 'berichten', kind: 'inbox', register: 'dossiq', schema: 'portaalBericht' },
				{ id: 'zaken', register: 'dossiq', schema: 'zaak' },
			],
		},
	],
}

const DECISION = {
	id: 'b1',
	subject: 'Het besluit op uw Woo-verzoek is gepubliceerd',
	body: 'Het besluit staat online.',
	receivedAt: '2026-10-07T07:12:00Z',
	read: false,
	about: '2026-0082',
	nature: 'Besluit op uw Woo-verzoek',
	rechtsgevolg: 'Dit is een besluit van de gemeente.',
	term: 'Bezwaar maken kan tot en met 18 november 2026',
	recordLink: { app: 'dossiq', collection: 'zaken', id: 'z-82', label: 'Verlichting fietspad Lindelaan' },
	_source: { appId: 'dossiq', label: 'Gemeente Zuiddrecht', register: 'dossiq', schema: 'portaalBericht', collection: 'berichten' },
	_files: [
		{ id: 1, name: 'Besluit Woo-verzoek 2026-0082.pdf', size: 412000 },
		{ id: 2, name: 'Inventarislijst.pdf', size: 86000 },
	],
}

const NAV = [
	{ key: 'inbox', special: 'inbox', label: 'Berichten' },
	{ key: 'dossiq:zaken', label: 'Zaken', contribution: { app: 'dossiq' }, page: { id: 'zaken', collection: 'zaken', blocks: [{ type: 'collection', collection: 'zaken' }] } },
]

test('a message opens on its own route under the inbox, and the route names it back', () => {
	assert.equal(messageRoute(DECISION), '/mijn/inbox/berichten/b1')
	assert.equal(messageRoute({ ...DECISION, _source: {} }), '')
	assert.equal(messageRoute({ _source: { collection: 'berichten' } }), '')
	assert.deepEqual(messageOfRoute('/mijn/inbox/berichten/b1'), { source: 'berichten', id: 'b1' })
	assert.deepEqual(messageOfRoute('/mijn/inbox/a%20b/x%2Fy'), { source: 'a b', id: 'x/y' })
	assert.equal(messageOfRoute('/mijn/inbox'), null)
	assert.equal(messageOfRoute('/mijn/inbox/berichten'), null)
	assert.equal(messageOfRoute('/mijn/inbox/berichten/b1/extra'), null)
	assert.equal(messageOfRoute('/mijn/tasks/a/b'), null)
})

test('the shell shows the inbox page for a message route', () => {
	assert.equal(navEntryForRoute(NAV, '/mijn/inbox/berichten/b1')?.key, 'inbox')
	assert.equal(navEntryForRoute(NAV, '/mijn/inbox/berichten'), null)
	assert.equal(navEntryForRoute([NAV[1]], '/mijn/inbox/berichten/b1'), null)
})

test("the source resolves from the resident's own inbox collections only", () => {
	assert.deepEqual(inboxSource(CONTRIBUTIONS, 'berichten'), { collection: 'berichten', register: 'dossiq', schema: 'portaalBericht' })
	assert.equal(inboxSource(CONTRIBUTIONS, 'zaken'), null, 'a collection that is no inbox')
	assert.equal(inboxSource(CONTRIBUTIONS, 'elsewhere'), null)
	assert.deepEqual(inboxSource(null, 'portalMessages'), { collection: 'portalMessages', register: 'portaliq', schema: 'portalMessage' })
	assert.equal(inboxSource(CONTRIBUTIONS, ''), null)
})

test('"Over dit bericht" lists the formal fields, and leaves out a row without a value', () => {
	const tr = (key) => key
	assert.deepEqual(
		aboutRows(DECISION, tr, 'nl').map((row) => [row.label, row.value]),
		[
			['From', 'Gemeente Zuiddrecht'],
			['About case', '2026-0082'],
			['Received', aboutRows(DECISION, tr, 'nl')[2].value],
			['Nature', 'Besluit op uw Woo-verzoek'],
			['Legal effect', 'Dit is een besluit van de gemeente.'],
			['Deadline', 'Bezwaar maken kan tot en met 18 november 2026'],
		],
	)
	const plain = { ...DECISION, nature: '', rechtsgevolg: undefined, term: null }
	assert.deepEqual(aboutRows(plain, tr, 'nl').map((row) => row.key), ['from', 'case', 'received'])
	assert.deepEqual(aboutRows({ subject: 'x' }, tr, 'nl'), [])
})

test('when a message came in reads to the minute, in the page language', () => {
	const parts = receivedParts('2026-10-07T07:12:00Z', 'nl')
	assert.match(parts.date, /oktober 2026/)
	assert.match(parts.time, /^\d{2}:\d{2}$/)
	assert.equal(receivedParts('', 'nl'), null)
	assert.equal(receivedParts('not a date', 'nl'), null)
})

test('an attachment names its type and size', () => {
	assert.equal(fileDetails({ name: 'Besluit.pdf', size: 412000 }), 'PDF · 412 kB')
	assert.equal(fileDetails({ name: 'scan.jpeg', size: 2400000 }), 'JPEG · 2.4 MB')
	assert.equal(fileDetails({ name: 'notitie' }), '')
	assert.equal(fileDetails({ name: 'a.txt', size: 0 }), 'TXT')
})

test('an unread message is marked read once on open; a read one never', async () => {
	const calls = []
	const api = { markMessageRead: async (message) => (calls.push(message.id), { ok: true }) }
	assert.deepEqual(await markOnOpen(api, DECISION), { read: true, marked: true })
	assert.deepEqual(await markOnOpen(api, { ...DECISION, read: true }), { read: true, marked: false })
	assert.deepEqual(calls, ['b1'])

	const refused = { markMessageRead: async () => ({ ok: false, status: 404 }) }
	assert.deepEqual(await markOnOpen(refused, DECISION), { read: false, marked: false })
})

test('the page shows the decision as drawn: header, case card, details, text and both attachments', async () => {
	const html = await renderComponent(
		inState(MessagePage, { state: 'shown', message: { ...DECISION, read: true } }),
		{ opened: { source: 'berichten', id: 'b1' }, api: {}, t, locale: 'nl', nav: NAV, contributions: CONTRIBUTIONS },
	)
	assert.match(html, /<h1[^>]*data-testid="message-title"[^>]*>\s*Het besluit op uw Woo-verzoek is gepubliceerd/)
	assert.match(html, /Ontvangen op 7 oktober 2026 om \d{2}:\d{2}/)
	assert.match(html, /Van Gemeente Zuiddrecht, over zaak /)
	assert.match(html, /data-testid="message-read-badge"[^>]*>\s*Gelezen/)
	assert.match(html, /data-testid="message-case-card"/)
	assert.match(html, /Verlichting fietspad Lindelaan/)
	assert.equal((html.match(/Naar uw zaak/g) || []).length, 2)
	assert.match(html, /<dl[^>]*data-testid="message-about"/)
	assert.match(html, /<dt>Rechtsgevolg<\/dt>/)
	assert.match(html, />\s*Bijlagen\s*</)
	assert.match(html, /Besluit Woo-verzoek 2026-0082\.pdf, PDF · 412 kB/)
	assert.match(html, /Inventarislijst\.pdf, PDF · 86 kB/)
	assert.equal((html.match(/Terug naar berichten/g) || []).length, 2)
	assert.doesNotMatch(html, /data-testid="message-action"/, 'the decision declares no action')
})

test('no case card without a record link, and no "Bijlagen" without files', async () => {
	const html = await renderComponent(
		inState(MessagePage, { state: 'shown', message: { ...DECISION, recordLink: undefined, _files: undefined } }),
		{ opened: { source: 'berichten', id: 'b1' }, api: {}, t, locale: 'nl', nav: NAV },
	)
	assert.doesNotMatch(html, /data-testid="message-case-card"/)
	assert.doesNotMatch(html, /Naar uw zaak/)
	assert.doesNotMatch(html, /data-testid="message-files"/)
	assert.doesNotMatch(html, /data-testid="message-read-badge"/, 'an unread message carries no badge')
})

test('a message that is not theirs reads the same as one that does not exist', async () => {
	const html = await renderComponent(
		inState(MessagePage, { state: 'missing' }),
		{ opened: { source: 'berichten', id: 'henk' }, api: {}, t, locale: 'nl' },
	)
	assert.match(html, /Dit bericht bestaat niet of is niet voor u\./)
	assert.match(html, /Terug naar berichten/)
})

test('an unknown source shows not found without calling the server', async () => {
	let asked = 0
	const page = instance(MessagePage, {
		opened: { source: 'elsewhere', id: 'b1' },
		api: { fetchMessage: async () => (asked++, { ok: true, message: DECISION }) },
		contributions: CONTRIBUTIONS,
	})
	await page.load()
	assert.equal(page.state, 'missing')
	assert.equal(asked, 0)
})

test('opening an unread message marks it read and tells the inbox, once', async () => {
	const marked = []
	const page = instance(MessagePage, {
		opened: { source: 'berichten', id: 'b1' },
		api: {
			fetchMessage: async (source, id) => (assert.deepEqual([source, id], [{ collection: 'berichten', register: 'dossiq', schema: 'portaalBericht' }, 'b1']), { ok: true, status: 200, message: DECISION }),
			markMessageRead: async (message) => (marked.push(message.id), { ok: true }),
		},
		contributions: CONTRIBUTIONS,
	})
	page.$nextTick = (fn) => fn()
	page.$refs = {}
	await page.load()
	assert.equal(page.state, 'shown')
	assert.equal(page.message.read, true)
	assert.deepEqual(marked, ['b1'])
	assert.deepEqual(page.emitted.filter(([name]) => name === 'read'), [['read', 'b1']])

	const failing = instance(MessagePage, {
		opened: { source: 'berichten', id: 'b1' },
		api: { fetchMessage: async () => ({ ok: false, status: 500, message: null }) },
		contributions: CONTRIBUTIONS,
	})
	await failing.load()
	assert.equal(failing.state, 'failed')
})

test('the inbox counts down by one when a message opened cold is read', () => {
	const inbox = instance(InboxPage, { api: {}, contributions: { unreadCount: 3 }, currentRoute: '/mijn/inbox/berichten/b1' })
	assert.deepEqual(inbox.opened, { source: 'berichten', id: 'b1' })
	inbox.onOpenedRead('b1')
	assert.deepEqual(inbox.emitted, [['unread', 2]])

	const listed = instance(InboxPage, { api: {}, currentRoute: '' })
	listed.loading = false
	listed.messages = [{ id: 'b1', read: false }, { id: 'b2', read: false }]
	listed.onOpenedRead('b1')
	assert.deepEqual(listed.emitted, [['unread', 1]])
})

test('each message title in the inbox links to its page', async () => {
	const html = await renderComponent(
		inState(InboxPage, { loading: false, messages: [DECISION] }),
		{ api: {}, t, locale: 'nl' },
	)
	assert.match(html, /<a[^>]*href="[^"]*\/mijn\/inbox\/berichten\/b1"[^>]*data-testid="inbox-row-title"/)
})
