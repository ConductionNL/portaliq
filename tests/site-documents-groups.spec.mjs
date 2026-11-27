// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The documents block groups rows under a heading per value, marks new
// documents, shows a status pill, the provider's own line and an authored
// note (documents-grouped-per-record).
//
// @spec openspec/changes/documents-grouped-per-record/specs/site-mijn-omgeving/spec.md

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { eachRecordBlock, groupDocuments, paperLine, statusState } from '../src/site/components/mijn/documents.js'
import { collectionIdsFor } from '../src/site/pages/collections/collectionLoader.js'
import { instance, inState, t } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const DocumentsBlock = await loadSfc('src/site/components/mijn/DocumentsBlock.vue')
const ContributionPage = await loadSfc('src/site/pages/collections/ContributionPage.vue')

const ZAKEN = {
	id: 'mijnKinderen',
	register: 'learniq',
	schema: 'pupil',
	kind: 'cases',
	documents: { label: 'Documenten', provider: 'docs' },
}

const ROWS = [
	{ id: 'a', title: 'Rapport Vera', group: 'Groep 7', isNew: true },
	{ id: 'b', title: 'Rapport Sami', group: 'Groep 4' },
	{ id: 'c', title: 'Toestemming foto\'s', group: 'Groep 7', status: 'Ondertekend', statusTone: 'success', meta: 'Door u ingevuld op 28 augustus 2026' },
	{ id: 'd', title: 'Schoolgids' },
]

function render (block) {
  return renderComponent(inState(DocumentsBlock, { answer: { documents: ROWS }, failed: false }), {
		block: { type: 'documents', collection: 'mijnKinderen', ...block },
		collection: ZAKEN,
		record: { id: 'k-1' },
		locale: 'nl',
	})
}

test('rows group under a heading per value, in the provider order', () => {
	const groups = groupDocuments(ROWS, 'group')
	assert.deepEqual(groups.map((g) => g.heading), ['Groep 7', 'Groep 4', ''])
	assert.deepEqual(groups[0].entries.map((e) => e.id), ['a', 'c'])
	assert.equal(groupDocuments(ROWS, '').length, 1)
	assert.deepEqual(groupDocuments(null, 'group'), [])
})

