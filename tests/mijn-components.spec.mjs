#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// mijn-components.spec.mjs: the mijn omgeving action rows, data badges, empty
// and loading states, and the `tasks` and `inbox` blocks
// (site-mijn-omgeving-components wave 2: REQ-SMO-001, REQ-SMO-004,
// REQ-SMO-009, the tasks and inbox blocks of REQ-SMO-021).
//
// Usage:
//   node --test tests/mijn-components.spec.mjs

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { OPEN_STORAGE_KEY } from '../src/shared/openRecord.js'
import { blocks } from '../src/site/components/mijn/index.js'
import {
	deadlineBadge,
	inboxRows,
	mijnTranslator,
	receivedInWords,
	siteHref,
	taskRows,
} from '../src/site/components/mijn/rows.js'
import strings from '../src/site/components/mijn/strings.js'
import { collectionIdsFor } from '../src/site/pages/collections/collectionLoader.js'
import { resolveBlocks } from '../src/site/pages/collections/pageBlocks.js'
import { instance, inState } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const MIJN = join(ROOT, 'src', 'site', 'components', 'mijn')

const ActionRow = await loadSfc('src/site/components/mijn/ActionRow.vue')
const TasksBlock = await loadSfc('src/site/components/mijn/TasksBlock.vue')
const InboxBlock = await loadSfc('src/site/components/mijn/InboxBlock.vue')

/** Friday 2 October 2026, midday: the day the mockups were drawn. */
const TODAY = new Date(2026, 9, 2, 12, 0, 0)

const nl = mijnTranslator(null, 'nl')
const en = mijnTranslator(null, 'en')

/** The versions thematiq's Den Haag token mapping pins (thematiq#892). */
const PINNED = {
	'@gemeente-denhaag/action': '4.4.2',
	'@gemeente-denhaag/data-badge': '2.2.2',
}

