#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-collections.spec.mjs: a contribution page on the site renders its
// blocks, and a guardian reads labels and values, not field names, uuids or
// [object Object] (site-reaches-portal-parity slice b, REQ-SRP-014,
// REQ-SRP-015, REQ-SRP-017; finding F21 from the po-flow lane).
//
// Usage:
//   node --test tests/site-collections.spec.mjs
//
// The fixtures are learniq's parent collections as its
// PortalContributionProvider declares them: My children, grades, attendance,
// absence excuses and report cards.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	columnLabel,
	deriveColumns,
	detailFields,
	formatCell,
	humanise,
	readable,
	safeHref,
} from '../src/site/components/collections/cells.js'
import {
	BLOCK_SLOTS,
	blockSlotLoader,
	registerBlockSlot,
} from '../src/site/pages/collections/blockSlots.js'
import { pages } from '../src/site/pages/collections/index.js'
import { resolveBlocks } from '../src/site/pages/collections/pageBlocks.js'
import strings from '../src/site/pages/collections/strings.js'
import { collectionsTranslator } from '../src/site/pages/collections/translate.js'
import { renderSfc } from './support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const UUID = /[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i
const t = (key) => key
const en = { locale: 'en', t }

const VERA = 'ee010008-0000-4000-8000-000000000415'
const FATIMA = 'ee010008-0000-4000-8000-000000000009'

const parentChildren = {
	id: 'parentChildren',
	register: 'learniq',
	schema: 'learner-profile',
	label: 'My children',
	columns: [
		{ field: 'givenName', label: 'First name', render: 'text' },
		{ field: 'familyName', label: 'Last name', render: 'text' },
	],
}
const parentReportCards = {
	id: 'parentReportCards',
	register: 'learniq',
	schema: 'report-card',
	label: "My child's report cards",
	columns: [
		{ field: 'mentorComment', label: "Teacher's comment", render: 'text' },
		{ field: 'subjectGrades', label: 'Grades', render: 'text' },
	],
}
const parentExcuseRequests = {
	id: 'parentExcuseRequests',
	register: 'learniq',
	schema: 'excuse-request',
	label: "My child's absence excuses",
	columns: [
		{ field: 'dateFrom', label: 'From', render: 'text' },
		{ field: 'dateTo', label: 'To', render: 'text' },
		{ field: 'reason', label: 'Reason', render: 'text' },
		{ field: 'lifecycle', label: 'Status', render: 'text' },
		{ field: 'decidedAt', label: 'Decided on', render: 'text' },
	],
}

const children = [
	{
		id: VERA,
		givenName: 'Vera',
		familyName: 'Hulstkamp',
		guardianRefs: [FATIMA],
		beeldmateriaalConsent: true,
		'@self': { id: VERA },
	},
]
const reportCards = [
	{
		id: 'ee010009-0000-4000-8000-000000000001',
		learnerRef: VERA,
		reportPeriodId: 'ee010009-0000-4000-8000-0000000000aa',
		mentorComment: 'Vera werkt goed mee.',
		subjectGrades: [
			{
				curriculumPlanId: 'ee010009-0000-4000-8000-0000000000bb',
				periodAverage: 7.5,
				passed: true,
				teacherComment: 'Mooi werk',
			},
		],
		attendanceSummary: { presentCount: 40, absentUnexcusedCount: 0 },
	},
]
const excuses = [
	{
		id: 'ee010009-0000-4000-8000-000000000002',
		learnerRef: VERA,
		dateFrom: '2026-09-21',
		dateTo: '2026-09-22',
		reason: 'Griep',
		lifecycle: 'approved',
		decidedAt: '2026-09-21T10:15:00+00:00',
	},
]

const contribution = {
	app: 'learniq',
	collections: [parentChildren, parentReportCards, parentExcuseRequests],
	actions: [
		{
			id: 'createExcuseRequest',
			type: 'create',
			register: 'learniq',
			schema: 'excuse-request',
		},
	],
	pages: [
		{
			id: 'children',
			label: 'My children',
			blocks: [
				{
					type: 'richText',
					markdown: '## Your children\nWhat the school knows.',
				},
				{ type: 'collection', collection: 'parentChildren' },
				{ type: 'detail', collection: 'parentChildren' },
				{ type: 'collection', collection: 'parentReportCards' },
				{ type: 'collection', collection: 'parentExcuseRequests' },
				{ type: 'action', action: 'createExcuseRequest' },
				{ type: 'somethingNew' },
			],
		},
	],
}

