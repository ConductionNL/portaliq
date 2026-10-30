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
	fieldLabel,
	humanSize,
	longDescriptionLines,
	publicationSummary,
	schemaRows,
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

// publication-page-shows-the-schemas-fields (#1645): a publication of
// another schema shows every other field the API returns, named and ordered
// by its schema, so the schema in OpenRegister decides what is public. A Woo
// publication reads as before.
const MODULE_SCHEMA = {
	slug: 'module',
	properties: {
		name: { title: 'Name', type: 'string', order: 1 },
		website: { title: 'Website', type: 'string', format: 'url', order: 9 },
		licence: { title: 'License', type: 'string', $ref: '', order: 5 },
		applicationType: { title: 'Application type', type: 'string', order: 2 },
		provider: {
			title: 'Supplier',
			type: 'object',
			$ref: '#/components/schemas/organization',
			order: 3,
		},
		compliancy: {
			title: 'Compliance',
			type: 'array',
			items: { $ref: '#/components/schemas/compliancy' },
			order: 4,
		},
		bbnLevel: {
			title: 'BBN level',
			type: 'string',
			'x-enum-labels': { BBN2: 'Basisbeveiligingsniveau 2' },
			order: 6,
		},
		dpiaDate: { title: 'DPIA date', type: 'string', format: 'date', order: 7 },
		roadmapStatement: {
			title: 'Roadmap',
			type: 'string',
			format: 'markdown',
			order: 8,
		},
		logo: { title: 'Logo', type: 'file', order: 10 },
		type: { title: 'Type', type: 'string', visible: false, order: 11 },
		standards: {
			title: 'AMEF standards',
			type: 'array',
			items: { type: 'string' },
		},
		koppelingen: { title: 'Connections', type: 'array' },
	},
}
const MODULE = {
	'@self': { id: 'm-1', register: '25', schema: MODULE_SCHEMA },
	id: 'm-1',
	name: 'Voorbeeld Zaaksysteem',
	shortDescription: 'Demo: zaaksysteem van de gemeente.',
	longDescription:
		'## Wat het doet\nRegistreert en volgt zaken.\n\n<b>Geen opmaak</b>',
	applicationType: 'Zaaksysteem',
	licence: 'Apache License 2.0',
	website: 'https://example.org/zaaksysteem',
	provider: { id: 'org-9', name: 'Leverancier BV' },
	compliancy: ['5b0d1c7e-0000-0000-0000-000000000001'],
	bbnLevel: 'BBN2',
	dpiaDate: '2026-03-01',
	roadmapStatement: '# Plan\nNieuwe versie in 2027.',
	logo: { downloadUrl: 'https://example.org/logo.png' },
	type: 'Application',
	standards: ['ZGW API', ''],
	koppelingen: null,
	hostingLocation: 'NL',
	publicationDate: '2026-10-26T00:00:00+00:00',
}

test('a Woo publication gets no extra rows: the page reads exactly as before (#1645)', () => {
	const t = createTranslator('nl')
	assert.deepEqual(schemaRows(PUBLICATION, { locale: 'nl' }), [])
	assert.deepEqual(
		schemaRows(
			{
				...PUBLICATION,
				'@self': {
					id: 'p-1',
					schema: { properties: { status: { title: 'Status' } } },
				},
			},
			{ locale: 'nl' },
		),
		[],
		'the schema coming along changes nothing for a Woo publication',
	)
	assert.deepEqual(
		schemaRows({ wooCategory: null, status: 'x' }, { locale: 'nl' }),
		[],
	)
	assert.deepEqual(
		visitorRows(PUBLICATION, {
			t,
			locale: 'nl',
			themeNames: { [THEME]: 'Verkeer en parkeren' },
		}).map((row) => [row.label, row.value]),
		[
			['Publicatiedatum', '2 september 2026'],
			['Informatiecategorie', 'Woo-verzoeken en -besluiten'],
			["Thema's", ['Verkeer en parkeren']],
		],
	)
	assert.equal(publicationSummary(PUBLICATION), PUBLICATION.summary)
	assert.deepEqual(longDescriptionLines(PUBLICATION), [])
})