const NAV = [
	{
		key: 'dossiq:zaken',
		label: 'Mijn zaken',
		contribution: { app: 'dossiq' },
		page: {
			id: 'zaken',
			blocks: [{ type: 'collection', collection: 'vragenAanU' }],
		},
	},
	{ key: '__inbox__', label: 'Berichten', special: 'inbox' },
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

test('a deadline badge says the date in words, counts down from seven days out, and says when it is late', () => {
	assert.deepEqual(deadlineBadge('2026-10-12T12:00:00', TODAY, nl, 'nl'), {
		text: 'Voor 12 oktober',
		state: 'neutral',
		datetime: '2026-10-12',
	})
	assert.equal(deadlineBadge('2026-10-09', TODAY, nl, 'nl').text, 'Nog 7 dagen')
	assert.equal(deadlineBadge('2026-10-09', TODAY, nl, 'nl').state, 'warning')
	assert.equal(
		deadlineBadge('2026-10-10', TODAY, nl, 'nl').text,
		'Voor 10 oktober',
	)
	assert.equal(deadlineBadge('2026-10-03', TODAY, nl, 'nl').text, 'Nog 1 dag')
	assert.equal(
		deadlineBadge('2026-10-02T23:00:00', TODAY, nl, 'nl').text,
		'Vandaag',
	)
	assert.deepEqual(deadlineBadge('2026-10-01', TODAY, nl, 'nl'), {
		text: 'Verlopen',
		state: 'error',
		datetime: '2026-10-01',
	})
	assert.equal(
		deadlineBadge('2027-01-15', TODAY, en, 'en').text,
		'Before 15 January 2027',
		'another year names the year',
	)
	assert.equal(deadlineBadge('', TODAY, nl), null)
	assert.equal(deadlineBadge('geen datum', TODAY, nl), null)
})

test('a tasks block lists the soonest deadline first, rows without one last, within its limit', () => {
	const rows = [
		{ id: 'a', onderwerp: 'Later', antwoordVoor: '2026-10-20' },
		{ id: 'b', onderwerp: 'Zonder datum' },
		{ id: 'c', onderwerp: 'Eerst', antwoordVoor: '2026-10-05' },
		{ id: 'd', onderwerp: 'Ook later', antwoordVoor: '2026-10-21' },
	]
	const block = {
		collection: 'vragenAanU',
		dueField: 'antwoordVoor',
		titleFields: ['onderwerp'],
		limit: 3,
	}
	assert.deepEqual(
		taskRows(rows, block, { label: 'Vragen' }).map((r) => [
			r.id,
			r.title,
			r.due,
		]),
		[
			['c', 'Eerst', '2026-10-05'],
			['a', 'Later', '2026-10-20'],
			['d', 'Ook later', '2026-10-21'],
		],
	)
	assert.equal(
		taskRows(rows, { collection: 'x' }, { label: 'Vragen' }).length,
		4,
		'no limit shows up to five',
	)
	assert.equal(
		taskRows([{ id: 'z' }], { collection: 'x' }, { label: 'Vragen' })[0].title,
		'Vragen',
		'a row without a title is named after its collection',
	)
})

test('an inbox block shows its own inbox, unread first, then newest first, within its limit', () => {
	const messages = [
		{
			id: 'old-unread',
			read: false,
			receivedAt: '2026-09-01T10:00:00Z',
			_source: { appId: 'dossiq', collection: 'berichten' },
		},
		{
			id: 'new-read',
			read: true,
			receivedAt: '2026-10-02T10:00:00Z',
			_source: { appId: 'dossiq', collection: 'berichten' },
		},
		{
			id: 'new-unread',
			read: false,
			receivedAt: '2026-10-01T10:00:00Z',
			_source: { appId: 'dossiq', collection: 'berichten' },
		},
		{
			id: 'elders',
			read: false,
			receivedAt: '2026-10-02T11:00:00Z',
			_source: { appId: 'learniq', collection: 'berichten' },
		},
	]
	assert.deepEqual(
		inboxRows(messages, { collection: 'berichten' }, 'dossiq').map((m) => m.id),
		['new-unread', 'old-unread', 'new-read'],
		"another app's inbox of the same name stays out",
	)
	assert.deepEqual(
		inboxRows(messages, { limit: 2 }, 'dossiq').map((m) => m.id),
		['elders', 'new-unread'],
		'without a collection every inbox',
	)
	assert.deepEqual(inboxRows(null, {}, 'dossiq'), [])
})

test('when a message came in reads as words', () => {
	assert.equal(
		receivedInWords(new Date(2026, 9, 2, 14, 20).toISOString(), TODAY, nl, 'nl'),
		'Vandaag om 14.20 uur',
	)
	assert.equal(
		receivedInWords(new Date(2026, 8, 30, 9, 5).toISOString(), TODAY, nl, 'nl'),
		'30 september om 09.05 uur',
	)
	assert.equal(receivedInWords('', TODAY, nl, 'nl'), '')
})

test('the words fall back to this folder, in both languages, with the same keys and no em-dash', () => {
	assert.deepEqual(Object.keys(strings.nl).sort(), Object.keys(strings.en).sort())
	for (const text of [
		...Object.values(strings.nl),
		...Object.values(strings.en),
	]) {
		assert.doesNotMatch(text, /—/)
	}
	assert.equal(nl('New'), 'Nieuw')
	assert.equal(nl('Loading'), 'Bezig met laden')
	assert.equal(
		mijnTranslator((key) => (key === 'New' ? 'Nieuw!' : key), 'nl')('New'),
		'Nieuw!',
		"the site's own translation wins",
	)
	assert.equal(siteHref(''), '')
	assert.equal(
		siteHref('/mijn/inbox'),
		'/mijn/inbox',
		'outside a browser the route',
	)
})

test('an action row is one link with the badge inside it; a row with nowhere to go is text', async () => {
	const link = await renderComponent(ActionRow, {
		title: 'Wij hebben een vraag over uw Woo-verzoek',
		meta: 'Vandaag om 14.20 uur',
		route: '/mijn/inbox',
		unread: true,
		badges: [{ text: 'Nieuw', state: 'success' }],
	})
	assert.match(
		link,
		/^<li class="pq-action-row" data-testid="mijn-action-row"><a class="denhaag-action denhaag-action--single pq-action-row__control pq-action-row--unread" href="\/mijn\/inbox">/,
	)
	assert.equal((link.match(/<a /g) || []).length, 1, 'one control per row')
	assert.match(
		link,
		/pq-action-row__title">Wij hebben een vraag over uw Woo-verzoek<\/span>.*nl-data-badge--success[^>]*>(<!--\[-->)?Nieuw(<!--\]-->)?<\/span>.*<\/a><\/li>$/,
		'a screen reader hears "Nieuw" with the link name',
	)
	assert.match(
		link,
		/<svg class="denhaag-action__link-icon[^"]*"[^>]*aria-hidden="true"/,
	)

	const text = await renderComponent(ActionRow, { title: 'Niets te openen' })
	assert.match(
		text,
		/<div class="denhaag-action denhaag-action--single pq-action-row__control">/,
	)
	assert.doesNotMatch(text, /<a |<button|<svg/)

	const badge = await renderComponent(ActionRow, {
		title: 'x',
		button: true,
		badges: [{ text: 'Rood?', state: 'purple' }],
	})
	assert.match(badge, /nl-data-badge--neutral/, 'an unknown state is neutral')

	const row = instance(ActionRow, { title: 'x', route: '/mijn/inbox' })
	let prevented = false
	row.onClick({ preventDefault: () => (prevented = true) })
	assert.equal(prevented, true)
	assert.deepEqual(row.emitted, [['open', '/mijn/inbox']])
	const newTab = instance(ActionRow, { title: 'x', route: '/mijn/inbox' })
	newTab.onClick({
		ctrlKey: true,
		preventDefault: () => assert.fail("a new tab is the browser's"),
	})
	assert.deepEqual(newTab.emitted, [])
})