test('a declared column shows its label, an undeclared field shows as words', () => {
	assert.equal(
		columnLabel({ field: 'givenName', label: 'First name' }),
		'First name',
	)
	assert.equal(
		columnLabel({ field: 'beeldmateriaalConsentReviewDueAt' }),
		'Beeldmateriaal consent review due at',
	)
	assert.equal(humanise('given_name'), 'Given name')
	assert.deepEqual(
		deriveColumns(parentChildren, children).map((c) => c.label),
		['First name', 'Last name'],
	)
})

test('without declared columns, identifiers and the envelope are not columns', () => {
	const columns = deriveColumns({ id: 'x' }, children).map((c) => c.field)
	assert.deepEqual(columns, ['givenName', 'familyName', 'beeldmateriaalConsent'])
	assert.ok(
		!columns.includes('guardianRefs'),
		'a field of only uuids is no column',
	)
})

test('a guardian never reads a uuid, an ISO stamp or [object Object] in a cell (F21)', () => {
	const cells = []
	for (const [collection, rows] of [
		[parentChildren, children],
		[parentReportCards, reportCards],
		[parentExcuseRequests, excuses],
	]) {
		for (const row of rows) {
			for (const column of deriveColumns(collection, rows)) {
				cells.push(formatCell(row[column.field], column.render, en))
			}
		}
	}
	const text = cells.join(' | ')
	assert.doesNotMatch(text, /\[object Object\]/)
	assert.doesNotMatch(text, UUID)
	assert.doesNotMatch(text, /\d{4}-\d{2}-\d{2}T/)
	assert.match(
		text,
		/7\.5, Yes, Mooi werk/,
		'a nested grade reads as its readable parts',
	)
	assert.match(text, /Griep/)
})

test('the render formatters follow the column', () => {
	assert.equal(formatCell(true, 'boolean', en), 'Yes')
	assert.equal(
		formatCell(false, 'boolean', {
			locale: 'nl',
			t: collectionsTranslator(null, 'nl'),
		}),
		'Nee',
	)
	assert.equal(
		formatCell(12.5, 'currency', { locale: 'nl', t }),
		new Intl.NumberFormat('nl', { style: 'currency', currency: 'EUR' }).format(
			12.5,
		),
	)
	assert.equal(
		formatCell('2026-09-21', 'date', { locale: 'nl', t }),
		new Date('2026-09-21').toLocaleDateString('nl'),
	)
	assert.equal(formatCell(null, 'text', en), '')
	assert.equal(
		readable({ label: 'Groep 7', id: 'x' }, en),
		'Groep 7',
		'a named object reads as its name',
	)
	assert.equal(safeHref('javascript:alert(1)'), '')
	assert.equal(
		safeHref('https://school.example/rapport'),
		'https://school.example/rapport',
	)
})

test('a detail card labels its fields from the columns and leaves the envelope out', () => {
	const fields = detailFields(parentChildren, children[0])
	assert.deepEqual(
		fields.map((f) => f.label),
		['First name', 'Last name', 'Guardian refs', 'Beeldmateriaal consent'],
	)
	assert.ok(fields.every((f) => !['id', '@self', '_files'].includes(f.field)))
	const declared = detailFields(
		{ ...parentChildren, detail: { fields: ['familyName'] } },
		children[0],
	)
	assert.deepEqual(
		declared.map((f) => [f.field, f.label, f.declared]),
		[['familyName', 'Last name', true]],
	)
})

