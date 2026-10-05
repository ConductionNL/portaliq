#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-inbox-pages.spec.mjs: the site's inbox, messages and news pages
// (site-reaches-portal-parity slice d, REQ-SRP-030 to REQ-SRP-033). The pages
// register lazily by section key, say what the React portal says in Dutch and
// English, and behave as the React pages did: marking a message read drops
// the unread count, "Open" goes to the page that shows the record, the
// language picker reloads the text in the chosen language.
//
// Usage:
//   node --test tests/site-inbox-pages.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { OPEN_STORAGE_KEY } from '../src/shared/openRecord.js'
import { buildNav, routeForNav } from '../src/shared/portalNav.js'
import { blockSlotLoader } from '../src/site/pages/collections/blockSlots.js'
import {
	markedRead,
	recordRoute,
	TASKS_ROUTE,
} from '../src/site/pages/inbox/inbox.js'
import { components, pages } from '../src/site/pages/inbox/index.js'
import strings from '../src/site/pages/inbox/strings.js'
import { withStrings } from '../src/site/pages/inbox/translate.js'
import { sitePageLoader } from '../src/site/pages/registry.js'
import { instance, inState, t } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const InboxPage = await loadSfc('src/site/pages/inbox/InboxPage.vue')
const MessagesPage = await loadSfc('src/site/pages/inbox/MessagesPage.vue')
const NewsPage = await loadSfc('src/site/pages/inbox/NewsPage.vue')
const MessageLanguagePicker = await loadSfc(
	'src/site/components/inbox/MessageLanguagePicker.vue',
)

const NAV = [
	{
		key: 'dossiq:cases',
		label: 'Zaken',
		contribution: { app: 'dossiq' },
		page: { id: 'cases', blocks: [{ type: 'collection', collection: 'cases' }] },
	},
	{ key: '__inbox__', label: 'Inbox', special: 'inbox' },
]

const MESSAGES = [
	{
		id: 'm1',
		subject: 'Besluit',
		body: 'Uw aanvraag is toegekend.',
		read: false,
		receivedAt: '2026-09-30T10:00:00+02:00',
		_source: { label: 'Dossiq' },
		nature: 'Besluit',
		rechtsgevolg: 'Bezwaar mogelijk',
		term: '2026-11-11T00:00:00+01:00',
		recordLink: { app: 'dossiq', collection: 'cases', id: 'z-1' },
		_deliveries: [{ channel: 'messageBox', label: 'MijnOverheid Berichtenbox' }],
	},
	{
		id: 'm2',
		subject: 'Taak',
		read: true,
		taskUuid: 'task-7',
		recordLink: { app: 'elders', collection: 'x', id: 'y' },
	},
]

/**
 * A storage that keeps what it is given, like sessionStorage.
 *
 * @return {object} The storage.
 */
function memoryStorage() {
	const map = new Map()
	return {
		getItem: (key) => (map.has(key) ? map.get(key) : null),
		setItem: (key, value) => map.set(key, String(value)),
		removeItem: (key) => map.delete(key),
	}
}

/**
 * The shared translation bundle.
 *
 * @param {string} locale `nl` or `en`.
 * @return {object} The bundle.
 */
function portalBundle(locale) {
	const file = join(ROOT, 'src', 'shared', 'i18n', `${locale}.json`)
	return JSON.parse(readFileSync(file, 'utf8'))
}

test('the shell renders these pages for their sections and the timed test in its place', () => {
	for (const key of ['inbox', 'tasks', 'messages', 'news']) {
		assert.equal(
			sitePageLoader({ key: `__${key}__`, special: key }),
			pages[key],
			`${key} replaces the placeholder`,
		)
	}
	assert.equal(blockSlotLoader('timedTask'), components.timedTask)
	assert.equal(TASKS_ROUTE, routeForNav({ special: 'tasks' }))
})

test("the pages register lazily by the portal's section keys", () => {
	assert.deepEqual(Object.keys(pages).sort(), [
		'inbox',
		'messages',
		'news',
		'tasks',
	])
	const index = readFileSync(
		join(ROOT, 'src', 'site', 'pages', 'inbox', 'index.js'),
		'utf8',
	)
	for (const [key, file] of [
		['inbox', 'InboxPage'],
		['tasks', 'TasksPage'],
		['messages', 'MessagesPage'],
		['news', 'NewsPage'],
	]) {
		assert.equal(typeof pages[key], 'function')
		assert.match(
			index,
			new RegExp(`${key}: \\(\\) => import\\('\\./${file}\\.vue'\\)`),
		)
	}
	assert.equal(typeof components.timedTask, 'function')
	assert.match(index, /@typedef \{object\} SitePageProps/)
	const sections = buildNav([], (key) => key, {
		tasks: true,
		messages: true,
		news: true,
	}).map((entry) => entry.special)
	for (const key of Object.keys(pages)) {
		assert.ok(
			sections.includes(key),
			`${key} is a section of the shared navigation`,
		)
	}
})