test('the block renders the headings, the new badge, the status pill and the provider line', async () => {
	const html = await render({ groupBy: 'group' })
	assert.equal((html.match(/data-testid="mijn-documents-group"/g) || []).length, 3)
	assert.match(html, /Groep 7[\s\S]*Groep 4/)
	assert.equal((html.match(/data-testid="mijn-file-new"/g) || []).length, 1)
	assert.match(html, /Nieuw/)
	assert.match(html, /data-testid="mijn-file-status"[^>]*>(<!--\[-->)?Ondertekend/)
	assert.match(html, /Door u ingevuld op 28 augustus 2026/)
})

test('without groupBy the rows stay one flat list', async () => {
	const html = await render({})
	assert.equal((html.match(/data-testid="mijn-documents-group"/g) || []).length, 1)
	assert.doesNotMatch(html, /pq-documents-block__group-heading/)
})

test('the note shows under the list', async () => {
	const html = await render({ note: 'Het eerste rapport komt op vrijdag 12 februari 2027.' })
	assert.match(html, /data-testid="mijn-documents-note"[^>]*>\s*Het eerste rapport komt op vrijdag 12 februari 2027\./)
	assert.doesNotMatch(await render({}), /mijn-documents-note/)
})

test('a status tone the badge does not know reads neutral', () => {
	assert.equal(statusState({ statusTone: 'success' }), 'success')
	assert.equal(statusState({ statusTone: 'purple' }), 'neutral')
	assert.equal(statusState({}), 'neutral')
})

// documents-for-each-record: one group per record the subject may read.

const KINDEREN = { ...ZAKEN, kind: undefined }

function renderPaper (block, entry) {
	return renderComponent(inState(DocumentsBlock, { answer: { documents: [entry] }, failed: false }), {
		block: { type: 'documents', collection: 'mijnKinderen', ...block },
		collection: KINDEREN,
		record: { id: 'vera' },
		locale: 'nl',
	})
}

test('each record heads its group with its own title and subtitle, else the block\'s', () => {
	const block = { type: 'documents', collection: 'mijnKinderen', eachRecord: true, titleFields: ['givenName'], subtitleFields: ['groupLabel'], label: 'Rapporten' }
	const vera = eachRecordBlock(block, { id: 'vera', givenName: 'Vera', groupLabel: 'Groep 7' })
	assert.equal(vera.label, 'Vera')
	assert.equal(vera.subtitle, 'Groep 7')
	const school = eachRecordBlock({ ...block, titleFields: undefined, subtitleFields: undefined, label: 'Van school', subtitle: 'Voor alle ouders' }, { id: 's1', name: 'De Wilgenboom' })
	assert.equal(school.label, 'Van school')
	assert.equal(school.subtitle, 'Voor alle ouders')
})

test('a page loads the collection of a documents block for each record, and not of one on a record page', () => {
	const page = { blocks: [
		{ type: 'documents', collection: 'mijnKinderen', eachRecord: true },
		{ type: 'documents', collection: 'zaken' },
	] }
	assert.deepEqual(collectionIdsFor(page), ['mijnKinderen'])
})

test('a paper that is not a case\'s reads its day, type and size, without a sender', async () => {
	assert.equal(paperLine({ date: '2026-10-02', mimeType: 'application/pdf', size: 86016 }, 'nl'), '2 oktober 2026 · PDF, 84 kB')
	assert.equal(paperLine({ title: 'Schoolgids.pdf' }, 'nl'), 'PDF')
	const html = await renderPaper({ eachRecord: true, subtitle: 'Groep 7', label: 'Vera' }, { id: 'r1', title: 'Rapport 1', date: '2026-02-12', mimeType: 'application/pdf' })
	assert.match(html, /12 februari 2026 · PDF/)
	assert.doesNotMatch(html, /Van de gemeente/)
	assert.match(html, /data-testid="mijn-documents-subtitle"[^>]*>\s*Groep 7/)
	assert.match(html, /id="pq-documents-mijnKinderen-vera"/)
})

test('a group with nothing in it says the block\'s own words', async () => {
	const html = await renderComponent(inState(DocumentsBlock, { answer: { documents: [] }, failed: false }), {
		block: { type: 'documents', collection: 'mijnKinderen', emptyLabel: 'Er zijn nog geen rapporten.' },
		collection: KINDEREN,
		record: { id: 'sami' },
		locale: 'nl',
	})
	assert.match(html, /Er zijn nog geen rapporten\./)
})

test('a page that is no record page draws a group per child the guardian may read', () => {
	const kinderen = { id: 'parentChildren', register: 'learniq', schema: 'learner-profile', documents: { provider: 'childDocuments' } }
	const page = { id: 'parentDocuments', blocks: [{ type: 'documents', collection: 'parentChildren', eachRecord: true, titleFields: ['givenName'] }] }
	const rows = [{ id: 'vera', givenName: 'Vera' }, { id: 'sami', givenName: 'Sami' }]
	const ctx = instance(ContributionPage, {
		entry: { key: 'learniq:parentDocuments', page, contribution: { app: 'learniq', collections: [kinderen] } },
		t,
		initialData: { parentChildren: { loading: false, objects: rows } },
	})
	const [item] = ctx.blocks
	assert.equal(item.kind, 'documents')
	assert.deepEqual(ctx.loadedOf(item.collection).objects.map((row) => ctx.eachRecordBlockOf(item.block, row).label), ['Vera', 'Sami'])
	assert.equal(ctx.eachRowKey(rows[1]), 'sami')
})

// documents-grouped-per-record tasks 2 and 3: the media group for the
// signed-in person and the bundle button, loaded on demand.

const DocumentsExtras = await loadSfc('src/site/components/mijn/DocumentsExtras.vue')

function renderExtras (props) {
	return renderComponent(DocumentsExtras, { collection: ZAKEN, record: { id: 'k-1' }, locale: 'nl', ...props })
}

test('a media group renders its heading and media, and an empty one is left out', async () => {
	const html = await renderExtras({
		part: 'media',
		block: { type: 'documents', collection: 'mijnKinderen' },
		initialGroups: [
			{ label: 'Van school', register: 'school-media', schema: 'paper', entries: [{ id: 'm1', title: 'Schoolgids 2026-2027', mimeType: 'application/pdf' }] },
			{ label: 'Voor groep 5', register: 'school-media', schema: 'letter', entries: [] },
		],
	})
	assert.equal((html.match(/data-testid="mijn-documents-media-group"/g) || []).length, 1)
	assert.match(html, /Van school[\s\S]*Schoolgids 2026-2027/)
	assert.doesNotMatch(html, /Voor groep 5/)
})

test('the documents block places the extras only when it declares them', async () => {
	const html = await renderWith({})
	assert.doesNotMatch(html, /mijn-documents-bundle|mijn-documents-media-group/)
})

test('the media groups are read through the api, one read per declared group', async () => {
	const asked = []
	const api = { fetchMediaGroup: async (group) => { asked.push(group.schema); return group.schema === 'paper' ? [{ id: 'm1', title: 'Schoolgids' }] : null } }
	const ctx = instance(DocumentsExtras, {
		block: { type: 'documents', collection: 'mijnKinderen', extraGroups: [{ label: 'Van school', register: 'r', schema: 'paper' }, { label: 'Brieven', register: 'r', schema: 'letter' }] },
		api,
	})
	await ctx.loadGroups()
	assert.deepEqual(asked, ['paper', 'letter'])
	assert.deepEqual(ctx.groups.map((g) => g.entries.length), [1, 0])
	assert.deepEqual(ctx.shownGroups.map((g) => g.label), ['Van school'])
})

test('opening a medium asks for it by its group and id, and a failure says so', async () => {
	const opened = []
	const ctx = instance(DocumentsExtras, {
		block: { type: 'documents', collection: 'mijnKinderen' },
		api: { downloadMediaGroupFile: async (group, entry) => { opened.push([group.schema, entry.id]); return { ok: opened.length === 1 } } },
		initialGroups: [],
	})
	await ctx.openMedia({ register: 'r', schema: 'paper' }, 0, { id: 'm1' })
	assert.deepEqual(opened, [['paper', 'm1']])
	assert.equal(ctx.failed, false)
	await ctx.openMedia({ register: 'r', schema: 'paper' }, 0, { id: 'm2' })
	assert.equal(ctx.failed, true)
})

test('the bundle button shows its words and saves through the api', async () => {
	const block = { type: 'documents', collection: 'mijnKinderen', bulkDownload: { label: 'Alle geldige certificaten downloaden' } }
	const html = await renderExtras({ part: 'bundle', block, api: {} })
	assert.match(html, /data-testid="mijn-documents-bundle"[^>]*>\s*Alle geldige certificaten downloaden/)

	const saved = []
	const ctx = instance(DocumentsExtras, {
		part: 'bundle',
		block,
		collection: ZAKEN,
		record: { id: 'k-1' },
		api: { downloadDocumentsBundle: async (collection, id, name) => { saved.push([collection.id, id, name]); return { ok: false } } },
	})
	await ctx.downloadBundle()
	assert.deepEqual(saved, [['mijnKinderen', 'k-1', 'Alle geldige certificaten downloaden']])
	assert.equal(ctx.failed, true)
})

function renderWith (block, props = {}) {
	return renderComponent(inState(DocumentsBlock, { answer: { documents: ROWS }, failed: false }), {
		block: { type: 'documents', collection: 'mijnKinderen', ...block },
		collection: ZAKEN,
		record: { id: 'k-1' },
		locale: 'nl',
		...props,
	})
}

test('the extras calls go through the portal api\'s request door', async () => {
	const { documentsExtrasApi } = await import('../src/shared/documentsExtrasApi.js')
	const asked = []
	const calls = documentsExtrasApi({
		request: async (method, path, body, raw) => {
			asked.push([method, path, raw === true])
			return path.endsWith('/paper') ? { ok: true, status: 200, json: { documents: [{ id: 'm1' }] } } : { ok: false, status: 404 }
		},
	})
	assert.deepEqual(await calls.fetchMediaGroup({ register: 'school-media', schema: 'paper' }), [{ id: 'm1' }])
	assert.equal(await calls.fetchMediaGroup({ register: 'school-media', schema: 'letter' }), null)
	assert.deepEqual(await calls.downloadDocumentsBundle({ register: 'learniq', schema: 'certificate' }, 'c/1', 'Alles'), { ok: false, status: 404 })
	assert.deepEqual(await calls.downloadMediaGroupFile({ register: 'school-media', schema: 'paper' }, { id: 'm 1', title: 'Gids' }), { ok: false, status: 404 })
	assert.deepEqual(asked, [
		['GET', '/media-groups/school-media/paper', false],
		['GET', '/media-groups/school-media/letter', false],
		['GET', '/collections/learniq/certificate/c%2F1/documents-bundle', true],
		['GET', '/media-groups/school-media/paper/m%201', true],
	])
})
