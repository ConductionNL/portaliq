#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// document-page.spec.mjs: one public document's own page
// (publication-detail-page-complete REQ-PDC-002).
//
// The attachment rows follow opencatalogi's public read shape,
// source: opencatalogi docs/Technical/publication-detail-for-the-portal.md
// ("One document (REQ-PDP-002)").
//
// Usage:
//   node --test tests/document-page.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import {
	documentFacts,
	documentPageOf,
	documentPageRoute,
	documentRouteOf,
	isDocumentRoute,
	loadDocumentPage,
	publicationPageRoute,
} from '../src/site/lib/documentPage.js'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const nl = JSON.parse(
	readFileSync(new URL('../src/shared/i18n/nl.json', import.meta.url), 'utf8'),
)
const t = (key) => nl[key] || key

const PUBLICATION = {
	id: 'p-1',
	'@self': { id: 'p-1', name: 'Besluit Stationsweg' },
	title: 'Besluit Stationsweg',
}

const FILE = {
	documentId: '4711',
	title: 'besluit.pdf',
	size: 120000,
	published: '2026-11-02T09:00:00+00:00',
	format: 'pdf',
	documentsoort: 'besluit',
	creationDate: '2026-10-30',
	downloadUrl: '/index.php/s/AbC123/download',
	metadataUrl: '/index.php/apps/opencatalogi/api/woo/p-1/documents/4711',
	diwooUrl: '/index.php/apps/opencatalogi/api/woo/p-1/documents/4711/diwoo',
}

/**
 * A stand-in for fetch answering from a map of URL to [status, body].
 *
 * @param {object} answers URL to [status, body].
 * @return {Function}
 */
function fakeFetch(answers) {
	const asked = []
	const fetcher = async (url) => {
		asked.push(url)
		const [status, body] = answers[url] || [404, null]
		return { ok: status >= 200 && status < 300, status, json: async () => body }
	}
	fetcher.asked = asked
	return fetcher
}

const BASE = '/index.php/apps/opencatalogi/api/federation/publications/p-1'

test('a document route names its publication and its document', () => {
	assert.deepEqual(documentRouteOf('/document/p-1/4711'), {
		publicationId: 'p-1',
		documentId: '4711',
	})
	assert.deepEqual(documentRouteOf('/document/p%201/47%2F11/'), {
		publicationId: 'p 1',
		documentId: '47/11',
	})
	assert.equal(documentRouteOf('/document/4711'), null)
	assert.equal(documentRouteOf('/document'), null)
	assert.equal(documentRouteOf('/document/p-1/4711/extra'), null)
	assert.equal(documentRouteOf('/document/%E0%A4%A/4711'), null)
	assert.equal(documentRouteOf('/publicatie/p-1'), null)
	assert.equal(isDocumentRoute('/document/p-1/4711'), true)
	assert.equal(isDocumentRoute(''), false)
})

test('a document page route is built from both ids, or not at all', () => {
	assert.equal(documentPageRoute('p-1', 4711), '/document/p-1/4711')
	assert.equal(documentPageRoute('p 1', '47/11'), '/document/p%201/47%2F11')
	assert.equal(documentPageRoute('', '4711'), '')
	assert.equal(documentPageRoute('p-1', ''), '')
	assert.equal(documentPageRoute('p-1', undefined), '')
	assert.equal(publicationPageRoute('p-1'), '/publicatie/p-1')
})

test('the facts are the metadata the read carries, in reading order', () => {
	assert.deepEqual(documentFacts(FILE, t), [
		{ label: 'Bestandstype', value: 'PDF' },
		{ label: 'Grootte', value: '120 kB' },
		{ label: 'Gepubliceerd op', value: '2 november 2026' },
		{ label: 'Documentsoort', value: 'besluit' },
		{ label: 'Opgesteld op', value: '30 oktober 2026' },
	])
	assert.deepEqual(documentFacts({ title: 'notitie', format: 'odt' }, t), [
		{ label: 'Bestandstype', value: 'ODT' },
	])
	assert.deepEqual(documentFacts(null, t), [])
})

test('the page is the row with the id, linked to its publication', () => {
	const page = documentPageOf({
		publication: PUBLICATION,
		attachments: { results: [{ ...FILE, documentId: '1' }, FILE] },
		publicationId: 'p-1',
		documentId: '4711',
		t,
	})
	assert.equal(page.document.id, '4711')
	assert.equal(page.document.title, 'besluit.pdf')
	assert.equal(page.document.href, FILE.downloadUrl)
	assert.equal(page.document.metadataUrl, FILE.metadataUrl)
	assert.equal(page.document.diwooUrl, FILE.diwooUrl)
	assert.deepEqual(page.publication, {
		id: 'p-1',
		title: 'Besluit Stationsweg',
		route: '/publicatie/p-1',
	})
})

