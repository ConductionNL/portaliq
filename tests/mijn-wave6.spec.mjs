#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// mijn-wave6.spec.mjs: what the app lanes found after alignment
// (site-mijn-omgeving-components wave 6: REQ-SMO-010, REQ-SMO-024 to
// REQ-SMO-028): tasks that leave handed-in work out, an inbox about the
// chosen child, a switcher subtitle from a related record, cta tiles to a
// page or a route for the open record, a text filled from the record,
// cards with a progress figure, and a link that opens a record page.
//
// Usage:
//   node --test tests/mijn-wave6.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { navKeyFor, opensAsRecordPage } from '../src/shared/openRecord.js'
import { taskRows } from '../src/site/components/mijn/rows.js'
import { ctaLabel, fillTemplate } from '../src/site/components/mijn/template.js'
import {
	groupTiles,
	resolveBlocks,
} from '../src/site/pages/collections/pageBlocks.js'
import { recordRoute } from '../src/site/pages/inbox/inbox.js'
import { instance, t } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const RecordSwitcher = await loadSfc('src/site/components/mijn/RecordSwitcher.vue')
const ProgressCards = await loadSfc('src/site/components/mijn/ProgressCards.vue')
const InboxBlock = await loadSfc('src/site/components/mijn/InboxBlock.vue')
const ContributionPage = await loadSfc(
	'src/site/pages/collections/ContributionPage.vue',
)

const CHILDREN = [
	{ id: 'vera', givenName: 'Vera' },
	{ id: 'sami', givenName: 'Sami' },
]

test('handed-in work is not a task', () => {
	// REQ-SMO-025 scenario "Handed-in work is not a task".
	const block = {
		collection: 'assignments',
		titleFields: ['title'],
		lookups: [
			{
				collection: 'submissions',
				matchField: 'assignment',
				valueField: 'state',
				as: 'submission',
			},
		],
		excludeWhen: { lookup: 'submission', in: ['submitted', 'graded'] },
	}
	const rows = [
		{ id: 'a1', title: 'Werkstuk', submission: 'submitted' },
		{ id: 'a2', title: 'Spreekbeurt', submission: '' },
	]
	assert.deepEqual(
		taskRows(rows, block, {}).map((row) => row.id),
		['a2'],
	)
	assert.equal(
		taskRows(rows, { ...block, excludeWhen: undefined }, {}).length,
		2,
		'without excludeWhen every row',
	)
	assert.equal(
		taskRows(rows, { ...block, lookups: [] }, {}).length,
		2,
		'an excludeWhen without its lookup leaves nothing out',
	)
})

test('messages about the chosen child only', async () => {
	// REQ-SMO-025 scenario "Messages about the chosen child".
	const messages = [
		{ id: 'm1', subject: 'Over Vera', learnerRef: 'vera', read: false },
		{ id: 'm2', subject: 'Over Sami', learnerRef: 'sami', read: false },
	]
	const about = instance(InboxBlock, {
		block: { type: 'inbox', recordField: 'learnerRef' },
		record: CHILDREN[0],
		initialMessages: messages,
	})
	assert.deepEqual(
		about.entries.map((entry) => entry.title),
		['Over Vera'],
	)
	const none = instance(InboxBlock, {
		block: { type: 'inbox', recordField: 'learnerRef' },
		initialMessages: messages,
	})
	assert.deepEqual(none.entries, [], 'no open record, no messages about one')
	const all = instance(InboxBlock, {
		block: { type: 'inbox' },
		initialMessages: messages,
	})
	assert.equal(all.entries.length, 2)
})

