#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// mijn-documents.spec.mjs: file items, the contact timeline, the description
// list and the documents and timeline blocks (site-mijn-omgeving-components
// wave 4: REQ-SMO-005, REQ-SMO-021), with a failed read that says so
// (REQ-SMO-009).
//
// Usage:
//   node --test tests/mijn-documents.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	fileLine,
	fileType,
	momentInWords,
	sizeInWords,
} from '../src/site/components/mijn/documents.js'
import { blocks } from '../src/site/components/mijn/index.js'
import { mijnTranslator } from '../src/site/components/mijn/rows.js'
import { resolveBlocks } from '../src/site/pages/collections/pageBlocks.js'
import { instance, inState } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const FileItem = await loadSfc('src/site/components/mijn/FileItem.vue')
const ContactTimeline = await loadSfc('src/site/components/mijn/ContactTimeline.vue')
const DescriptionList = await loadSfc('src/site/components/mijn/DescriptionList.vue')
const DocumentsBlock = await loadSfc('src/site/components/mijn/DocumentsBlock.vue')
const TimelineBlock = await loadSfc('src/site/components/mijn/TimelineBlock.vue')

const nl = mijnTranslator(null, 'nl')

const ZAKEN = {
	id: 'mijnZaken',
	register: 'dossiq',
	schema: 'case',
	kind: 'cases',
	documents: { label: 'Documenten', provider: 'caseDocuments' },
	timeline: { label: 'Wat er is gebeurd', provider: 'caseTimeline' },
}

const RECEIPT = {
	id: 'd1',
	title: 'Ontvangstbevestiging',
	kind: 'decision',
	date: '2026-10-02',
	mimeType: 'application/pdf',
	size: 84 * 1024,
}

const ENTRIES = [
	{
		id: 'e1',
		message: 'Uw verzoek is ontvangen.',
		occurredAt: '2026-10-02T14:10:00',
	},
	{
		id: 'e2',
		message: 'Wij hebben u een vraag gesteld.',
		occurredAt: '2026-10-02T14:20:00',
	},
	{ id: 'e3', message: 'Zonder moment.' },
]

test('the receipt reads who sent it, when, its type and its size', () => {
	// REQ-SMO-005 scenario "The receipt on the case page".
	assert.equal(
		fileLine(RECEIPT, nl, 'nl'),
		'Van de gemeente, 2 oktober 2026. PDF, 84 kB',
	)
	assert.equal(
		fileLine({ title: 'foto.jpg', kind: 'yours', size: 1536 * 1024 }, nl, 'nl'),
		'Van u. JPG, 1,5 MB',
	)
	assert.equal(fileLine({ title: 'zonder' }, nl, 'nl'), 'Van de gemeente.')
	assert.equal(fileType({ title: 'brief.docx' }), 'DOCX')
	assert.equal(fileType({ mimeType: 'application/msword', title: 'x' }), 'Word')
	assert.equal(sizeInWords(0), '')
	assert.equal(sizeInWords(300), '1 kB')
	assert.equal(sizeInWords(1536 * 1024, 'en'), '1.5 MB')
	assert.equal(
		momentInWords('2026-10-02T14:20:00', nl, 'nl'),
		'2 oktober 2026 om 14.20 uur',
	)
	assert.equal(
		momentInWords('2026-10-02', nl, 'nl'),
		'2 oktober 2026',
		'a date without a time is the day',
	)
	assert.equal(momentInWords('', nl, 'nl'), '')
})

test('a file item is one button named after the document, with its line', async () => {
	const html = await renderComponent(FileItem, {
		name: 'Ontvangstbevestiging',
		line: fileLine(RECEIPT, nl, 'nl'),
	})
	assert.equal((html.match(/<button/g) || []).length, 1)
	assert.match(
		html,
		/^<li class="pq-file-item" data-testid="mijn-file-item"><button type="button" class="denhaag-file pq-file-item__control">/,
	)
	assert.match(html, /<span class="denhaag-file__left" aria-hidden="true">/)
	assert.match(
		html,
		/<span class="pq-file-item__name">Ontvangstbevestiging<\/span><span class="pq-file-item__line">Van de gemeente, 2 oktober 2026\. PDF, 84 kB<\/span>/,
	)
})

test('the contact timeline lists events newest first, each a moment and a sentence', async () => {
	const html = await renderComponent(ContactTimeline, {
		entries: ENTRIES,
		tr: nl,
		locale: 'nl',
	})
	assert.match(
		html,
		/^<ol class="denhaag-process-steps denhaag-contact-timeline pq-contact-timeline"/,
	)
	const order = [
		'Wij hebben u een vraag gesteld.',
		'Uw verzoek is ontvangen.',
		'Zonder moment.',
	].map((text) => html.indexOf(text))
	assert.ok(
		order.every((at) => at > -1),
		'none dropped',
	)
	assert.deepEqual(
		[...order].sort((a, b) => a - b),
		order,
		'newest first, no moment last',
	)
	assert.match(
		html,
		/<time class="denhaag-contact-timeline__step-header__date" datetime="2026-10-02T14:20:00">2 oktober 2026 om 14\.20 uur<\/time>/,
	)
	assert.equal(
		(html.match(/<time /g) || []).length,
		2,
		'an event without a moment has no <time>',
	)
})