test('a tasks block links each row to the page that shows it and keeps the row to open', async () => {
	const block = {
		type: 'tasks',
		collection: 'vragenAanU',
		dueField: 'antwoordVoor',
		titleFields: ['onderwerp'],
		label: 'Dit moet u nog doen',
	}
	const rows = [
		{
			id: 'v1',
			onderwerp: 'Beantwoord onze vraag over uw Woo-verzoek',
			antwoordVoor: '2026-10-12',
		},
	]
	const html = await renderComponent(TasksBlock, {
		block,
		collection: { id: 'vragenAanU' },
		rows,
		app: 'dossiq',
		nav: NAV,
		locale: 'nl',
		today: TODAY,
	})
	assert.match(
		html,
		/<h2 id="pq-tasks-vragenAanU" class="utrecht-heading-3">Dit moet u nog doen<\/h2>/,
	)
	assert.match(html, /aria-labelledby="pq-tasks-vragenAanU"/)
	assert.match(
		html,
		/<a class="denhaag-action[^"]*" href="\/mijn\/dossiq\/zaken">/,
	)
	assert.match(html, /Beantwoord onze vraag over uw Woo-verzoek/)
	assert.match(html, /<time datetime="2026-10-12">Voor 12 oktober<\/time>/)

	const storage = memoryStorage()
	globalThis.window = { sessionStorage: storage }
	try {
		const ctx = instance(TasksBlock, {
			block,
			rows,
			app: 'dossiq',
			nav: NAV,
			today: TODAY,
		})
		ctx.open(ctx.entries[0])
		assert.deepEqual(ctx.emitted, [['navigate', '/mijn/dossiq/zaken']])
		assert.deepEqual(JSON.parse(storage.getItem(OPEN_STORAGE_KEY)), {
			app: 'dossiq',
			collection: 'vragenAanU',
			id: 'v1',
		})
		const nowhere = instance(TasksBlock, {
			block,
			rows,
			app: 'dossiq',
			nav: [],
			today: TODAY,
		})
		assert.equal(nowhere.entries[0].route, '', 'no page shows it, so no link')
		nowhere.open(nowhere.entries[0])
		assert.deepEqual(nowhere.emitted, [])
	} finally {
		delete globalThis.window
	}

	const empty = await renderComponent(TasksBlock, {
		block,
		rows: [],
		locale: 'nl',
	})
	assert.match(empty, /U hoeft nu niets te doen\./)
	assert.doesNotMatch(empty, /<ul/)
	const loading = await renderComponent(TasksBlock, {
		block,
		rows: [],
		loading: true,
		locale: 'nl',
	})
	assert.match(loading, /<p class="sr-only" role="status">Bezig met laden<\/p>/)
})

test('an unread message carries "Nieuw" inside its link, and "Alle berichten" leads to the inbox', async () => {
	const messages = [
		{
			id: 'm1',
			subject: 'Ontvangstbevestiging van uw Woo-verzoek',
			read: true,
			receivedAt: new Date(2026, 9, 2, 14, 10).toISOString(),
			_source: { appId: 'dossiq', collection: 'berichten', label: 'Dossiq' },
		},
		{
			id: 'm2',
			subject: 'Wij hebben een vraag over uw Woo-verzoek',
			read: false,
			receivedAt: new Date(2026, 9, 2, 14, 20).toISOString(),
			recordLink: { app: 'dossiq', collection: 'vragenAanU', id: 'v1' },
			_source: { appId: 'dossiq', collection: 'berichten', label: 'Dossiq' },
		},
	]
	const html = await renderComponent(
		inState(InboxBlock, { messages, failed: false }),
		{
			block: { type: 'inbox', label: 'Nieuwe berichten' },
			app: 'dossiq',
			nav: NAV,
			locale: 'nl',
			today: TODAY,
		},
	)
	const rows = html.split('<li class="pq-action-row"').slice(1)
	assert.equal(rows.length, 2)
	assert.match(
		rows[0],
		/href="\/mijn\/dossiq\/zaken">.*Wij hebben een vraag over uw Woo-verzoek.*Dossiq, Vandaag om 14\.20 uur.*nl-data-badge--success[^>]*>(<!--\[-->)?Nieuw(<!--\]-->)?<\/span>.*<\/a>/,
		'unread first, opening the record it is about',
	)
	assert.match(
		rows[1],
		/href="\/mijn\/inbox">/,
		'a message about no shown record opens the inbox',
	)
	assert.doesNotMatch(rows[1], /Nieuw/)
	assert.match(
		html,
		/<a class="utrecht-link" href="\/mijn\/inbox">Alle berichten<\/a>/,
	)
})