test('no row with the id, another publication or no publication is not found', () => {
	const asked = { publicationId: 'p-1', documentId: '4711', t }
	assert.equal(
		documentPageOf({
			...asked,
			publication: PUBLICATION,
			attachments: { results: [{ ...FILE, documentId: '1' }] },
		}),
		null,
	)
	assert.equal(
		documentPageOf({ ...asked, publication: PUBLICATION, attachments: null }),
		null,
	)
	assert.equal(
		documentPageOf({
			...asked,
			publication: { id: 'p-2' },
			attachments: [FILE],
		}),
		null,
	)
	assert.equal(
		documentPageOf({ ...asked, publication: null, attachments: [FILE] }),
		null,
	)
	assert.notEqual(
		documentPageOf({ ...asked, publication: PUBLICATION, attachments: [FILE] }),
		null,
	)
})

test('a document of a non-public publication renders not found', async () => {
	// The anonymous read answers 404 for a draft: the attachments are never read.
	const fetcher = fakeFetch({
		[`${BASE}/attachments`]: [200, { results: [FILE] }],
	})
	const page = await loadDocumentPage({
		publicationId: 'p-1',
		documentId: '4711',
		t,
		fetcher,
	})
	assert.equal(page, null)
	assert.deepEqual(fetcher.asked, [BASE])

	const failing = async () => {
		throw new Error('offline')
	}
	assert.equal(
		await loadDocumentPage({
			publicationId: 'p-1',
			documentId: '4711',
			t,
			fetcher: failing,
		}),
		null,
	)
})

test('a public document is read through its publication', async () => {
	const fetcher = fakeFetch({
		[BASE]: [200, PUBLICATION],
		[`${BASE}/attachments`]: [200, { results: [FILE] }],
	})
	const page = await loadDocumentPage({
		publicationId: 'p-1',
		documentId: '4711',
		t,
		fetcher,
	})
	assert.equal(page.document.title, 'besluit.pdf')
	assert.deepEqual(fetcher.asked, [BASE, `${BASE}/attachments`])

	const missing = fakeFetch({
		[BASE]: [200, PUBLICATION],
		[`${BASE}/attachments`]: [500, null],
	})
	assert.equal(
		await loadDocumentPage({
			publicationId: 'p-1',
			documentId: '4711',
			t,
			fetcher: missing,
		}),
		null,
	)
})

test('the page links back to its publication', async () => {
	const component = await loadSfc('src/site/components/DocumentPage.vue')
	const page = documentPageOf({
		publication: PUBLICATION,
		attachments: [FILE],
		publicationId: 'p-1',
		documentId: '4711',
		t,
	})
	const html = await renderComponent(
		{
			...component,
			mounted() {},
			data() {
				return { page, loading: false }
			},
		},
		{
			publicationId: 'p-1',
			documentId: '4711',
			t,
			hrefForRoute: (route) => `/site${route}`,
		},
	)
	assert.match(html, /Onderdeel van/)
	assert.match(
		html,
		/<a[^>]*href="\/site\/publicatie\/p-1"[^>]*data-testid="document-page-publication"[^>]*>\s*Besluit Stationsweg\s*<\/a>/,
	)
	assert.match(html, /data-testid="document-page-title">\s*besluit\.pdf/)
	assert.match(html, /Gepubliceerd op/)
	assert.match(html, /href="\/index\.php\/s\/AbC123\/download"/)
	assert.match(html, /data-testid="document-page-metadata"/)
})

test('a page that found nothing renders nothing of the document', async () => {
	const component = await loadSfc('src/site/components/DocumentPage.vue')
	const html = await renderComponent(
		{
			...component,
			mounted() {},
			data() {
				return { page: null, loading: false }
			},
		},
		{ publicationId: 'p-1', documentId: '4711', t },
	)
	assert.doesNotMatch(html, /besluit|document-page-title|document-page-download/)
})

test('the publication page links each document to its page', async () => {
	const component = await loadSfc(
		'src/site/components/PublicationDetailBlock.vue',
		{
			'../../shared/i18n/index.js': `const nl = ${JSON.stringify(nl)}\nexport function createTranslator() { return (key) => nl[key] || key }\n`,
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
					documents: [
						{
							id: '4711',
							title: 'besluit.pdf',
							type: 'PDF',
							size: '120 kB',
							href: FILE.downloadUrl,
							view: '',
							metadataUrl: '',
							diwooUrl: '',
						},
						{
							id: '',
							title: 'zonder-id.pdf',
							type: 'PDF',
							size: '',
							href: '',
							view: '',
							metadataUrl: '',
							diwooUrl: '',
						},
					],
				}
			},
		},
		{ subjectId: 'p-1' },
	)
	const links = [...html.matchAll(/data-testid="publication-document-page"/g)]
	assert.equal(links.length, 1)
	assert.match(
		html,
		/href="\/document\/p-1\/4711"[^>]*data-testid="publication-document-page"/,
	)
})
