#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// mijn-cases.spec.mjs: case cards, process steps and the cases and steps
// blocks (site-mijn-omgeving-components wave 3: REQ-SMO-001 to REQ-SMO-003,
// REQ-SMO-021, REQ-SMO-022), and the rule that a list that could not be
// loaded never reads as an empty one (REQ-SMO-009). Found live on :8090:
// "Mijn taken" said "U heeft geen open taken." while /portal/api/tasks
// answered 502 task-service-unreachable.
//
// Usage:
//   node --test tests/mijn-cases.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createPortalApi } from '../src/shared/portalApi.js'
import {
	caseCard,
	casesOnScreen,
	isClosedCase,
	stepPosition,
	turnSentence,
} from '../src/site/components/mijn/cases.js'
import { blocks } from '../src/site/components/mijn/index.js'
import { mijnTranslator } from '../src/site/components/mijn/rows.js'
import { collectionIdsFor } from '../src/site/pages/collections/collectionLoader.js'
import { resolveBlocks } from '../src/site/pages/collections/pageBlocks.js'
import { instance, inState, t } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const CaseCard = await loadSfc('src/site/components/mijn/CaseCard.vue')
const ProcessSteps = await loadSfc('src/site/components/mijn/ProcessSteps.vue')
const CasesBlock = await loadSfc('src/site/components/mijn/CasesBlock.vue')
const StepsBlock = await loadSfc('src/site/components/mijn/StepsBlock.vue')
const TasksBlock = await loadSfc('src/site/components/mijn/TasksBlock.vue')
const TasksPage = await loadSfc('src/site/pages/inbox/TasksPage.vue')
const MessagesPage = await loadSfc('src/site/pages/inbox/MessagesPage.vue')

/** Friday 2 October 2026, the day the mockups were drawn. */
const TODAY = new Date(2026, 9, 2, 12, 0, 0)
const nl = mijnTranslator(null, 'nl')

/** Dossiq's `mijnZaken`, as DossiqOverview.dc.html draws it. */
const ZAKEN = {
	id: 'mijnZaken',
	register: 'dossiq',
	schema: 'case',
	kind: 'cases',
	closedField: 'endDate',
	dueField: 'answerBy',
	turnField: 'portalTurn',
	steps: { label: 'Waar staat uw aanvraag?', provider: 'caseSteps' },
	fieldConfigs: {
		portalTurn: {
			valueLabels: {
				resident: 'U bent aan zet',
				organisation: 'De gemeente is aan zet',
			},
		},
	},
}

const STEPS = [
	{ label: 'Ontvangen', state: 'done', date: '2026-10-02' },
	{
		label: 'In behandeling',
		state: 'current',
		description: 'Wij zoeken de documenten.',
	},
	{ label: 'Besluit', state: 'todo' },
	{ label: 'Afgerond', state: 'todo' },
]

const WOO = {
	id: 'z-3',
	title: 'Woo-verzoek bomenkap Lindelaan',
	statusLabel: 'In behandeling',
	identifier: '2026-0003',
	answerBy: '2026-10-30',
	portalTurn: 'resident',
}

const NAV = [
	{
		key: 'dossiq:zaken',
		label: 'Mijn zaken',
		contribution: { app: 'dossiq' },
		page: {
			id: 'zaken',
			blocks: [{ type: 'collection', collection: 'mijnZaken' }],
		},
	},
]

test('a running Woo request reads its status, reference, step, answer date and whose turn', () => {
	// REQ-SMO-002 scenario "A running Woo request".
	const card = caseCard(WOO, ZAKEN, {
		tr: nl,
		locale: 'nl',
		today: TODAY,
		steps: STEPS,
	})
	assert.equal(card.title, 'Woo-verzoek bomenkap Lindelaan')
	assert.equal(card.status, 'In behandeling')
	assert.equal(card.reference, 'Zaak 2026-0003')
	assert.equal(card.position.text, 'Stap 2 van 4')
	assert.equal(card.due, 'Antwoord uiterlijk 30 oktober')
	assert.equal(card.turn, 'U bent aan zet')
	assert.equal(card.closed, false)
})

