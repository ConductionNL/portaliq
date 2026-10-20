#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// publication-documents.spec.mjs: the documents of a publication, as the
// publication page lists them (woo-search-and-detail, REQ-WSD-005).
//
// Usage:
//   node --test tests/publication-documents.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { VALUE_LISTS } from '../src/site/config/valueLists.js'
import { labelBuckets, valueLabel } from '../src/site/lib/facetLabels.js'
import {
	humanSize,
	publicationSummary,
	themeIdsOf,
	toDocuments,
	visitorRows,
} from '../src/site/lib/publicationDetail.js'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

function bundle(locale) {
	return JSON.parse(
		readFileSync(
			new URL(`../src/shared/i18n/${locale}.json`, import.meta.url),
			'utf8',
		),
	)
}
function createTranslator(locale) {
	const strings = bundle(locale)
	return (key) => strings[key] || key
}

test('documents from the envelope', () => {
	const documents = toDocuments({
		results: [
			{
				id: 11,
				title: 'besluit.pdf',
				extension: 'pdf',
				type: 'application/pdf',
				size: 120000,
				downloadUrl: 'https://gemeente.example/s/abc/download',
				accessUrl: 'https://gemeente.example/s/abc',
			},
			{
				id: 12,
				title: 'inventaris.xlsx',
				type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				size: 2400000,
				accessUrl: '/index.php/s/def',
			},
		],
		total: 2,
	})

	assert.deepEqual(documents, [
		{
			id: '11',
			title: 'besluit.pdf',
			type: 'PDF',
			size: '120 kB',
			href: 'https://gemeente.example/s/abc/download',
		},
		{
			id: '12',
			title: 'inventaris.xlsx',
			type: 'XLSX',
			size: '2,4 MB',
			href: '/index.php/s/def',
		},
	])
})

test('unsafe link', () => {
	const [document] = toDocuments({
		results: [{ id: 1, title: 'x.pdf', downloadUrl: 'javascript:alert(1)' }],
	})
	assert.equal(document.href, '')

	const [protocolRelative] = toDocuments({
		results: [{ id: 2, title: 'y.pdf', downloadUrl: '//evil.example/y.pdf' }],
	})
	assert.equal(protocolRelative.href, '')
})

test('an envelope that is not one lists nothing', () => {
	assert.deepEqual(toDocuments(null), [])
	assert.deepEqual(toDocuments({ error: 'Not Found' }), [])
	assert.deepEqual(toDocuments([{ id: 3, title: 'bare.pdf' }]).length, 1)
})

test('human size', () => {
	assert.equal(humanSize(512), '512 B')
	assert.equal(humanSize(1500000), '1,5 MB')
	assert.equal(humanSize(undefined), '')
})

// WHAT A VISITOR READS (resident-sees-words-not-codes): the category's name,
// the themes' names, the date; never Plooi, retention or organisation ids.
// @spec openspec/changes/resident-sees-words-not-codes/specs/portal-federated-search/spec.md#requirement-the-publication-page-must-show-what-a-visitor-needs-in-words

const THEME = 'f75bc1d0-65e4-499c-b53e-8b6df3154b67'
const PUBLICATION = {
	'@self': { id: 'p-1', register: '7', organisation: 'org-uuid' },
	id: 'p-1',
	title: 'Besluit op Woo-verzoek over parkeervergunningen',
	summary: 'Besluit op een verzoek om informatie over parkeervergunningen.',
	description: '',
	caseReference: null,
	organization: '4b1c2d3e-0000-0000-0000-000000000001',
	plooiDeliveredAt: null,
	plooiIdentifier: null,
	plooiReason: null,
	plooiStatus: null,
	depublicationDate: null,
	period: null,
	publicationDate: '2026-09-02T09:00:00+00:00',
	publicationKind: 'woo-besluit',
	retentionAction: null,
	retentionCategory: null,
	retentionTermMonths: null,
	status: 'published',
	themes: [THEME, 'unknown-theme'],
	wooCategory: 'infocat014',
}