test('Vera, Groep 6: the switcher subtitle from a related record', async () => {
	// REQ-SMO-026 scenario "Vera, Groep 6".
	const page = {
		id: 'overview',
		records: {
			collection: 'parentChildren',
			titleFields: ['givenName'],
			subtitleLookup: {
				collection: 'parentGroupMemberships',
				matchField: 'learnerRef',
				valueField: 'cohortName',
			},
		},
		blocks: [{ type: 'richText', markdown: 'x' }],
	}
	const contribution = {
		app: 'learniq',
		collections: [{ id: 'parentChildren' }, { id: 'parentGroupMemberships' }],
	}
	const ctx = instance(ContributionPage, {
		entry: { key: 'learniq:overview', page, contribution },
		t,
		initialData: {
			parentChildren: { loading: false, objects: CHILDREN },
			parentGroupMemberships: {
				loading: false,
				objects: [{ learnerRef: 'vera', cohortName: 'Groep 6' }],
			},
		},
	})
	assert.deepEqual(ctx.switcherSubtitles, { vera: 'Groep 6' })
	const html = await renderComponent(RecordSwitcher, {
		rows: CHILDREN,
		titleFields: ['givenName'],
		subtitles: ctx.switcherSubtitles,
		legend: 'Kies voor wie',
	})
	assert.match(
		html,
		/Vera<\/span><span class="pq-record-switcher__subtitle">Groep 6<\/span>/,
	)
	assert.doesNotMatch(
		html,
		/Sami<\/span><span class="pq-record-switcher__subtitle">/,
		'nothing found, no subtitle',
	)
})

test('a text filled from the record: plain text, words for an empty value, no sentence for an unprojected field', () => {
	// REQ-SMO-027 scenarios "Access without an end date" and "A value is not markup".
	assert.deepEqual(
		fillTemplate(
			'U heeft toegang tot {expiresAt}.',
			{ expiresAt: '' },
			{ whenEmpty: { expiresAt: 'U heeft toegang zonder einddatum.' } },
		),
		['U heeft toegang zonder einddatum.'],
	)
	assert.deepEqual(
		fillTemplate('U heeft toegang tot {expiresAt}.', {}),
		[],
		'no value, no words for it: the sentence goes',
	)
	assert.deepEqual(
		fillTemplate(
			'U heeft toegang tot {expiresAt}. Welkom.',
			{ expiresAt: '2026-11-01' },
			{ locale: 'nl' },
		),
		['U heeft toegang tot 1 november 2026.', 'Welkom.'],
	)
	assert.deepEqual(
		fillTemplate('Hallo {title}.', { title: '**Jan**' }),
		['Hallo **Jan**.'],
		'the asterisks stay text',
	)
	assert.deepEqual(
		fillTemplate(
			'Naam {name}. Geheim {bsn}.',
			{ name: 'Jan', bsn: '123' },
			{ fields: ['name'] },
		),
		['Naam Jan.'],
		'a placeholder on an unprojected field drops its sentence',
	)
	assert.equal(
		ctaLabel('{title} ziek of afwezig melden', 'Vera'),
		'Vera ziek of afwezig melden',
	)
	assert.equal(
		ctaLabel('{title} ziek of afwezig melden', ''),
		'ziek of afwezig melden',
	)
})