test('every string says what the React portal says, in Dutch and English', () => {
	for (const locale of ['nl', 'en']) {
		const bundle = portalBundle(locale)
		for (const [key, value] of Object.entries(strings[locale])) {
			assert.equal(value, bundle[key], `${locale}: ${key}`)
		}
	}
	assert.deepEqual(Object.keys(strings.nl).sort(), Object.keys(strings.en).sort())
})

test("the shell's translator wins, and a key it does not know falls back to the folder's strings", () => {
	const shell = (key) => (key === 'Inbox' ? 'Postvak' : key)
	const nl = withStrings(shell, 'nl')
	assert.equal(nl('Inbox'), 'Postvak')
	assert.equal(nl('Mark as read'), strings.nl['Mark as read'])
	assert.equal(
		nl('Finish before {date}', { date: '1-1-2027' }),
		strings.nl['Finish before {date}'].replace('{date}', '1-1-2027'),
	)
	assert.equal(withStrings(null, 'en')('Unknown key'), 'Unknown key')
})

test('marking a message read flips that row only and drops the unread count by one', async () => {
	assert.deepEqual(
		markedRead(MESSAGES, 'm1').map((m) => m.read),
		[true, true],
	)
	const calls = []
	const api = {
		async fetchInbox() {
			return MESSAGES
		},
		async markMessageRead(message) {
			calls.push(message.id)
			return { ok: true }
		},
	}
	const page = instance(InboxPage, {
		api,
		t,
		locale: 'en',
		contributions: { unreadCount: 3 },
		nav: NAV,
	})
	await page.load()
	assert.equal(page.messages.length, 2)
	// The loaded rows are the truth, not the sign-in count of 3: a notice a
	// job wrote after sign-in counts too (woo-inbox-notices REQ-NAP-011).
	assert.deepEqual(page.emitted, [['unread', 1]])
	await page.markRead(page.messages[0])
	assert.deepEqual(calls, ['m1'])
	assert.equal(page.messages[0].read, true)
	assert.deepEqual(page.emitted, [
		['unread', 1],
		['unread', 0],
	])
	await page.markRead(page.messages[1])
	assert.deepEqual(calls, ['m1'], 'a read message is not sent again')
})

test('a page mounted again after a read counts from its rows, not from the sign-in count', async () => {
	// The shell has not reloaded the contributions yet, so it still hands
	// the page the sign-in count of 2, while the server already has m1 read.
	const rows = [
		{ ...MESSAGES[0], read: true },
		{ ...MESSAGES[1], read: false },
	]
	const api = {
		async fetchInbox() {
			return rows
		},
		async markMessageRead() {
			return { ok: true }
		},
	}
	const page = instance(InboxPage, { api, t, contributions: { unreadCount: 2 } })
	assert.equal(page.unread, 0, 'nothing loaded, nothing counted')
	await page.load()
	assert.deepEqual(page.emitted, [['unread', 1]])
	await page.markRead(page.messages[1])
	assert.deepEqual(page.emitted, [
		['unread', 1],
		['unread', 0],
	])
})

test('an inbox the server did not answer leaves the shell its own count', async () => {
	const page = instance(InboxPage, {
		api: {
			async fetchInbox() {
				return null
			},
		},
		t,
		contributions: { unreadCount: 4 },
	})
	await page.load()
	assert.deepEqual(page.messages, [])
	assert.deepEqual(page.emitted, [], 'a failed read is not "0 unread"')
})

test('a refused mark-read changes nothing', async () => {
	const api = {
		async markMessageRead() {
			return { ok: false }
		},
	}
	const page = instance(InboxPage, { api, t, contributions: { unreadCount: 1 } })
	page.messages = [{ ...MESSAGES[0] }]
	await page.markRead(page.messages[0])
	assert.equal(page.messages[0].read, false)
	assert.deepEqual(page.emitted, [])
})

test('"Open" goes to the page that shows the record and keeps the record to preselect', () => {
	assert.equal(recordRoute(NAV, MESSAGES[0].recordLink), '/mijn/dossiq/cases')
	assert.equal(
		recordRoute(NAV, MESSAGES[1].recordLink),
		null,
		'no page shows that collection',
	)
	const storage = memoryStorage()
	globalThis.window = { sessionStorage: storage }
	try {
		const page = instance(InboxPage, { api: {}, t, nav: NAV })
		page.openRecord(MESSAGES[0].recordLink)
		assert.deepEqual(page.emitted, [['navigate', '/mijn/dossiq/cases']])
		assert.deepEqual(JSON.parse(storage.getItem(OPEN_STORAGE_KEY)), {
			app: 'dossiq',
			collection: 'cases',
			id: 'z-1',
		})
	} finally {
		delete globalThis.window
	}
})