test('the fields of another schema read in the words and order of the schema, hidden and coded ones left out (#1645)', () => {
	const rows = schemaRows(MODULE, { locale: 'nl' })
	assert.deepEqual(
		rows.map((row) => [row.label, row.kind, row.value]),
		[
			['Application type', 'text', 'Zaaksysteem'],
			['Supplier', 'text', 'Leverancier BV'],
			['License', 'text', 'Apache License 2.0'],
			['BBN level', 'text', 'Basisbeveiligingsniveau 2'],
			['DPIA date', 'text', '1 maart 2026'],
			[
				'Roadmap',
				'lines',
				[
					{ index: 0, level: 1, text: 'Plan' },
					{ index: 1, level: 0, text: 'Nieuwe versie in 2027.' },
				],
			],
			['Website', 'link', 'https://example.org/zaaksysteem'],
			['AMEF standards', 'list', ['ZGW API']],
			['Hosting location', 'text', 'NL'],
		],
	)
	const shown = JSON.stringify(rows)
	for (const hidden of [
		'Application"',
		'Compliance',
		'5b0d1c7e',
		'Logo',
		'Connections',
	]) {
		assert.ok(!shown.includes(hidden), hidden)
	}
	assert.equal(publicationSummary(MODULE), MODULE.shortDescription)
	assert.deepEqual(longDescriptionLines(MODULE), [
		{ index: 0, level: 2, text: 'Wat het doet' },
		{ index: 1, level: 0, text: 'Registreert en volgt zaken.' },
		{ index: 3, level: 0, text: '<b>Geen opmaak</b>' },
	])
	assert.deepEqual(
		longDescriptionLines({ summary: 'Zelfde', longDescription: 'Zelfde' }),
		[],
		'a long description equal to the summary is not repeated',
	)
	assert.deepEqual(schemaRows(null, { locale: 'nl' }), [])
})

test('without its schema a field name reads in words, and a date the way the reader writes it (#1645)', () => {
	const { '@self': self, ...fields } = MODULE
	const rows = schemaRows(
		{ ...fields, '@self': { ...self, schema: '189' }, trueFlag: true },
		{ locale: 'en' },
	)
	assert.deepEqual(rows.map((row) => [row.label, row.value]).slice(0, 3), [
		['Application type', 'Zaaksysteem'],
		['Licence', 'Apache License 2.0'],
		['Website', 'https://example.org/zaaksysteem'],
	])
	const labels = Object.fromEntries(rows.map((row) => [row.label, row.value]))
	assert.equal(labels['Dpia date'], '1 March 2026')
	assert.equal(labels['True flag'], 'Yes')
	assert.equal(
		schemaRows(
			{ '@self': { schema: '189' }, trueFlag: true },
			{ t: createTranslator('nl'), locale: 'nl' },
		)[0].value,
		'Ja',
		'a yes/no reads through the translator',
	)
	assert.equal(labels.Type, 'Application')
	assert.equal(fieldLabel('hosting_location'), 'Hosting location')
})

test('a list of files reaches no row, with or without its schema (#1772 f1)', () => {
	const file = { title: 'a.pdf', downloadUrl: 'x' }
	assert.deepEqual(
		schemaRows(
			{
				'@self': {
					schema: {
						properties: {
							bijlagen: {
								title: 'Bijlagen',
								type: 'array',
								items: { type: 'file' },
							},
						},
					},
				},
				bijlagen: [file],
			},
			{ locale: 'nl' },
		),
		[],
	)
	assert.deepEqual(
		schemaRows(
			{ bijlagen: [file], bijlage: { title: 'b.pdf', accessUrl: 'y' } },
			{ locale: 'nl' },
		),
		[],
		'a file-shaped object without a schema reads as no row either',
	)
})