test('report Vera sick, and a tile to a page with Sami chosen', () => {
	// REQ-SMO-024 scenarios "Report Vera sick from the overview" (the label)
	// and "A tile to a page".
	const contribution = {
		app: 'learniq',
		collections: [{ id: 'parentChildren' }],
		actions: [{ id: 'createExcuseRequest', type: 'create' }],
	}
	const overview = {
		id: 'overview',
		records: { collection: 'parentChildren', titleFields: ['givenName'] },
		blocks: [
			{
				type: 'cta',
				action: 'createExcuseRequest',
				label: '{title} ziek of afwezig melden',
				withRecord: true,
			},
			{
				type: 'cta',
				page: 'parentGrades',
				label: 'Cijfers van {title}',
				withRecord: true,
			},
			{ type: 'cta', route: '/mijn/messages', label: 'Bericht sturen' },
		],
	}
	const grades = {
		id: 'parentGrades',
		record: { collection: 'parentChildren' },
		blocks: [{ type: 'richText', markdown: 'x' }],
	}
	const nav = [
		{ key: 'learniq:overview', page: overview, contribution },
		{ key: 'learniq:parentGrades', page: grades, contribution },
	]
	const ctx = instance(ContributionPage, {
		entry: nav[0],
		nav,
		t,
		initialData: { parentChildren: { loading: false, objects: CHILDREN } },
		initialSelected: { parentChildren: CHILDREN[1] },
	})
	const [cta, tiles] = ctx.blocks
	assert.equal(cta.kind, 'cta')
	assert.equal(ctx.withTitle(cta.block).label, 'Sami ziek of afwezig melden')
	assert.equal(tiles.kind, 'tiles')
	assert.deepEqual(
		ctx.tilesOf(tiles).map((tile) => [tile.label, tile.route]),
		[
			['Cijfers van Sami', '/mijn/learniq/parentGrades/sami'],
			['Bericht sturen', '/mijn/messages'],
		],
	)

	// 🔑 A ROUTE THAT NAMES THE RECORD MUST NOT ALSO KEEP IT IN STORAGE.
	// Measured on :8090: the tile's href was right and the address never
	// moved, because the shell reads a kept record back
	// (followAccountRoute -> openRecordEntry -> navKeyFor) and replaces the
	// route with the page that LISTS the collection — the page the tile is on.
	const kept = []
	const savedWindow = globalThis.window
	globalThis.window = {
		sessionStorage: {
			setItem: (key, value) => kept.push([key, value]),
			getItem: () => null,
			removeItem: () => {},
		},
	}
	try {
		const [toGrades, toMessages] = ctx.tilesOf(tiles)
		assert.equal(toGrades.carriesRecord, true, 'the route names the record')
		assert.equal(toMessages.carriesRecord, false)
		ctx.openTile(toGrades)
		assert.deepEqual(kept, [], 'so nothing is kept to open on arrival')
		assert.deepEqual(ctx.emitted, [
			['navigate', '/mijn/learniq/parentGrades/sami'],
		])

		// A page that shows the collection as a list cannot name the record in
		// its route, so there the record IS kept and the page selects it.
		const listing = {
			id: 'parentList',
			blocks: [{ type: 'detail', collection: 'parentChildren' }],
		}
		const listNav = [
			nav[0],
			{ key: 'learniq:parentList', page: listing, contribution },
		]
		const onList = instance(ContributionPage, {
			entry: listNav[0],
			nav: listNav,
			t,
			initialData: { parentChildren: { loading: false, objects: CHILDREN } },
			initialSelected: { parentChildren: CHILDREN[1] },
		})
		const toList = onList.tileTarget({
			type: 'cta',
			page: 'parentList',
			label: 'Lijst',
			withRecord: true,
		})
		assert.deepEqual(toList, {
			route: '/mijn/learniq/parentList',
			carriesRecord: false,
		})
		onList.openTile({ block: { withRecord: true }, route: toList.route, carriesRecord: false })
		assert.equal(kept.length, 1, 'there the record is kept')
		assert.match(kept[0][1], /"id":"sami"/)
	} finally {
		globalThis.window = savedWindow
	}
	assert.deepEqual(
		groupTiles([
			{ index: 0, kind: 'tile', block: 'a' },
			{ index: 1, kind: 'richText' },
			{ index: 2, kind: 'tile', block: 'b' },
		]).map((item) => item.kind),
		['tiles', 'richText', 'tiles'],
		'only consecutive tiles share a list',
	)
	assert.equal(
		resolveBlocks(
			{ blocks: [{ type: 'richText', template: 'x' }] },
			contribution,
		)[0].kind,
		'template',
	)
})