test('a case without progress data shows title, status and reference, and no empty bar', async () => {
	// REQ-SMO-002 scenario "A case without progress data".
	const bare = {
		id: 'z-4',
		title: 'Speeltuin Parkweg',
		statusLabel: 'Ontvangen',
		identifier: '2026-0004',
	}
	const card = caseCard(
		bare,
		{ id: 'x', kind: 'cases' },
		{ tr: nl, locale: 'nl', today: TODAY },
	)
	assert.deepEqual([card.position, card.due, card.turn], [null, '', ''])
	const html = await renderComponent(CaseCard, { card })
	assert.match(html, /Speeltuin Parkweg/)
	assert.match(html, /Ontvangen/)
	assert.match(html, /Zaak 2026-0004/)
	assert.doesNotMatch(html, /pq-case-card__bar|pq-case-card__progress/)
})

test('a case card is one link whose name starts with the title, with the status in words', async () => {
	const card = caseCard(WOO, ZAKEN, {
		tr: nl,
		locale: 'nl',
		today: TODAY,
		steps: STEPS,
	})
	const html = await renderComponent(CaseCard, {
		card,
		route: '/mijn/dossiq/zaken',
	})
	assert.equal((html.match(/<a /g) || []).length, 1)
	assert.match(
		html,
		/<p class="denhaag-case-card__title pq-case-card__title"><a class="pq-case-card__link" href="\/mijn\/dossiq\/zaken">Woo-verzoek bomenkap Lindelaan<\/a><\/p>/,
		'the link is the title, so its name is the case title',
	)
	// Den Haag's default card: its own background element, not the flat
	// list form (live finding on wave 3).
	assert.match(
		html,
		/<div class="denhaag-case-card pq-case-card"><div class="denhaag-case-card__wrapper"><span class="denhaag-case-card__background" aria-hidden="true">/,
	)
	assert.doesNotMatch(html, /denhaag-case-card--list/)
	assert.match(
		html,
		/nl-data-badge[^>]*>(<!--\[-->)?In behandeling(<!--\]-->)?<\/span>/,
		'status as text',
	)
	assert.match(html, /Stap 2 van 4.*Antwoord uiterlijk 30 oktober/)
	assert.match(
		html,
		/class="pq-case-card__bar" aria-hidden="true"><span style="inline-size:50%;">/,
	)
	assert.match(html, /U bent aan zet/)
})

test('where a case stands, whose turn it is, and which cases are closed', () => {
	assert.deepEqual(stepPosition(STEPS), { current: 2, total: 4 })
	assert.deepEqual(stepPosition([{ state: 'done' }, { state: 'todo' }]), {
		current: 2,
		total: 2,
	})
	assert.deepEqual(stepPosition([{ state: 'done' }, { state: 'done' }]), {
		current: 2,
		total: 2,
	})
	assert.equal(stepPosition([]), null)
	assert.equal(stepPosition(null), null)
	assert.equal(
		turnSentence({ portalTurn: 'resident' }, { turnField: 'portalTurn' }),
		'',
		'a code is not a sentence',
	)
	assert.equal(turnSentence({ portalTurn: 'nobody' }, ZAKEN), '')
	assert.equal(isClosedCase({ endDate: '2026-09-01' }, ZAKEN), true)
	assert.equal(isClosedCase({ endDate: '' }, ZAKEN), false)
	assert.equal(
		isClosedCase({ _closed: false, endDate: 'x' }, ZAKEN),
		false,
		'the server mark wins',
	)
	const rows = [
		{ id: 1 },
		{ id: 2, endDate: '2026-01-01' },
		{ id: 3 },
		{ id: 4 },
		{ id: 5 },
		{ id: 6 },
	]
	assert.deepEqual(casesOnScreen(rows, { open: true }, ZAKEN), {
		rows: [{ id: 1 }, { id: 3 }, { id: 4 }, { id: 5 }],
		more: true,
	})
	assert.equal(casesOnScreen(rows, { limit: 10 }, ZAKEN).rows.length, 6)
})