test("each block resolves to what renders it, and another slice's block to its place", () => {
	const kinds = resolveBlocks(contribution.pages[0], contribution).map(
		(item) => item.kind,
	)
	assert.deepEqual(kinds, [
		'richText',
		'table',
		'detail',
		'table',
		'table',
		'action',
		'none',
	])
	const timed = resolveBlocks(
		{
			blocks: [
				{ type: 'collection', collection: 'toets' },
				{ type: 'citizenCase', collection: 'zaak' },
				{ type: 'cta', action: 'pay', label: 'Pay' },
				{ type: 'collection', collection: 'gone' },
			],
		},
		{
			collections: [
				{ id: 'toets', kind: 'timedTask', timedTask: {} },
				{ id: 'zaak' },
			],
			actions: [{ id: 'pay', type: 'endpoint', endpoint: '/x' }],
		},
	).map((item) => item.kind)
	assert.deepEqual(timed, ['timedTask', 'citizenCase', 'cta', 'none'])
})

test('a case screen under a detail card on its collection waits quietly for a case', () => {
	const contribution = {
		collections: [{ id: 'mijnZaken' }, { id: 'verzoeken' }],
	}
	const [, detail, quiet, alone] = resolveBlocks(
		{
			blocks: [
				{ type: 'collection', collection: 'mijnZaken' },
				{ type: 'detail', collection: 'mijnZaken' },
				{ type: 'citizenCase', collection: 'mijnZaken' },
				{ type: 'citizenCase', collection: 'verzoeken' },
			],
		},
		contribution,
	)
	assert.equal(detail.kind, 'detail')
	assert.equal(quiet.quietWhenEmpty, true)
	// Without a detail card on its collection it still says "Select a case.".
	assert.equal(alone.quietWhenEmpty, undefined)
	const page = readFileSync(
		join(ROOT, 'src', 'site', 'pages', 'collections', 'ContributionPage.vue'),
		'utf8',
	)
	assert.match(page, /:quietWhenEmpty="item\.quietWhenEmpty === true"/)
	const screen = readFileSync(
		join(ROOT, 'src', 'site', 'components', 'e', 'CitizenCase.vue'),
		'utf8',
	)
	assert.match(
		screen,
		/<p v-if="!quietWhenEmpty" class="utrecht-paragraph pq-empty">/,
	)
})

test('only update and endpoint row actions reach the row buttons, never propose-change', () => {
	const withActions = {
		collections: [
			{ id: 'c', rowActions: ['approve', 'propose', 'pay', 'viewDocument'] },
		],
		actions: [
			{ id: 'approve', type: 'update' },
			{ id: 'propose', type: 'propose-change' },
			{
				id: 'pay',
				type: 'endpoint',
				endpoint: '/pay',
				rowField: 'id',
				label: 'Pay',
			},
			{
				id: 'viewDocument',
				type: 'endpoint',
				endpoint: '/view',
				rowField: 'id',
			},
		],
	}
	const [table, detail] = resolveBlocks(
		{
			blocks: [
				{ type: 'collection', collection: 'c' },
				{ type: 'detail', collection: 'c' },
			],
		},
		withActions,
	)
	assert.ok(table.tableActions.some((a) => a.id === 'approve'))
	assert.ok(table.tableActions.some((a) => a.id === 'pay'))
	assert.ok(!table.tableActions.some((a) => a.id === 'propose'))
	assert.ok(
		!table.tableActions.some((a) => a.id === 'viewDocument'),
		'viewing belongs to the sign step',
	)
	assert.equal(table.viewAction.id, 'viewDocument')
	assert.equal(detail.proposeAction.id, 'propose')
})

test('the places other slices fill are named, and an unknown place is refused', () => {
	assert.deepEqual([...BLOCK_SLOTS].sort(), [
		'action',
		'attachedActions',
		'citizenCase',
		'proposals',
		'rowAction',
		'timedTask',
	])
	assert.equal(blockSlotLoader('timedTask'), null)
	assert.throws(() => registerBlockSlot('dashboard', () => null), /not a place/)
	const loader = async () => ({})
	registerBlockSlot('timedTask', loader)
	assert.equal(blockSlotLoader('timedTask'), loader)
})

test('the slice exports a lazy contribution page', () => {
	assert.deepEqual(Object.keys(pages), ['contribution'])
	assert.equal(typeof pages.contribution, 'function')
	const index = readFileSync(
		join(ROOT, 'src/site/pages/collections/index.js'),
		'utf8',
	)
	assert.match(index, /@typedef \{object\} SitePageProps/)
	assert.match(index, /import\('\.\/ContributionPage\.vue'\)/)
})

