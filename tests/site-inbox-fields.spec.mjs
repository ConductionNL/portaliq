// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// A message names its record and links its action: the role line, the
// "About" line, one action button for a page of this portal, the tabs and
// "mark all read".
//
// @spec openspec/changes/a-message-names-its-record-and-links-its-action/specs/portal-notifications-and-preferences/spec.md

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { actionOf, inboxTabs, messagesOnTab } from '../src/site/pages/inbox/inbox.js'
import { instance, inState, t } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const InboxPage = await loadSfc('src/site/pages/inbox/InboxPage.vue')

const PETRA = {
	id: 'p1',
	subject: 'Vraag over je uren van dinsdag 29 september',
	read: false,
	senderRole: 'Praktijkopleider, Bakker Techniek BV',
	about: 'Uren week 40',
	aboutLink: '/mijn/bpv-uren',
	tab: 'BPV',
	action: { label: 'Uren aanpassen', href: '/mijn/bpv-uren/2026-09-29' },
}

function render (messages, entry = null) {
  return renderComponent(inState(InboxPage, { loading: false, messages }), {
		api: {},
		t,
		locale: 'nl',
		entry,
	})
}

test("Petra's question shows the role, the about line and the action button", async () => {
	const html = await render([PETRA])
	assert.match(html, /data-testid="inbox-row-role"[^>]*>\s*Praktijkopleider, Bakker Techniek BV/)
	assert.match(html, /Over: Uren week 40/)
	assert.match(html, /href="\/mijn\/bpv-uren"/)
	assert.match(html, /data-testid="inbox-row-action"[^>]*>\s*Uren aanpassen/)
})

test('an action outside the portal draws no button', async () => {
	for (const href of ['https://example.org/pay', '//example.org/pay', 'javascript:alert(1)', '']) {
		const message = { ...PETRA, action: { label: 'Betalen', href } }
		assert.equal(actionOf(message, 'http://localhost:8080'), null, href)
		assert.doesNotMatch(await render([message]), /inbox-row-action/, href)
	}
	assert.equal(actionOf({ action: { label: 'Open', href: 'http://localhost:8080/x' } }, 'http://localhost:8080').href, 'http://localhost:8080/x')
	assert.equal(actionOf({ action: { label: '', href: '/x' } }), null)
})

test('a message without the fields shows none of them', async () => {
	const html = await render([{ id: 'm9', subject: 'Plain', read: true }])
	assert.doesNotMatch(html, /inbox-row-role|inbox-row-about|inbox-row-action/)
})

test('the tabs are all, unread with its count and one per tab value', () => {
	const messages = [
		PETRA,
		{ id: 'm2', read: true, tab: 'Examens' },
		{ id: 'm3', read: false, tab: 'BPV' },
		{ id: 'm4', read: true },
	]
	assert.deepEqual(
		inboxTabs(messages).map((tab) => tab.key),
		['all', 'unread', 'tab:BPV', 'tab:Examens'],
	)
	assert.deepEqual(messagesOnTab(messages, 'unread').map((m) => m.id), ['p1', 'm3'])
	assert.deepEqual(messagesOnTab(messages, 'tab:Examens').map((m) => m.id), ['m2'])
	assert.equal(messagesOnTab(messages, 'all').length, 4)
})

test('the tab strip shows only where the page declares tabs', async () => {
	const messages = [PETRA, { id: 'm2', read: false }]
	assert.doesNotMatch(await render(messages), /inbox-tab-/)
	const html = await render(messages, { tabs: true })
	assert.match(html, /data-testid="inbox-tab-unread"[^>]*>\s*Ongelezen \(2\)/)
	assert.match(html, /data-testid="inbox-tab-tab:BPV"[^>]*>\s*BPV/)
})

test('"mark all read" marks only the shown messages, through their own endpoint', async () => {
	const marked = []
	const page = instance(InboxPage, {
		entry: { tabs: true },
		api: {
			markMessageRead: async (m) => {
				marked.push(m.id)
				return { ok: m.id !== 'bad' }
			},
		},
	})
	page.messages = [
		{ id: 'a', read: false, tab: 'BPV' },
		{ id: 'b', read: false, tab: 'Examens' },
		{ id: 'bad', read: false, tab: 'BPV' },
		{ id: 'c', read: true, tab: 'BPV' },
	]
	page.activeTab = 'tab:BPV'
	await page.markAllRead()
	assert.deepEqual(marked, ['a', 'bad'])
	assert.deepEqual(page.messages.map((m) => m.read), [true, false, false, true])
	assert.deepEqual(page.emitted.at(-1), ['unread', 2])
})