test('process steps are an ordered list; the current step is marked, a done step says so', async () => {
	// REQ-SMO-003 scenario "The case page of DossiqCase.dc.html".
	const html = await renderComponent(ProcessSteps, {
		steps: STEPS,
		tr: nl,
		locale: 'nl',
		today: TODAY,
	})
	assert.match(html, /^<ol class="denhaag-process-steps pq-process-steps"/)
	const items = html.split('<li ').slice(1)
	assert.equal(items.length, 4)
	assert.deepEqual(
		items.map((item) => /<\/span>([^<]+)<\/p>/.exec(item)?.[1]),
		['Ontvangen', 'In behandeling', 'Besluit', 'Afgerond'],
		'in the order given',
	)
	assert.match(
		items[1],
		/^class="denhaag-process-steps__step" aria-current="step"/,
	)
	assert.doesNotMatch(items[0] + items[2], /aria-current/)
	assert.match(items[0], /<span class="sr-only">Gereed: <\/span>Ontvangen/)
	assert.match(items[0], /<time datetime="2026-10-02">2 oktober<\/time>/)
	assert.match(items[1], /Wij zoeken de documenten\./)
	assert.match(
		items[0],
		/<span class="[^"]*denhaag-step-marker--checked[^"]*" aria-hidden="true">/,
	)
	assert.match(
		items[1],
		/<span class="[^"]*denhaag-step-marker--current[^"]*" aria-hidden="true">(<!--\[-->)?2(<!--\]-->)?<\/span>/,
	)
})

test('a cases block shows open cases as cards and asks for steps only for the cards on screen', async () => {
	const rows = [
		WOO,
		{ id: 'z-old', title: 'Oud', endDate: '2026-01-01' },
		...[5, 6, 7, 8].map((n) => ({ id: `z-${n}`, title: `Zaak ${n}` })),
	]
	const asked = []
	const api = {
		fetchSteps: async (collection, id) => {
			asked.push([collection.id, id])
			return { label: '', steps: STEPS }
		},
	}
	const block = {
		type: 'cases',
		collection: 'mijnZaken',
		open: true,
		label: 'Lopende zaken',
	}
	const ctx = instance(CasesBlock, {
		block,
		collection: ZAKEN,
		rows,
		api,
		app: 'dossiq',
		nav: NAV,
		today: TODAY,
	})
	await ctx.loadSteps()
	assert.deepEqual(
		asked.map(([, id]) => id),
		['z-3', 'z-5', 'z-6', 'z-7'],
		'four on screen, the closed one left out',
	)
	await ctx.loadSteps()
	assert.equal(asked.length, 4, 'a card is asked once')
	assert.equal(ctx.entries[0].card.position.text, 'Stap 2 van 4')
	assert.equal(ctx.entries[0].route, '/mijn/dossiq/zaken')

	const html = await renderComponent(
		inState(CasesBlock, { steps: { 'z-3': { steps: STEPS } } }),
		{
			block,
			collection: ZAKEN,
			rows,
			app: 'dossiq',
			nav: NAV,
			locale: 'nl',
			today: TODAY,
		},
	)
	assert.equal((html.match(/data-testid="mijn-case-card"/g) || []).length, 4)
	assert.match(
		html,
		/<a class="utrecht-link" href="\/mijn\/dossiq\/zaken">Alle zaken<\/a>/,
	)
	assert.doesNotMatch(html, />Oud</)

	const noProvider = instance(CasesBlock, {
		block,
		collection: { ...ZAKEN, steps: undefined },
		rows,
		api,
	})
	await noProvider.loadSteps()
	assert.equal(asked.length, 4, 'no steps provider, no reads')
})

test('a failed list says so with a way to try again, never as empty', async () => {
	// REQ-SMO-009, for every list this change builds.
	const cases = await renderComponent(CasesBlock, {
		block: { type: 'cases', collection: 'mijnZaken' },
		collection: ZAKEN,
		rows: [],
		failed: true,
		locale: 'nl',
	})
	assert.match(cases, /data-testid="mijn-load-error"/)
	assert.match(cases, /Uw zaken konden niet worden geladen\./)
	assert.doesNotMatch(cases, /U heeft nog geen zaken/)

	const tasks = await renderComponent(TasksBlock, {
		block: { type: 'tasks', collection: 'vragenAanU' },
		rows: [],
		failed: true,
		locale: 'nl',
	})
	assert.match(tasks, /Wat u nog moet doen kon niet worden geladen\./)
	assert.doesNotMatch(tasks, /U hoeft nu niets te doen/)

	const retried = instance(CasesBlock, {
		block: { type: 'cases', collection: 'x' },
	})
	retried.$emit('retry')
	assert.deepEqual(retried.emitted, [['retry', undefined]])

	const steps = instance(StepsBlock, {
		block: { type: 'steps', collection: 'mijnZaken' },
		collection: ZAKEN,
		record: { id: 'z-3' },
		api: { fetchSteps: async () => null },
	})
	await steps.load()
	assert.equal(steps.failed, true)
	const stepsHtml = await renderComponent(
		inState(StepsBlock, { answer: null, failed: true }),
		{
			block: { type: 'steps', collection: 'mijnZaken' },
			collection: ZAKEN,
			record: { id: 'z-3' },
			locale: 'nl',
		},
	)
	assert.match(stepsHtml, /Waar uw zaak staat kon niet worden geladen\./)
	assert.doesNotMatch(stepsHtml, /Er zijn nog geen stappen/)
})