test('every string has a Dutch and an English text, and the site translator wins', () => {
	assert.deepEqual(Object.keys(strings.nl).sort(), Object.keys(strings.en).sort())
	for (const [key, text] of Object.entries(strings.nl)) {
		assert.ok(text && !text.includes('—'), `nl "${key}"`)
	}
	const nl = collectionsTranslator((key) => key, 'nl')
	assert.equal(nl('Attachments'), 'Bijlagen')
	assert.equal(
		nl('File added: {name}', { name: 'a.pdf' }),
		'Bestand toegevoegd: a.pdf',
	)
	const site = collectionsTranslator(
		(key) => (key === 'Attachments' ? 'Bijlagen (site)' : key),
		'nl',
	)
	assert.equal(site('Attachments'), 'Bijlagen (site)')
	const portalNl = JSON.parse(
		readFileSync(join(ROOT, 'src/shared/i18n/nl.json'), 'utf8'),
	)
	for (const [key, text] of Object.entries(strings.nl)) {
		if (portalNl[key]) {
			assert.equal(
				text,
				portalNl[key],
				`"${key}" keeps the React portal's Dutch`,
			)
		}
	}
})

test("the page renders learniq's parent collections with labels, as a guardian reads them", async () => {
	const html = await renderSfc('src/site/pages/collections/ContributionPage.vue', {
		entry: {
			key: 'learniq:children',
			label: 'My children',
			page: contribution.pages[0],
			contribution,
		},
		api: {},
		t,
		locale: 'en',
		initialData: {
			parentChildren: { loading: false, objects: children },
			parentReportCards: { loading: false, objects: reportCards },
			parentExcuseRequests: { loading: false, objects: excuses },
		},
		initialSelected: { parentChildren: children[0] },
	})

	assert.match(
		html,
		/<th scope="col" class="utrecht-table__header-cell">First name<\/th>/,
	)
	assert.match(
		html,
		/<th scope="col" class="utrecht-table__header-cell">Teacher&#39;s comment<\/th>/,
	)
	assert.match(
		html,
		/<th scope="col" class="utrecht-table__header-cell">Decided on<\/th>/,
	)
	assert.doesNotMatch(html, /givenName|mentorComment|\[object Object\]/)
	assert.doesNotMatch(
		html.replace(/data-[a-z-]+="[^"]*"/g, ''),
		UUID,
		'no uuid is visible',
	)
	// One heading per title: the shell shows "My children" as the page h1, so
	// the collection named like the page does not repeat it, and its table is
	// labelled by the shell's title. The other collections keep their own.
	assert.doesNotMatch(html, /<h2[^>]*>My children<\/h2>/)
	assert.match(
		html,
		/aria-labelledby="site-account-title" data-testid="collection-table"/,
	)
	assert.match(
		html,
		/<h2[^>]*class="utrecht-heading-3">My child&#39;s report cards<\/h2>/,
	)
	// The detail card's fields are a description list
	// (site-mijn-omgeving-components REQ-SMO-005).
	assert.match(
		html,
		/<dt class="utrecht-data-list__item-key pq-description-list__key">First name<\/dt>/,
	)
	assert.match(
		html,
		/<dd class="utrecht-data-list__item-value pq-description-list__value">Vera<\/dd>/,
	)
	assert.match(html, /<h3 class="utrecht-heading-3">Your children<\/h3>/)
	assert.match(
		html,
		/data-testid="collections-slot-action"/,
		'the form is left to slice c',
	)
	assert.equal((html.match(/data-testid="collection-table"/g) || []).length, 3)
})

test('a page whose rows are still loading says so in a status region', async () => {
	const html = await renderSfc('src/site/pages/collections/ContributionPage.vue', {
		page: {
			id: 'p',
			blocks: [{ type: 'collection', collection: 'parentChildren' }],
		},
		contribution,
		api: {},
		t,
		locale: 'nl',
	})
	assert.match(html, /role="status"[^>]*>\s*Laden…/)
})