test('a Woo category reads as its name, in the site language', () => {
	assert.equal(
		valueLabel('wooCategory', 'infocat014', 'nl'),
		'Woo-verzoeken en -besluiten',
	)
	assert.equal(
		valueLabel('wooCategory', 'infocat014', 'en'),
		'Woo requests and decisions',
	)
	assert.equal(valueLabel('wooCategory', 'infocat016', 'de'), 'Beschikkingen')
	assert.equal(valueLabel('wooCategory', 'infocat999', 'nl'), 'infocat999')
})

test('a field without a configured list reads as the code itself', () => {
	assert.equal(valueLabel('organization', 'org-1', 'nl'), 'org-1')
	assert.equal(valueLabel('toString', 'infocat014', 'nl'), 'infocat014')
	assert.equal(valueLabel('wooCategory', 'toString', 'nl'), 'toString')
	assert.equal(valueLabel(undefined, undefined, 'nl'), '')
})

test('every configured code has a Dutch and an English name', () => {
	const lists = Object.entries(VALUE_LISTS)
	assert.ok(lists.length > 0)
	for (const [field, codes] of lists) {
		assert.ok(Object.keys(codes).length > 0, `${field} has no codes`)
		for (const [code, names] of Object.entries(codes)) {
			for (const locale of ['nl', 'en']) {
				assert.equal(
					typeof names[locale],
					'string',
					`${field}.${code}.${locale}`,
				)
				assert.notEqual(
					names[locale].trim(),
					'',
					`${field}.${code}.${locale}`,
				)
			}
		}
	}
	assert.equal(Object.keys(VALUE_LISTS.wooCategory).length, 17)
})

test('the category filter shows names, other filters keep their labels', () => {
	const buckets = [
		{ value: 'infocat014', label: 'infocat014', count: 3 },
		{ value: 'infocat016', label: 'infocat016', count: 1 },
	]
	assert.deepEqual(
		labelBuckets(buckets, 'wooCategory', 'nl').map((bucket) => bucket.label),
		['Woo-verzoeken en -besluiten', 'Beschikkingen'],
	)
	const organisations = [{ value: 'org-1', label: 'Gemeente Tilburg', count: 2 }]
	assert.deepEqual(
		labelBuckets(organisations, 'organization', 'nl'),
		organisations,
	)
})

// woo-search-names-the-organisation: the organisation filter read
// "bacda7a4-b1ae-4a51-9c3e-c1b00c0bc596 (13)" on the Zuiddrecht rehearsal.
test('the organisation filter reads the name the host knows, and keeps any other label', () => {
	const buckets = [
		{ value: 'bacda7a4', label: 'bacda7a4', count: 13 },
		{ value: 'other-org', label: 'other-org', count: 1 },
	]
	assert.deepEqual(
		labelBuckets(buckets, 'organization', 'nl', {
			bacda7a4: 'Gemeente Zuiddrecht',
		}).map((bucket) => [bucket.value, bucket.label, bucket.count]),
		[
			['bacda7a4', 'Gemeente Zuiddrecht', 13],
			['other-org', 'other-org', 1],
		],
	)
	assert.deepEqual(
		labelBuckets(
			[{ value: 'infocat014', label: 'infocat014', count: 1 }],
			'wooCategory',
			'nl',
			{},
		).map((bucket) => bucket.label),
		['Woo-verzoeken en -besluiten'],
		'a configured list still names its codes',
	)
})

test('the page shows date, category and named themes only, never internal fields', () => {
	const t = createTranslator('nl')
	const rows = visitorRows(PUBLICATION, {
		t,
		locale: 'nl',
		themeNames: { [THEME]: 'Verkeer en parkeren' },
	})
	assert.deepEqual(
		rows.map((row) => [row.label, row.value]),
		[
			['Publicatiedatum', '2 september 2026'],
			['Informatiecategorie', 'Woo-verzoeken en -besluiten'],
			["Thema's", ['Verkeer en parkeren']],
		],
	)
	const shown = JSON.stringify(rows)
	for (const internal of [
		'plooi',
		'retention',
		'organization',
		'infocat014',
		THEME,
		'woo-besluit',
		'published',
	]) {
		assert.ok(!shown.toLowerCase().includes(internal.toLowerCase()), internal)
	}
	assert.equal(publicationSummary(PUBLICATION), PUBLICATION.summary)
	assert.equal(
		publicationSummary({ description: 'Alleen een omschrijving' }),
		'Alleen een omschrijving',
	)
	assert.deepEqual(themeIdsOf(PUBLICATION), [THEME, 'unknown-theme'])
})