test('My tasks: a 502 or a rejected read is an alert with "Opnieuw proberen", not "no open tasks"', async () => {
	// The defect found live: task-service-unreachable read as an empty list.
	globalThis.window = {
		localStorage: { getItem: () => 'token-1', setItem() {}, removeItem() {} },
	}
	globalThis.fetch = async () => ({
		ok: false,
		status: 502,
		json: async () => ({ code: 'task-service-unreachable' }),
	})
	try {
		const api = createPortalApi({ apiBase: '/api' })
		assert.deepEqual(await api.fetchTasks(), {
			results: [],
			total: 0,
			failed: true,
		})
		assert.equal(await api.fetchThreads({ orNull: true }), null)
		assert.deepEqual(await api.fetchThreads(), [], 'without orNull as before')
		assert.equal(
			await api.fetchCollection(
				{ id: 'a', register: 'r', schema: 's' },
				{ orNull: true },
			),
			null,
		)
		assert.deepEqual(
			await api.fetchCollection({ id: 'a', register: 'r', schema: 's' }),
			[],
			'without orNull as before',
		)

		const page = instance(TasksPage, { api, t, locale: 'nl' })
		await page.loadList()
		assert.equal(page.failed, true)
		assert.equal(page.loading, false)
	} finally {
		delete globalThis.window
		delete globalThis.fetch
	}

	const rejecting = instance(TasksPage, {
		api: {
			fetchTasks: async () => {
				throw new TypeError('Failed to fetch')
			},
		},
		t,
		locale: 'nl',
	})
	await rejecting.loadList()
	assert.equal(rejecting.failed, true, 'a rejected read is a failed read')
	assert.equal(rejecting.loading, false, 'and the page is not stuck loading')

	const html = await renderComponent(
		inState(TasksPage, { loading: false, failed: true, tasks: [] }),
		{
			api: {},
			t,
			locale: 'nl',
		},
	)
	assert.match(html, /role="alert" data-testid="mijn-load-error"/)
	assert.match(html, /Uw taken konden niet worden geladen\./)
	assert.match(html, /data-testid="mijn-load-error-retry">Opnieuw proberen</)
	assert.doesNotMatch(html, /U heeft geen open taken/)

	const ok = instance(TasksPage, {
		api: { fetchTasks: async () => ({ results: [], total: 0 }) },
		t,
		locale: 'nl',
	})
	await ok.loadList()
	assert.equal(ok.failed, false, 'an empty answer is an empty list')
})

test('the messages page: a failed read is an alert, not "no conversations"', async () => {
	const page = instance(MessagesPage, {
		api: {
			fetchThreads: async (options) => (options?.orNull ? null : []),
			getDetails: async () => ({}),
		},
		t,
		locale: 'nl',
	})
	await page.load()
	assert.equal(page.failed, true)
	assert.equal(page.threads, null)
	const html = await renderComponent(
		inState(MessagesPage, { threads: null, failed: true }),
		{
			api: {},
			t,
			locale: 'nl',
		},
	)
	assert.match(html, /Uw gesprekken konden niet worden geladen\./)
	assert.doesNotMatch(html, /Nog geen gesprekken/)
})

test('a page renders its cases and steps blocks and loads the cases collection', () => {
	const contribution = { app: 'dossiq', collections: [ZAKEN] }
	const page = {
		id: 'overzicht',
		blocks: [
			{ type: 'cases', collection: 'mijnZaken' },
			{ type: 'steps', collection: 'mijnZaken' },
			{ type: 'cases', collection: 'weg' },
		],
	}
	assert.deepEqual(
		resolveBlocks(page, contribution).map((item) => [
			item.kind,
			item.collection?.id,
		]),
		[
			['cases', 'mijnZaken'],
			['steps', 'mijnZaken'],
			['none', undefined],
		],
	)
	assert.deepEqual(collectionIdsFor(page), ['mijnZaken', 'weg'])
	assert.equal(typeof blocks.cases, 'function')
	assert.equal(typeof blocks.steps, 'function')
})