test('no messages yet reads as a sentence; a failed read says so and is not empty', async () => {
	// REQ-SMO-009 scenario "No messages yet".
	const none = await renderComponent(
		inState(InboxBlock, { messages: [], failed: false }),
		{
			block: { type: 'inbox' },
			locale: 'nl',
		},
	)
	assert.match(none, /U heeft nog geen berichten\./)
	assert.doesNotMatch(none, /<ul|Alle berichten/)

	const ctx = instance(InboxBlock, {
		block: { type: 'inbox' },
		api: { fetchInbox: async () => null },
	})
	await ctx.load()
	assert.equal(ctx.failed, true)
	const failed = await renderComponent(
		inState(InboxBlock, { messages: [], failed: true }),
		{
			block: { type: 'inbox' },
			locale: 'nl',
		},
	)
	assert.match(
		failed,
		/role="alert">\s*Uw berichten konden niet worden geladen\.\s*</,
	)

	const loaded = instance(InboxBlock, {
		block: { type: 'inbox' },
		api: { fetchInbox: async () => [{ id: 'x', subject: 'Hallo' }] },
	})
	await loaded.load()
	assert.equal(loaded.failed, false)
	assert.equal(loaded.entries.length, 1)

	const loading = await renderComponent(
		inState(InboxBlock, { messages: null, failed: false }),
		{
			block: { type: 'inbox' },
			locale: 'nl',
		},
	)
	assert.match(loading, /<p class="sr-only" role="status">Bezig met laden<\/p>/)
})

test("a page renders its tasks and inbox blocks, and loads the tasks block's collection", () => {
	const contribution = {
		app: 'dossiq',
		collections: [{ id: 'vragenAanU' }, { id: 'berichten', kind: 'inbox' }],
	}
	const page = {
		id: 'overzicht',
		blocks: [
			{ type: 'tasks', collection: 'vragenAanU' },
			{ type: 'inbox', collection: 'berichten' },
			{ type: 'tasks', collection: 'weg' },
		],
	}
	assert.deepEqual(
		resolveBlocks(page, contribution).map((item) => [
			item.kind,
			item.collection?.id,
		]),
		[
			['tasks', 'vragenAanU'],
			['inbox', undefined],
			['none', undefined],
		],
	)
	assert.deepEqual(collectionIdsFor(page), ['vragenAanU', 'weg'])
	assert.equal(typeof blocks.tasks, 'function', 'loaded on demand')
	assert.equal(typeof blocks.inbox, 'function', 'loaded on demand')
})

test("the components take only the Den Haag CSS, at thematiq's pinned versions, and declare no tokens", () => {
	// REQ-SMO-001. The build half (no react in any chunk) runs after
	// `npm run build:site`, in scripts/check-site-chunks.js.
	const pkg = JSON.parse(readFileSync(join(ROOT, 'package.json'), 'utf8'))
	for (const [name, version] of Object.entries(PINNED)) {
		assert.equal(pkg.dependencies[name], version, `${name} is pinned exactly`)
	}
	const files = readdirSync(MIJN).filter((f) => /\.(vue|js)$/.test(f))
	const denhaag = []
	for (const file of files) {
		const source = readFileSync(join(MIJN, file), 'utf8')
		for (const match of source.matchAll(
			/['"](@gemeente-denhaag\/[^'"]+)['"]/g,
		)) {
			denhaag.push(match[1])
		}
		assert.doesNotMatch(
			source,
			/from\s+['"]@gemeente-denhaag|from\s+['"]react/,
			`${file} imports no Den Haag or React module`,
		)
		assert.doesNotMatch(
			source,
			/--(denhaag|nl-data-badge)-[a-z-]+\s*:/,
			`${file} declares no Den Haag token`,
		)
	}
	assert.deepEqual(denhaag.sort(), [
		'@gemeente-denhaag/action/index.css',
		'@gemeente-denhaag/data-badge/index.css',
	])
})