test('a relation id reaches no row: without a schema as a UUID, with one by a $ref on the list (#1772 f2)', () => {
	assert.deepEqual(
		schemaRows(
			{
				provider: '5b0d1c7e-0000-0000-0000-000000000001',
				compliancy: ['5b0d1c7e-0000-0000-0000-000000000002'],
			},
			{ locale: 'nl' },
		),
		[],
	)
	assert.deepEqual(
		schemaRows(
			{
				'@self': {
					schema: {
						properties: {
							c: { title: 'C', type: 'array', $ref: '#/x' },
						},
					},
				},
				c: ['5b0d1c7e-0000-0000-0000-000000000002'],
			},
			{ locale: 'nl' },
		),
		[],
	)
})

test('a long description or summary field marked visible: false stays off the page (#1772 f4)', () => {
	const hiding = (name) => ({
		schema: { properties: { [name]: { title: name, visible: false } } },
	})
	assert.deepEqual(
		longDescriptionLines({
			'@self': hiding('longDescription'),
			longDescription: 'Verborgen tekst.',
		}),
		[],
	)
	assert.equal(
		publicationSummary({
			'@self': hiding('shortDescription'),
			shortDescription: 'Verborgen samenvatting.',
		}),
		'',
	)
	assert.equal(
		publicationSummary({
			'@self': hiding('summary'),
			summary: 'Verborgen',
			description: 'Wel zichtbaar',
		}),
		'Wel zichtbaar',
		'the summary falls through to the next field',
	)
	assert.deepEqual(
		visitorRows(
			{
				'@self': hiding('publicationDate'),
				publicationDate: '2026-10-26T00:00:00+00:00',
			},
			{ t: createTranslator('nl'), locale: 'nl' },
		),
		[],
		'the publication date honours visible: false',
	)
})

test('a Woo publication without a category is known by its schema and gets no extra rows (#1772 review)', () => {
	// pub-overeenkomst-vandam in opencatalogi's Zuiddrecht example set: the
	// `publication` schema requires only a title, so this one has no
	// wooCategory key.
	const vandam = {
		'@self': {
			id: 'p-vandam',
			schema: {
				slug: 'publication',
				properties: {
					title: { title: 'Title', type: 'string' },
					wooCategory: {
						title: 'Woo information category',
						type: 'string',
					},
					status: { title: 'Status', type: 'string' },
					publicationKind: {
						title: 'Kind of publication',
						type: 'string',
					},
					soortHandeling: { title: 'Document handling', type: 'string' },
					retentionCategory: {
						title: 'Retention Category',
						type: 'string',
					},
					plooiReason: { title: 'PLOOI failure reason', type: 'string' },
				},
			},
		},
		title: 'Overeenkomst levering en plaatsing verlichting fietspad Lindelaan',
		summary: 'De opdracht aan Installatiebedrijf Van Dam.',
		publicationKind: 'actief',
		soortHandeling: 'ondertekening',
		status: 'published',
		retentionCategory: 'B1',
		plooiReason: 'timeout',
		publicationDate: '2026-10-20T10:00:00+00:00',
	}
	assert.deepEqual(schemaRows(vandam, { locale: 'nl' }), [])
	assert.deepEqual(
		visitorRows(vandam, { t: createTranslator('nl'), locale: 'nl' }).map(
			(row) => row.label,
		),
		['Publicatiedatum'],
	)
})

test('markdown reads as text: a bullet as a bullet, links, emphasis and code as their words (#1772 review)', () => {
	const markdown =
		'## Wat het doet\n- Registreert **zaken**\n* volgt [de status](https://example.org/s)\n'
		+ 'Werkt met `ZGW API` en _koppelingen_; snake_case_naam blijft.'
	assert.deepEqual(
		longDescriptionLines({ longDescription: markdown }).map((line) => [
			line.level,
			line.text,
		]),
		[
			[2, 'Wat het doet'],
			[0, '• Registreert zaken'],
			[0, '• volgt de status'],
			[0, 'Werkt met ZGW API en koppelingen; snake_case_naam blijft.'],
		],
	)
	const rows = schemaRows(
		{
			'@self': {
				schema: {
					properties: {
						roadmap: {
							title: 'Roadmap',
							type: 'string',
							format: 'markdown',
						},
					},
				},
			},
			roadmap: '- *Nieuwe* versie',
		},
		{ locale: 'nl' },
	)
	assert.deepEqual(
		rows.map((row) => row.value.map((line) => line.text)),
		[['• Nieuwe versie']],
	)
})