test("the trainer's students: 120 van 400 uur", async () => {
	// REQ-SMO-028 scenario "The trainer's students".
	const html = await renderComponent(ProgressCards, {
		rows: [
			{ id: 's1', name: 'Noa', hoursDone: 120, hoursRequired: 400 },
			{ id: 's2', name: 'Daan', hoursDone: 10 },
		],
		block: {
			display: 'cards',
			progress: {
				valueField: 'hoursDone',
				totalField: 'hoursRequired',
				label: 'uur',
			},
		},
		locale: 'nl',
	})
	const [noa, daan] = html.split('<li ').slice(1)
	assert.match(noa, /pq-progress-cards__title">Noa<\/p>/)
	assert.match(noa, /<p class="pq-progress-cards__figure">120 van 400 uur<\/p>/)
	assert.match(
		noa,
		/<span class="pq-progress-cards__bar" aria-hidden="true"><span style="inline-size:30%;">/,
	)
	assert.match(daan, /pq-progress-cards__title">Daan<\/p>/)
	assert.doesNotMatch(
		daan,
		/pq-progress-cards__figure|pq-progress-cards__bar/,
		'no total, no figure',
	)
})

test('a card over a schema with no name names itself from titleFields', async () => {
	// REQ-SMO-028 scenario "A card over a schema with no name, title or
	// givenName". Measured on learniq's workplace-trainer overview over
	// bpv-placement, 4 October 2026: the row was there and in scope, and the
	// card showed a bar and a number and nothing identifying, because the
	// renderer fell back to name/title/givenName and the schema has none.
	const rows = [
		{
			id: 'p1',
			trainingCompanyName: 'Installatiebedrijf Van Dam',
			hoursApprovedTotal: 312,
			agreedHours: 640,
		},
		{
			id: 'p2',
			trainingCompanyName: 'Zorgcentrum De Vaartoever',
			hoursApprovedTotal: 0,
			agreedHours: 640,
		},
	]
	const progress = {
		valueField: 'hoursApprovedTotal',
		totalField: 'agreedHours',
		label: 'uur',
	}

	const named = await renderComponent(ProgressCards, {
		rows,
		block: { display: 'cards', titleFields: ['trainingCompanyName'], progress },
		locale: 'nl',
	})
	const [first, second] = named.split('<li ').slice(1)
	assert.match(
		first,
		/pq-progress-cards__title">\s*Installatiebedrijf Van Dam\s*<\/p>/,
	)
	assert.match(first, /pq-progress-cards__figure">312 van 640 uur<\/p>/)
	assert.match(
		second,
		/pq-progress-cards__title">\s*Zorgcentrum De Vaartoever\s*<\/p>/,
	)

	// THE CONTROL: without the declaration the same rows name nothing, which
	// is the state this fixes. The card must then carry no empty heading
	// either, rather than an empty paragraph where a name should be.
	const unnamed = await renderComponent(ProgressCards, {
		rows,
		block: { display: 'cards', progress },
		locale: 'nl',
	})
	assert.doesNotMatch(unnamed, /Installatiebedrijf Van Dam/)
	assert.doesNotMatch(unnamed, /pq-progress-cards__title/)
	// The figure still renders, so the control differs in the title alone.
	assert.match(unnamed, /pq-progress-cards__figure">312 van 640 uur<\/p>/)

	// The collection's own titleFields stay the older fallback.
	const viaCollection = await renderComponent(ProgressCards, {
		rows,
		block: { display: 'cards', progress },
		titleFields: ['trainingCompanyName'],
		locale: 'nl',
	})
	assert.match(viaCollection, /Installatiebedrijf Van Dam/)
})

test('a message about a case opens the case page with that case chosen', () => {
	// REQ-SMO-010 scenario "A message about a case opens the case page".
	const contribution = { app: 'dossiq' }
	const casePage = {
		key: 'dossiq:mijnZaken',
		contribution,
		page: {
			id: 'mijnZaken',
			menu: false,
			record: { collection: 'mijnZaken' },
			blocks: [{ type: 'steps', collection: 'mijnZaken' }],
		},
	}
	const link = { app: 'dossiq', collection: 'mijnZaken', id: '2026-0003' }
	assert.equal(navKeyFor([casePage], link), 'dossiq:mijnZaken')
	assert.equal(opensAsRecordPage(casePage.page && casePage, 'mijnZaken'), true)
	assert.equal(recordRoute([casePage], link), '/mijn/dossiq/mijnZaken/2026-0003')

	const listPage = {
		key: 'dossiq:lijst',
		contribution,
		page: {
			id: 'lijst',
			blocks: [{ type: 'collection', collection: 'mijnZaken' }],
		},
	}
	assert.equal(
		navKeyFor([casePage, listPage], link),
		'dossiq:lijst',
		'a page with a list block keeps precedence',
	)
	assert.equal(recordRoute([casePage, listPage], link), '/mijn/dossiq/lijst')
	assert.equal(navKeyFor([casePage], { ...link, app: 'learniq' }), null)
})

test('the shell learns the mandates where the session loads', () => {
	// REQ-SMO-008: the acting-for bar names its party before Mijn zaken opened.
	const app = readFileSync(join(ROOT, 'src', 'site', 'App.vue'), 'utf8')
	const load = app.slice(
		app.indexOf('async loadAccount()'),
		app.indexOf('forgetAccount()'),
	)
	assert.match(load, /learnMandates\(await this\.api\.fetchMyCases\(\)/)
})