test('a row without a value is left out, and English reads English', () => {
	const t = createTranslator('en')
	const rows = visitorRows(
		{ wooCategory: 'infocat010', themes: [THEME] },
		{ t, locale: 'en', themeNames: {} },
	)
	assert.deepEqual(
		rows.map((row) => [row.label, row.value]),
		[['Information category', 'Advice']],
	)
	assert.deepEqual(visitorRows(null, { t, locale: 'en' }), [])
})

test('the publication page renders the summary and the visitor rows, no internal field', async () => {
	const nl = JSON.stringify(bundle('nl'))
	const component = await loadSfc(
		'src/site/components/PublicationDetailBlock.vue',
		{
			'../../shared/i18n/index.js': `const nl = ${nl}\nexport function createTranslator() { return (key) => nl[key] || key }\n`,
		},
	)
	const data = component.data
	const html = await renderComponent(
		{
			...component,
			mounted() {},
			data() {
				return {
					...data.call(this),
					loading: false,
					publication: PUBLICATION,
					themeNames: { [THEME]: 'Verkeer en parkeren' },
					documents: [],
				}
			},
		},
		{ subjectId: 'p-1' },
	)
	assert.match(html, /Besluit op een verzoek om informatie/)
	assert.match(html, /Woo-verzoeken en -besluiten/)
	assert.match(html, /Verkeer en parkeren/)
	assert.doesNotMatch(html, /Plooi|Retention|infocat014|f75bc1d0/)
})

test('the publication tells the page its title for the breadcrumb, and nothing when it is not found (REQ-RR3-004)', async () => {
	const { instance } = await import('./support/page-instance.mjs')
	const component = await loadSfc(
		'src/site/components/PublicationDetailBlock.vue',
		{
			'../../shared/i18n/index.js':
				'export function createTranslator() { return (key) => key }\n',
		},
	)
	assert.ok(component.emits.includes('subject'))
	const savedFetch = globalThis.fetch
	const savedWindow = globalThis.window
	globalThis.window = { location: { origin: 'https://zuiddrecht.test' } }
	try {
		for (const [answer, told] of [
			[PUBLICATION, { title: PUBLICATION.title }],
			[null, null],
		]) {
			globalThis.fetch = async () => ({
				ok: answer !== null,
				status: answer ? 200 : 404,
				json: async () => answer,
			})
			const block = instance(component, {
				subjectId: PUBLICATION.id,
				endpoint: '/index.php/apps/opencatalogi/api/federation/publications',
				t: (key) => key,
			})
			block.loadDocuments = () => {}
			block.loadThemeNames = () => {}
			await block.load()
			assert.deepEqual(block.emitted.at(-1), ['subject', told])
		}
	} finally {
		globalThis.fetch = savedFetch
		globalThis.window = savedWindow
	}
})

test('the host hands the search block its organisation by name, after the authored props', () => {
	const read = (file) =>
		readFileSync(new URL(`../${file}`, import.meta.url), 'utf8')
	const grid = read('src/site/components/WidgetGrid.vue')
	const branch = grid.slice(grid.indexOf("widget.widgetKey === 'federatedSearch'"))
	const body = branch.slice(0, branch.indexOf('}\n\t\t\t}'))
	assert.match(body, /\.\.\.props,[\s\S]*facetNames: this\.organisation\?\.id/)
	assert.match(
		body,
		/organization:\s*\{\s*\[this\.organisation\.id\]: this\.organisation\.name,?\s*\}/,
	)
	assert.match(
		read('src/site/App.vue'),
		/organisation: this\.site\.organisation \|\| null,/,
		'the site record reaches every grid',
	)
	assert.match(
		read('src/site/components/FederatedSearchBlock.vue'),
		/pageLocale\(\),\s*this\.facetNames\[field\],/,
	)
})
