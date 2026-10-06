#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// zuiddrecht-resident-boards.spec.mjs: the displays a contribution and a
// portal declare to draw the Mijn Zuiddrecht boards
// (zuiddrecht-resident-pages-match-the-boards), the route of every control
// those displays move, and the control: a block, page or menu that declares
// none of the keys renders the markup it had.
//
// Usage:
//   node --test tests/zuiddrecht-resident-boards.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { ACCOUNT_ROUTE, buildNav, shellSections } from '../src/shared/portalNav.js'
import { caseCard } from '../src/site/components/mijn/cases.js'
import { mijnTranslator } from '../src/site/components/mijn/rows.js'
import { accountCrumbs } from '../src/site/lib/accountArea.js'
import { residentMenuGroups } from '../src/site/lib/residentMenu.js'
import { pageOwnsHeading } from '../src/site/pages/registry.js'
import { loadSfc, renderComponent, renderSfc } from './support/render-sfc.mjs'

const bundle = (locale) =>
	JSON.parse(
		readFileSync(
			new URL(`../src/shared/i18n/${locale}.json`, import.meta.url),
			'utf8',
		),
	)
const translator = (locale) => {
	const strings = bundle(locale)
	// A key the bundle lacks is handed back as it is, placeholders and all,
	// so the mijn components fall back to their own strings.
	return (key, vars = {}) =>
		(strings[key] || key).replace(/\{(\w+)\}/g, (match, name) =>
			name in vars ? String(vars[name]) : match,
		)
}
const nl = translator('nl')
const mijn = mijnTranslator(null, 'nl')
const href = (route) => `/apps/portaliq/site?route=${encodeURIComponent(route)}`

/** Friday 2 October 2026, the day the boards were drawn. */
const TODAY = new Date(2026, 9, 2, 12, 0, 0)

const CaseCard = await loadSfc('src/site/components/mijn/CaseCard.vue')
const CasesBlock = await loadSfc('src/site/components/mijn/CasesBlock.vue')
const TasksBlock = await loadSfc('src/site/components/mijn/TasksBlock.vue')
const InboxBlock = await loadSfc('src/site/components/mijn/InboxBlock.vue')
const DocumentsBlock = await loadSfc('src/site/components/mijn/DocumentsBlock.vue')
const DetailCard = await loadSfc('src/site/components/collections/DetailCard.vue')
const CitizenCase = await loadSfc('src/site/components/e/CitizenCase.vue')

/** Dossiq's `mijnZaken`, with the turn words as the MijnOverzicht board reads them. */
const ZAKEN = {
	id: 'mijnZaken',
	register: 'dossiq',
	schema: 'case',
	kind: 'cases',
	label: 'Mijn zaken',
	closedField: 'isFinalStatus',
	dueField: 'deadline',
	turnField: 'portalTurn',
	fieldConfigs: {
		portalTurn: {
			valueLabels: { applicant: 'Wacht op u', us: 'De gemeente is aan zet' },
		},
	},
	documents: { label: 'Documenten', provider: 'caseDocuments' },
	timeline: { label: 'Wat er is gebeurd', provider: 'caseTimeline' },
	detail: { fields: ['identifier', 'title'] },
	columns: [
		{ field: 'identifier', label: 'Zaaknummer' },
		{ field: 'title', label: 'Onderwerp' },
	],
}

const STEPS = [
	{ label: 'Ontvangen', state: 'done', date: '2026-10-03' },
	{ label: 'In behandeling', state: 'current' },
	{ label: 'Besluit', state: 'todo' },
	{ label: 'Besluit genomen', state: 'todo' },
	{ label: 'Afgehandeld', state: 'todo' },
]

const WOO = {
	id: 'c-82',
	identifier: '2026-0082',
	title: 'Verlichting fietspad Lindelaan',
	statusPublicLabel: 'Ontvangen',
	deadline: '2026-11-01',
	portalTurn: 'us',
}

const PARKEREN = {
	id: 'c-61',
	identifier: '2026-0061',
	title: 'Parkeervergunning binnenstad',
	statusPublicLabel: 'In behandeling',
	deadline: '2026-10-25',
	portalTurn: 'applicant',
}