test('a link whose URL holds parentheses and a star inside a word read as written (#1772 review r2)', () => {
	assert.deepEqual(
		longDescriptionLines({
			longDescription:
				'Zie [de pagina](https://example.org/a_(b)) en a*b*c, maar *dit* wel.',
		}).map((line) => line.text),
		['Zie de pagina en a*b*c, maar dit wel.'],
	)
})

test('with a schema only a date format reads as a date (#1772 f5)', () => {
	const rows = schemaRows(
		{
			'@self': {
				schema: {
					properties: { version: { title: 'Versie', type: 'string' } },
				},
			},
			version: '2026-01-01',
		},
		{ locale: 'nl' },
	)
	assert.deepEqual(
		rows.map((row) => [row.label, row.value]),
		[['Versie', '2026-01-01']],
	)
	const undescribed = schemaRows(
		{
			'@self': {
				schema: {
					properties: { version: { title: 'Versie', type: 'string' } },
				},
			},
			releaseDate: '2026-03-01',
			owner: '5b0d1c7e-0000-0000-0000-000000000003',
		},
		{ locale: 'nl' },
	)
	assert.deepEqual(
		undescribed.map((row) => [row.label, row.value]),
		[['Release date', '1 maart 2026']],
		'a field the schema does not describe reads as without a schema',
	)
})

test('the publication page asks for the schema and renders the fields of the other schema as text (#1645)', async () => {
	const { instance } = await import('./support/page-instance.mjs')
	const nl = JSON.stringify(bundle('nl'))
	const component = await loadSfc(
		'src/site/components/PublicationDetailBlock.vue',
		{
			'../../shared/i18n/index.js': `const nl = ${nl}\nexport function createTranslator() { return (key) => nl[key] || key }\n`,
		},
	)
	const savedFetch = globalThis.fetch
	const savedWindow = globalThis.window
	globalThis.window = { location: { origin: 'https://zuiddrecht.test' } }
	const asked = []
	try {
		globalThis.fetch = async (url) => {
			asked.push(url)
			return { ok: true, status: 200, json: async () => MODULE }
		}
		const block = instance(component, {
			subjectId: MODULE.id,
			endpoint: '/index.php/apps/opencatalogi/api/federation/publications',
			t: (key) => key,
		})
		let documentsUrl = ''
		block.loadDocuments = (url) => {
			documentsUrl = url
		}
		block.loadThemeNames = () => {}
		await block.load()
		const read = new URL(asked[0])
		assert.equal(
			read.pathname,
			'/index.php/apps/opencatalogi/api/federation/publications/m-1',
		)
		assert.equal(read.searchParams.get('_extend'), '@self.schema')
		assert.ok(
			!documentsUrl.includes('_extend'),
			'the documents are read without the extend',
		)
	} finally {
		globalThis.fetch = savedFetch
		globalThis.window = savedWindow
	}

	const data = component.data
	const html = await renderComponent(
		{
			...component,
			mounted() {},
			data() {
				return {
					...data.call(this),
					loading: false,
					publication: MODULE,
					documents: [],
				}
			},
		},
		{ subjectId: 'm-1' },
	)
	assert.match(html, /Demo: zaaksysteem van de gemeente\./)
	assert.match(html, /<h3[^>]*>\s*Wat het doet\s*<\/h3>/)
	assert.match(html, /&lt;b&gt;Geen opmaak&lt;\/b&gt;/)
	assert.match(html, /Supplier:/)
	assert.match(
		html,
		/<a[^>]*href="https:\/\/example\.org\/zaaksysteem"[^>]*>https:\/\/example\.org\/zaaksysteem<\/a>/,
	)
	assert.match(html, /Nieuwe versie in 2027\./)
	assert.doesNotMatch(html, /Compliance|Logo|5b0d1c7e|>\s*Type:/)
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