test('a plain click on "Open" stays in the site; a click for a new tab is left to the browser', () => {
	const storage = memoryStorage()
	globalThis.window = { sessionStorage: storage }
	try {
		const page = instance(InboxPage, { api: {}, t, nav: NAV })
		for (const modifier of [
			{ ctrlKey: true },
			{ metaKey: true },
			{ shiftKey: true },
			{ button: 1 },
		]) {
			let prevented = false
			page.onOpenClick(
				{
					...modifier,
					preventDefault: () => {
						prevented = true
					},
				},
				MESSAGES[0].recordLink,
			)
			assert.equal(prevented, false, JSON.stringify(modifier))
		}
		assert.deepEqual(page.emitted, [])
		assert.equal(storage.getItem(OPEN_STORAGE_KEY), null)

		let prevented = false
		page.onOpenClick(
			{
				button: 0,
				preventDefault: () => {
					prevented = true
				},
			},
			MESSAGES[0].recordLink,
		)
		assert.equal(prevented, true)
		assert.deepEqual(page.emitted, [['navigate', '/mijn/dossiq/cases']])
		assert.equal(JSON.parse(storage.getItem(OPEN_STORAGE_KEY)).id, 'z-1')
	} finally {
		delete globalThis.window
	}
})

test('an inbox row shows unread in text, its readiness fields, its delivery and its ways out', async () => {
	const html = await renderComponent(
		inState(InboxPage, { loading: false, messages: MESSAGES }),
		{ api: {}, t, locale: 'en', nav: NAV },
	)
	assert.match(html, /<strong class="pq-inbox-row__unread">Unread<\/strong>/)
	assert.equal(
		(html.match(/pq-inbox-row__unread"/g) || []).length,
		1,
		'only the unread row',
	)
	assert.match(html, /<dt>Nature<\/dt><dd>Besluit<\/dd>/)
	assert.match(html, /<dt>Legal effect<\/dt><dd>Bezwaar mogelijk<\/dd>/)
	assert.match(html, /<dt>Deadline<\/dt>/)
	assert.match(html, /Also sent to MijnOverheid Berichtenbox\./)
	// "Open" is a real link with the record's address, so it opens in a new
	// tab and reads as a link to a screen reader; only where a page shows it.
	assert.equal(
		(html.match(/data-testid="inbox-row-open"[^>]*>Open<\/a>/g) || []).length,
		1,
		'Open only where a page shows the record',
	)
	assert.match(
		html,
		/<a class="utrecht-button-link[^"]*" href="\/mijn\/dossiq\/cases" data-testid="inbox-row-open"/,
	)
	assert.doesNotMatch(html, />Open<\/button>/)
	assert.match(html, />View task<\/button>/)
	assert.match(
		html,
		/<button type="button" class="utrecht-button utrecht-button--subtle">Mark as read<\/button>/,
	)
	assert.match(
		html,
		/<button type="button" class="utrecht-button utrecht-button--subtle" disabled>Read<\/button>/,
	)

	const empty = await renderComponent(
		inState(InboxPage, { loading: false, messages: [] }),
		{ api: {}, t, locale: 'nl' },
	)
	assert.match(empty, /<em>Geen berichten\.<\/em>/)
	assert.match(
		await renderComponent(inState(InboxPage, {}), { api: {}, t, locale: 'en' }),
		/role="status" aria-live="polite"><span aria-hidden="true">…<\/span><span class="sr-only">Loading…<\/span>/,
	)
})

test('the messages page opens the first thread and reloads it in the picked language', async () => {
	const calls = []
	let saveOk = true
	const api = {
		async fetchThreads() {
			return [
				{ id: 't1', kind: 'group', createdAt: '2026-09-01T08:00:00+02:00' },
				{ id: 't2' },
			]
		},
		async getDetails() {
			return { messageLanguage: 'ar' }
		},
		async fetchThreadMessages(id) {
			calls.push(['messages', id])
			return [
				{ id: 'x', senderRef: 'me', body: 'Hoi' },
				{ id: 'y', senderRef: 'school', body: 'Dag' },
			]
		},
		async setMessageLanguage(tag) {
			calls.push(['language', tag])
			return { ok: saveOk }
		},
	}
	const page = instance(MessagesPage, {
		api,
		t,
		locale: 'en',
		session: { subjectRef: 'me' },
	})
	await page.load()
	assert.equal(page.language, 'ar')
	assert.equal(page.activeId, 't1')
	assert.equal(page.isOwn(page.messages[0]), true)
	assert.equal(page.isOwn(page.messages[1]), false)

	await page.changeLanguage('tr')
	assert.equal(page.language, 'tr')
	assert.deepEqual(calls.slice(-2), [
		['language', 'tr'],
		['messages', 't1'],
	])

	saveOk = false
	await page.changeLanguage('pl')
	assert.equal(page.language, 'tr')
	assert.equal(page.error, 'Your language choice could not be saved.')

	const html = await renderComponent(
		inState(MessagesPage, {
			threads: [{ id: 't1', kind: 'group' }],
			activeId: 't1',
			messages: page.messages,
		}),
		{ api, t, locale: 'en', session: { subjectRef: 'me' } },
	)
	assert.match(
		html,
		/<nav class="pq-messages__threads" aria-label="Conversations">/,
	)
	// Each conversation is a Den Haag action row that opens it on this page
	// (site-mijn-omgeving-components REQ-SMO-004).
	assert.match(
		html,
		/<button class="denhaag-action denhaag-action--single pq-action-row__control" type="button" aria-current="true"><span class="denhaag-action__row"><span class="denhaag-action__content"><span class="pq-action-row__title">Group conversation<\/span>/,
	)

	const none = await renderComponent(inState(MessagesPage, { threads: [] }), {
		api,
		t,
		locale: 'nl',
	})
	assert.match(
		none,
		/data-testid="mijn-empty-state"><p class="utrecht-paragraph pq-empty-state__text">Nog geen gesprekken\.<\/p>/,
	)
	assert.match(html, /<strong class="pq-message__sender">You<\/strong>/)
	assert.match(html, /<strong class="pq-message__sender">School<\/strong>/)
})

test('the news page reads the feed and the archive again after a new language', async () => {
	const calls = []
	const api = {
		async getDetails() {
			return { messageLanguage: '' }
		},
		async fetchNewsFeed() {
			calls.push('feed')
			return [{ id: 'n1', title: 'Studiedag', body: 'Vrijdag dicht.' }]
		},
		async fetchNewsletterArchive() {
			calls.push('archive')
			return []
		},
		async setMessageLanguage() {
			return { ok: true }
		},
	}
	const page = instance(NewsPage, { api, t, locale: 'en' })
	await page.loadFeed()
	assert.equal(page.feed.length, 1)
	await page.changeLanguage('ar')
	assert.equal(page.language, 'ar')
	assert.deepEqual(calls, ['feed', 'archive', 'feed', 'archive'])

	const html = await renderComponent(inState(NewsPage, { feed: [] }), {
		api,
		t,
		locale: 'en',
	})
	assert.match(html, /No news yet\./)
})

test('the language picker has a visible label and a hint tied to the select', async () => {
	const html = await renderComponent(MessageLanguagePicker, {
		id: 'pick',
		label: 'Show messages in',
		hint: 'Translated by AI.',
		language: 'ar',
		error: 'Oops',
		t,
		locale: 'en',
	})
	assert.match(
		html,
		/<label class="utrecht-form-label" for="pick">Show messages in<\/label>/,
	)
	assert.match(
		html,
		/<select id="pick" class="utrecht-select" value="ar" aria-describedby="pick-hint">/,
	)
	assert.match(html, /<option value="">As written<\/option>/)
	assert.match(html, /<option value="ar">Arabic \(العربية\)<\/option>/)
	assert.match(
		html,
		/<p id="pick-hint" class="utrecht-paragraph pq-language-picker__hint">Translated by AI\.<\/p>/,
	)
	assert.match(html, /role="alert">Oops<\/p>/)
})

test('a news item says when it was published, and nothing while it has no date', async () => {
	const NewsItem = await loadSfc('src/site/components/inbox/NewsItem.vue')
	const item = {
		id: 'n1',
		title: 'Studiedag',
		body: 'Vrijdag dicht.',
		publishedAt: '2026-10-03T09:00:00+00:00',
	}
	const nl = await renderComponent(NewsItem, {
		item,
		t: withStrings(null, 'nl'),
		locale: 'nl',
	})
	assert.match(
		nl,
		/<p class="utrecht-paragraph pq-news__date"[^>]*>\s*Gepubliceerd op 3-10-2026\s*<\/p>/,
	)
	const en = await renderComponent(NewsItem, {
		item,
		t: withStrings(null, 'en'),
		locale: 'en',
	})
	assert.match(en, /Published on 03\/10\/2026/)
	const undated = await renderComponent(NewsItem, {
		item: { id: 'n2', title: 'Kort', body: 'Tekst' },
		t: withStrings(null, 'nl'),
		locale: 'nl',
	})
	assert.doesNotMatch(undated, /pq-news__date/)
})