test('a description list is a dl of keys and values', async () => {
	const html = await renderComponent(DescriptionList, {
		items: [{ key: 'naam', label: 'Naam', value: 'Jansen' }],
		itemTestid: 'fact',
	})
	assert.match(
		html,
		/<dl class="utrecht-data-list pq-description-list" data-testid="mijn-description-list"><!--\[--><div class="utrecht-data-list__item pq-description-list__item" data-testid="fact"><dt class="utrecht-data-list__item-key pq-description-list__key">Naam<\/dt><dd class="utrecht-data-list__item-value pq-description-list__value">Jansen<\/dd><\/div>/,
	)
})

test('the documents block lists the case documents as file items, and a failed read says so', async () => {
	const api = {
		fetchCitizenCase: async () => ({
			documents: [RECEIPT],
			documentsLabel: 'Uw documenten',
		}),
		downloadCitizenDocument: async () => ({ ok: false, status: 500 }),
	}
	const ctx = instance(DocumentsBlock, {
		block: { type: 'documents', collection: 'mijnZaken' },
		collection: ZAKEN,
		record: { id: 'z-3' },
		api,
		locale: 'nl',
	})
	await ctx.load()
	assert.equal(ctx.failed, false)
	assert.equal(ctx.heading, 'Uw documenten')
	assert.equal(ctx.documents.length, 1)
	await ctx.openDocument(RECEIPT)
	assert.equal(ctx.openFailed, true, 'a failed download says so')

	const html = await renderComponent(
		inState(DocumentsBlock, { answer: { documents: [RECEIPT] }, failed: false }),
		{
			block: { type: 'documents', collection: 'mijnZaken' },
			collection: ZAKEN,
			record: { id: 'z-3' },
			locale: 'nl',
		},
	)
	assert.match(html, /data-testid="mijn-file-item"/)
	assert.match(html, /Van de gemeente, 2 oktober 2026\. PDF, 84 kB/)

	const failing = instance(DocumentsBlock, {
		block: { type: 'documents', collection: 'mijnZaken' },
		collection: ZAKEN,
		record: { id: 'z-3' },
		api: { fetchCitizenCase: async () => null },
	})
	await failing.load()
	assert.equal(failing.failed, true)
	const failed = await renderComponent(
		inState(DocumentsBlock, { answer: null, failed: true }),
		{
			block: { type: 'documents', collection: 'mijnZaken' },
			collection: ZAKEN,
			record: { id: 'z-3' },
			locale: 'nl',
		},
	)
	assert.match(failed, /data-testid="mijn-load-error"/)
	assert.match(failed, /De documenten konden niet worden geladen\./)
	assert.doesNotMatch(failed, /Er staan nog geen documenten/)
})

test('the timeline block shows the history as a contact timeline, and a failed read says so', async () => {
	const ctx = instance(TimelineBlock, {
		block: { type: 'timeline', collection: 'mijnZaken' },
		collection: ZAKEN,
		record: { id: 'z-3' },
		api: {
			fetchTimeline: async () => ({
				label: 'Wat er is gebeurd',
				entries: ENTRIES,
			}),
		},
	})
	await ctx.load()
	assert.equal(ctx.failed, false)
	assert.equal(ctx.entries.length, 3)

	const failing = instance(TimelineBlock, {
		block: { type: 'timeline', collection: 'mijnZaken' },
		collection: ZAKEN,
		record: { id: 'z-3' },
		api: {
			fetchTimeline: async () => {
				throw new TypeError('offline')
			},
		},
	})
	await failing.load()
	assert.equal(failing.failed, true, 'a rejected read is a failed read')
	const failed = await renderComponent(
		inState(TimelineBlock, { answer: null, failed: true }),
		{
			block: { type: 'timeline', collection: 'mijnZaken' },
			collection: ZAKEN,
			record: { id: 'z-3' },
			locale: 'nl',
		},
	)
	assert.match(failed, /Wat er is gebeurd kon niet worden geladen\./)
	assert.doesNotMatch(failed, /Er is nog niets gebeurd/)

	const empty = await renderComponent(
		inState(TimelineBlock, { answer: { entries: [] }, failed: false }),
		{
			block: { type: 'timeline', collection: 'mijnZaken' },
			collection: ZAKEN,
			record: { id: 'z-3' },
			locale: 'nl',
		},
	)
	assert.match(empty, /Er is nog niets gebeurd\./)
	assert.doesNotMatch(empty, /<ol/)
})

test('a page renders its documents and timeline blocks, loaded on demand', () => {
	const contribution = { app: 'dossiq', collections: [ZAKEN] }
	const page = {
		id: 'zaak',
		record: { collection: 'mijnZaken' },
		blocks: [
			{ type: 'documents', collection: 'mijnZaken' },
			{ type: 'timeline', collection: 'mijnZaken' },
			{ type: 'documents', collection: 'weg' },
		],
	}
	assert.deepEqual(
		resolveBlocks(page, contribution).map((item) => [
			item.kind,
			item.collection?.id,
		]),
		[
			['documents', 'mijnZaken'],
			['timeline', 'mijnZaken'],
			['none', undefined],
		],
	)
	assert.equal(typeof blocks.documents, 'function')
	assert.equal(typeof blocks.timeline, 'function')
})