const NAV = [
	{
		key: 'dossiq:mijnZaken',
		label: 'Uw zaak',
		contribution: { app: 'dossiq' },
		page: {
			id: 'mijnZaken',
			record: { collection: 'mijnZaken', titleFields: ['title'] },
			blocks: [{ type: 'collection', collection: 'mijnZaken' }],
		},
	},
]

test('a compact case card: the number left, the tag right, the title a link, one line under the bar', async () => {
	const card = caseCard(WOO, ZAKEN, {
		tr: mijn,
		locale: 'nl',
		today: TODAY,
		steps: STEPS,
		yourTurn: ['applicant'],
	})
	assert.equal(card.number, '2026-0082')
	assert.equal(card.readyBy, 'klaar uiterlijk 1 november')
	assert.equal(card.yourTurn, false)
	const html = await renderComponent(CaseCard, {
		card,
		route: '/mijn/dossiq/mijnZaken/c-82',
		display: 'compact',
	})
	assert.match(html, /pq-case-card--compact/)
	assert.match(html, /pq-case-card__number[^>]*>2026-0082</)
	assert.doesNotMatch(html, /Zaak 2026-0082/)
	// The status in the info tone, since nothing is asked of the resident.
	assert.match(html, /nl-data-badge--info[^>]*>(<!--\[-->)?Ontvangen/)
	assert.match(
		html,
		/pq-case-card__link" href="\/mijn\/dossiq\/mijnZaken\/c-82">Verlichting fietspad Lindelaan<\/a>/,
	)
	assert.match(html, /pq-case-card__bar--board/)
	assert.match(
		html,
		/pq-case-card__line[^>]*>Stap 2 van 5 · klaar uiterlijk 1 november</,
	)
	assert.doesNotMatch(html, /denhaag-case-card/)
})

test('a compact card reads "Wacht op u" in the warning tone when the resident must act', async () => {
	const card = caseCard(PARKEREN, ZAKEN, {
		tr: mijn,
		locale: 'nl',
		today: TODAY,
		yourTurn: ['applicant'],
	})
	assert.equal(card.yourTurn, true)
	const html = await renderComponent(CaseCard, { card, display: 'compact' })
	assert.match(html, /nl-data-badge--warning[^>]*>(<!--\[-->)?Wacht op u/)
	assert.doesNotMatch(html, /In behandeling/)
	// Without the block naming the turn values, the status stays.
	const plain = caseCard(PARKEREN, ZAKEN, { tr: mijn, locale: 'nl', today: TODAY })
	assert.equal(plain.yourTurn, false)
})

test('a row: the number over the title, the tag, "Uiterlijk klaar op" and the day', async () => {
	const card = caseCard(WOO, ZAKEN, { tr: mijn, locale: 'nl', today: TODAY })
	const html = await renderComponent(CaseCard, {
		card,
		route: '/mijn/x',
		display: 'row',
		dueLabel: 'Uiterlijk klaar op',
	})
	assert.match(html, /pq-case-card--row/)
	assert.match(html, /pq-case-card__due-label[^>]*>Uiterlijk klaar op</)
	assert.match(html, /<strong>1 november<\/strong>/)
	assert.equal((html.match(/<a /g) || []).length, 1)
})

test('the control: a card without a display is the Den Haag folder card it was', async () => {
	const card = caseCard(WOO, ZAKEN, {
		tr: mijn,
		locale: 'nl',
		today: TODAY,
		steps: STEPS,
	})
	const html = await renderComponent(CaseCard, { card, route: '/mijn/x' })
	assert.match(
		html,
		/<div class="denhaag-case-card pq-case-card"><div class="denhaag-case-card__wrapper"><span class="denhaag-case-card__background" aria-hidden="true">/,
	)
	assert.doesNotMatch(
		html,
		/pq-case-card--board|pq-case-card--compact|pq-case-card--row|nl-data-badge--info/,
	)
	assert.match(html, /Zaak 2026-0082/)
	// The step line above the bar, as before.
	assert.ok(html.indexOf('Stap 2 van 5') < html.indexOf('pq-case-card__bar'))
})

test('a cases block with showAll puts "Alle zaken" beside its heading; without it, as before', async () => {
	const base = {
		collection: ZAKEN,
		rows: [WOO, PARKEREN],
		app: 'dossiq',
		nav: NAV,
		locale: 'nl',
		today: TODAY,
		initialSteps: {},
	}
	const compact = await renderComponent(CasesBlock, {
		...base,
		block: {
			type: 'cases',
			collection: 'mijnZaken',
			display: 'compact',
			showAll: true,
			label: 'Lopende zaken',
			yourTurn: ['applicant'],
		},
	})
	assert.match(compact, /pq-cases-block__head/)
	assert.match(compact, /data-testid="mijn-cases-all"[^>]*>Alle zaken</)
	assert.match(compact, /pq-cases-block__list--compact/)
	assert.match(compact, /Wacht op u/)
	assert.equal((compact.match(/Alle zaken/g) || []).length, 1)
	const plain = await renderComponent(CasesBlock, {
		...base,
		block: { type: 'cases', collection: 'mijnZaken', label: 'Lopende zaken' },
	})
	assert.doesNotMatch(
		plain,
		/pq-cases-block__head|mijn-cases-all|pq-cases-block__list--compact|Alle zaken/,
	)
	assert.match(plain, /denhaag-case-card/)
})

test('a highlight with a tone and the due day in its line; without them, as before', async () => {
	const base = {
		collection: {
			id: 'vragenAanU',
			fields: ['summary', 'hersteltermijn', 'caseTitle'],
		},
		rows: [
			{
				id: 'q1',
				summary: 'Stuur een kopie van uw ID-bewijs',
				caseTitle: 'Voor uw aanvraag parkeervergunning',
				hersteltermijn: '2026-10-18',
			},
		],
		app: 'dossiq',
		nav: [],
		locale: 'nl',
		today: TODAY,
	}
	const toned = await renderComponent(TasksBlock, {
		...base,
		block: {
			type: 'tasks',
			collection: 'vragenAanU',
			display: 'highlight',
			tone: 'warning',
			dueInLine: true,
			dueField: 'hersteltermijn',
			titleFields: ['summary'],
			subtitleFields: ['caseTitle'],
			buttonLabel: 'Document toevoegen',
			label: 'Wat u nog moet doen',
		},
	})
	assert.match(toned, /pq-tasks-block__highlight--warning/)
	assert.match(
		toned,
		/pq-tasks-block__highlight-title[^>]*>Stuur een kopie van uw ID-bewijs</,
	)
	assert.match(toned, /Voor uw aanvraag parkeervergunning, uiterlijk 18 oktober/)
	const plain = await renderComponent(TasksBlock, {
		...base,
		block: {
			type: 'tasks',
			collection: 'vragenAanU',
			display: 'highlight',
			dueField: 'hersteltermijn',
			titleFields: ['summary'],
			subtitleFields: ['caseTitle'],
		},
	})
	assert.doesNotMatch(plain, /pq-tasks-block__highlight--|uiterlijk 18 oktober/)
	assert.match(plain, /Voor uw aanvraag parkeervergunning</)
})

test('an inbox block as a plain list: a title and "vandaag", no badge, no chevron; without it, action rows', async () => {
	const messages = [
		{
			id: 'm1',
			subject: 'Het besluit op uw Woo-verzoek is gepubliceerd',
			receivedAt: '2026-10-02T09:00:00',
			read: false,
		},
		{
			id: 'm2',
			subject: 'Uw vraag is beantwoord',
			receivedAt: '2026-10-01T10:00:00',
			read: true,
		},
		{
			id: 'm3',
			subject: 'Wij hebben uw aanvraag ontvangen',
			receivedAt: '2026-09-28T11:00:00',
			read: true,
		},
	]
	const list = await renderComponent(InboxBlock, {
		block: { type: 'inbox', display: 'list', label: 'Nieuwe berichten' },
		initialMessages: messages,
		locale: 'nl',
		today: TODAY,
	})
	assert.match(list, /data-testid="mijn-inbox-all"[^>]*>Alle berichten</)
	assert.equal((list.match(/mijn-inbox-plain-row/g) || []).length, 3)
	assert.match(
		list,
		/Het besluit op uw Woo-verzoek is gepubliceerd<\/a>.{0,80}pq-inbox-block__plain-day[^>]*>vandaag</,
	)
	assert.match(list, /pq-inbox-block__plain-day[^>]*>gisteren</)
	assert.match(list, /pq-inbox-block__plain-day[^>]*>28 september</)
	assert.doesNotMatch(list, /denhaag-action|nl-data-badge|pq-action-row__chevron/)
	const rows = await renderComponent(InboxBlock, {
		block: { type: 'inbox', label: 'Nieuwe berichten' },
		initialMessages: messages,
		locale: 'nl',
		today: TODAY,
	})
	assert.match(rows, /denhaag-action/)
	assert.doesNotMatch(rows, /pq-inbox-block__plain|mijn-inbox-all/)
})

test('a documents block with upload offers "Document toevoegen" beside its heading; without it, not', async () => {
	const base = {
		collection: ZAKEN,
		record: { id: 'c-82' },
		api: {},
		locale: 'nl',
		initialAnswer: { documents: [] },
	}
	const upload = await renderComponent(DocumentsBlock, {
		...base,
		block: {
			type: 'documents',
			collection: 'mijnZaken',
			upload: true,
			label: 'Stukken',
		},
	})
	assert.match(upload, /pq-documents-block__head/)
	assert.match(upload, /data-testid="mijn-documents-upload"/)
	assert.match(upload, /Document toevoegen/)
	const plain = await renderComponent(DocumentsBlock, {
		...base,
		block: { type: 'documents', collection: 'mijnZaken' },
	})
	assert.doesNotMatch(
		plain,
		/pq-documents-block__head|mijn-documents-upload|Document toevoegen/,
	)
})

test('a detail card with a label heads its facts and may leave its history out', async () => {
	const base = { collection: ZAKEN, row: WOO, t: nl, locale: 'nl', level: 2 }
	const labelled = await renderComponent(DetailCard, {
		...base,
		label: 'Gegevens',
		showTimeline: false,
	})
	assert.match(
		labelled,
		/<h2 class="utrecht-heading-3 pq-detail__label" data-testid="detail-card-label">Gegevens<\/h2>/,
	)
	assert.doesNotMatch(labelled, /Wat er is gebeurd/)
	const plain = await renderComponent(DetailCard, base)
	assert.doesNotMatch(plain, /detail-card-label/)
	assert.match(plain, /Wat er is gebeurd/)
})

test('the case screen with display actions: the closed window as an ok notice, no status, no documents, save and withdraw kept', async () => {
	const data = {
		case: {
			id: 'c-82',
			title: 'Verlichting fietspad Lindelaan',
			toelichting: 'Graag de stukken.',
		},
		writableSet: {
			status: {
				label: 'In behandeling',
				description: 'Wij kijken of uw verzoek compleet is.',
			},
			window: { open: true },
			fields: { toelichting: { open: true } },
			documents: { open: true },
		},
		documents: [],
		withdrawal: { declared: true, open: true },
	}
	const base = { collection: ZAKEN, row: WOO, api: {}, t: nl, initialData: data }
	const actions = await renderComponent(CitizenCase, {
		...base,
		block: { type: 'citizenCase', collection: 'mijnZaken', display: 'actions' },
	})
	assert.match(actions, /pq-citizen-case--actions/)
	assert.doesNotMatch(
		actions,
		/data-testid="case-status"|data-testid="case-documents"/,
	)
	assert.match(actions, /data-testid="case-save"/)
	assert.match(
		actions,
		/data-testid="case-withdraw"[^>]*>Deze aanvraag intrekken</,
	)
	const closed = await renderComponent(CitizenCase, {
		...base,
		block: { type: 'citizenCase', collection: 'mijnZaken', display: 'actions' },
		initialData: {
			...data,
			writableSet: {
				...data.writableSet,
				window: { open: false, reason: 'U hoeft nu niets te doen.' },
			},
		},
	})
	assert.match(
		closed,
		/utrecht-alert utrecht-alert--ok pq-case-ok" role="status" data-testid="case-window-closed"/,
	)
	assert.match(closed, /U hoeft nu niets te doen\./)
	const plain = await renderComponent(CitizenCase, base)
	assert.match(plain, /data-testid="case-status"/)
	assert.match(plain, /data-testid="case-documents"/)
	assert.doesNotMatch(plain, /pq-citizen-case--actions|pq-case-ok/)
})

test('a page whose record is its heading owns the h1, and passes through Mijn zaken in the breadcrumb', () => {
	const entry = {
		key: 'dossiq:mijnZaken',
		label: 'Uw zaak',
		contribution: { app: 'dossiq' },
		page: {
			id: 'mijnZaken',
			record: { collection: 'mijnZaken', heading: 'record', under: 'cases' },
		},
	}
	assert.equal(pageOwnsHeading(entry), true)
	assert.deepEqual(
		accountCrumbs(entry, nl, href).map((crumb) => [crumb.label, crumb.route]),
		[
			['Home', '/'],
			['Mijn omgeving', ACCOUNT_ROUTE],
			['Mijn zaken', '/mijn/cases'],
			['Uw zaak', '/mijn/dossiq/mijnZaken'],
		],
	)
	const plain = {
		...entry,
		page: { id: 'mijnZaken', record: { collection: 'mijnZaken' } },
	}
	assert.equal(pageOwnsHeading(plain), false)
	assert.equal(accountCrumbs(plain, nl, href).length, 3)
})

test('the record page draws the eyebrow and the h1 when its record is the heading', async () => {
	const html = await renderSfc('src/site/pages/collections/ContributionPage.vue', {
		page: {
			id: 'mijnZaken',
			label: 'Uw zaak',
			record: {
				collection: 'mijnZaken',
				titleFields: ['title'],
				heading: 'record',
			},
			blocks: [],
		},
		contribution: { app: 'dossiq', collections: [ZAKEN] },
		initialData: { mijnZaken: { objects: [WOO], loading: false } },
		initialSelected: { mijnZaken: WOO },
		t: nl,
		locale: 'nl',
	})
	assert.match(html, /data-testid="record-eyebrow">Uw zaak · 2026-0082</)
	assert.match(
		html,
		/<h1[^>]*class="utrecht-heading-2 pq-record__title pq-record__title--h1"[^>]*>Verlichting fietspad Lindelaan<\/h1>/,
	)
	const plain = await renderSfc(
		'src/site/pages/collections/ContributionPage.vue',
		{
			page: {
				id: 'mijnZaken',
				label: 'Uw zaak',
				record: { collection: 'mijnZaken', titleFields: ['title'] },
				blocks: [],
			},
			contribution: { app: 'dossiq', collections: [ZAKEN] },
			initialData: { mijnZaken: { objects: [WOO], loading: false } },
			initialSelected: { mijnZaken: WOO },
			t: nl,
			locale: 'nl',
		},
	)
	assert.doesNotMatch(plain, /record-eyebrow|pq-record__title--h1/)
	assert.match(plain, /<h2[^>]*class="utrecht-heading-2 pq-record__title"/)
})

/** The shell's sections for the demo resident, plus portaliq's Meldingen page. */
function navFor(t) {
	const contributions = {
		contributions: [
			{
				app: 'portaliq',
				label: 'Meldingen',
				pages: [{ id: 'meldingen', label: 'Melding indienen' }],
			},
		],
		cases: { enabled: true },
		tasks: { enabled: true },
		unreadCount: 2,
	}
	return buildNav(contributions.contributions, t, {
		...shellSections({
			session: { subjectRef: 'sanne.devries', name: 'Sanne de Vries' },
			contributions,
			threads: [],
			news: [],
		}),
	})
}

const LAYOUT = [
	{ title: 'Mijn Zuiddrecht', items: ['overview', 'inbox'] },
	{ title: 'Zaken en taken', items: ['cases', 'tasks'] },
	{ title: 'Vragen en meldingen', items: ['portaliq:meldingen'] },
	{ title: 'Uw gegevens', items: ['details', 'account'] },
]

const names = (groups) =>
	groups.map((group) => [group.title, group.items.map((item) => item.name)])

test("the resident menu in the portal's own groups, Overzicht first, nothing unreachable, icons off", () => {
	const nav = navFor(nl)
	const own = residentMenuGroups(nav, nl, 2, href)
	const laid = residentMenuGroups(nav, nl, 2, href, {}, LAYOUT)
	assert.deepEqual(names(laid).slice(0, 4), [
		['Mijn Zuiddrecht', ['Overzicht', 'Berichten']],
		['Zaken en taken', ['Mijn zaken', 'Mijn taken']],
		['Vragen en meldingen', ['Melding indienen']],
		['Uw gegevens', ['Mijn gegevens', 'Mijn account']],
	])
	const overview = laid[0].items[0]
	assert.equal(overview.link, ACCOUNT_ROUTE)
	assert.equal(overview.href, href(ACCOUNT_ROUTE))
	// The inbox keeps its count.
	assert.equal(laid[0].items[1].badge, '2')
	// Every item of the site's own groups is still there, by route.
	const routes = (groups) =>
		groups.flatMap((group) => group.items.map((item) => item.link)).sort()
	for (const route of routes(own)) {
		assert.ok(routes(laid).includes(route), `${route} stays reachable`)
	}
	// Toegang tot zaken, not named by the layout, follows in its own group.
	const rest = names(laid).slice(4)
	assert.ok(
		rest.some(([, items]) => items.includes('Toegang tot zaken')),
		JSON.stringify(rest),
	)
	assert.ok(
		laid
			.flatMap((group) => group.items)
			.every((item) => item.icon === undefined),
	)
	// Without a layout: as before.
	assert.deepEqual(residentMenuGroups(nav, nl, 2, href, {}, null), own)
	assert.deepEqual(residentMenuGroups(nav, nl, 2, href, {}, []), own)
})

test('Mijn zaken as rows when the portal says so, folder cards otherwise', async () => {
	const cases = [
		{
			...WOO,
			_source: { appId: 'dossiq', collection: 'mijnZaken', label: 'Dossiq' },
		},
	]
	const contributions = {
		contributions: [{ app: 'dossiq', label: 'Dossiq', collections: [ZAKEN] }],
	}
	const rows = await renderSfc('src/site/pages/e/MyCasesPage.vue', {
		api: {},
		t: nl,
		locale: 'nl',
		initialData: { ok: true, cases },
		portal: { myCases: { display: 'rows' } },
		contributions,
	})
	assert.match(rows, /pq-cases__list--rows/)
	assert.match(rows, /pq-case-card--row/)
	assert.match(rows, /Uiterlijk klaar op/)
	assert.match(rows, /<strong>1 november<\/strong>/)
	assert.doesNotMatch(rows, /denhaag-case-card/)
	const cards = await renderSfc('src/site/pages/e/MyCasesPage.vue', {
		api: {},
		t: nl,
		locale: 'nl',
		initialData: { ok: true, cases },
		portal: {},
		contributions,
	})
	assert.match(cards, /denhaag-case-card/)
	assert.doesNotMatch(
		cards,
		/pq-cases__list--rows|pq-case-card--row|Uiterlijk klaar op/,
	)
})

test('the moved controls keep their place: the two ctas on the overview, save and withdraw on the case page', () => {
	// dossiq declares them (lib/Portal/PortalPages.php); the overview keeps
	// its cta blocks and the case screen its buttons, which the tests above
	// render. The data badge knows the info tone the compact card uses.
	const badge = readFileSync(
		new URL('../src/site/components/mijn/DataBadge.vue', import.meta.url),
		'utf8',
	)
	assert.match(badge, /'neutral', 'success', 'warning', 'error', 'info'/)
	const pages = readFileSync(
		new URL('../src/site/pages/registry.js', import.meta.url),
		'utf8',
	)
	assert.match(pages, /record\?\.heading === 'record'/)
})
