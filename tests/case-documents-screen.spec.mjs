#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// case-documents-screen.spec.mjs: the documents on a resident's case
// (cases-documents-on-the-case). The screen groups what the server listed,
// decision first, then documents, then what the resident sent; each entry
// opens through one API call that names only the entry id; an empty case
// and a failed download each say so.
//
// Usage:
//   node --test tests/case-documents-screen.spec.mjs

import babel from '@babel/core'
import assert from 'node:assert/strict'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const OUT_DIR = join(ROOT, 'node_modules', '.cache', 'portaliq-tests-case-documents')

/**
 * Compile one file under src/portal and import it.
 *
 * @param {string} relative The path under src/portal.
 * @return {Promise<object>}
 */
async function load(relative) {
	const source = join(ROOT, 'src', 'portal', relative)
	const compiled = babel.transformSync(readFileSync(source, 'utf8'), { filename: source, babelrc: false, configFile: false, presets: [['@babel/preset-react', { runtime: 'automatic' }]] })
	mkdirSync(OUT_DIR, { recursive: true })
	const out = join(OUT_DIR, relative.replace(/[\\/]/g, '_').replace(/\.jsx?$/, '.mjs'))
	writeFileSync(out, compiled.code)
	return import(pathToFileURL(out).href)
}

const { createPortalApi } = await load('../shared/portalApi.js')
const { groupDocuments } = await import(pathToFileURL(join(ROOT, 'src', 'shared', 'caseDocuments.js')).href)

test('the listed documents are grouped: decisions, documents, sent by you', () => {
	const listed = [
		{ id: 'besluit-1', title: 'Besluit', kind: 'decision', date: '2026-09-01' },
		{ id: 'brief-1', title: 'Brief', kind: 'document', date: '2026-08-01' },
		{ id: 'upload:5', title: 'bewijs.pdf', kind: 'yours', date: '' },
	]
	assert.deepEqual(groupDocuments(listed), {
		decisions: [listed[0]],
		documents: [listed[1]],
		yours: [listed[2]],
		empty: false,
	})
	assert.deepEqual(groupDocuments([]), { decisions: [], documents: [], yours: [], empty: true })
	assert.deepEqual(groupDocuments(undefined), { decisions: [], documents: [], yours: [], empty: true })
})

test('a document opens through the case route, naming only the entry id, with the bearer', async () => {
	const calls = []
	const clicked = []
	globalThis.window = {
		localStorage: { getItem: () => 'token-1', setItem() {}, removeItem() {} },
		URL: { createObjectURL: () => 'blob:1', revokeObjectURL() {} },
	}
	globalThis.document = {
		createElement: () => ({ click() { clicked.push(this.download) }, remove() {} }),
		body: { appendChild() {} },
	}
	globalThis.setTimeout = () => 0
	globalThis.fetch = async (url, init) => {
		calls.push({ url, init })
		return { ok: true, status: 200, blob: async () => ({}) }
	}
	const api = createPortalApi({ apiBase: '/apps/portaliq/portal/api' })

	const result = await api.downloadCitizenDocument({ register: 'zaken', schema: 'zaak' }, 'zaak 1', { id: 'upload:5', title: 'bewijs.pdf' })

	assert.deepEqual(result, { ok: true })
	assert.equal(calls[0].url, '/apps/portaliq/portal/api/citizen/cases/zaken/zaak/zaak%201/documents/upload%3A5')
	assert.equal(calls[0].init.headers.Authorization, 'Bearer token-1')
	assert.deepEqual(clicked, ['bewijs.pdf'])

	globalThis.fetch = async () => ({ ok: false, status: 404 })
	assert.deepEqual(await api.downloadCitizenDocument({ register: 'zaken', schema: 'zaak' }, 'zaak-1', { id: 'x', title: 'x' }), { ok: false, status: 404 })
})

test('the case screen renders the groups and both locales say it', () => {
	const screen = readFileSync(join(ROOT, 'src', 'portal', 'components', 'CitizenCase.jsx'), 'utf8')
	assert.match(screen, /groupDocuments\(state\.data\.documents\)/)
	assert.match(screen, /api\.downloadCitizenDocument\(collection, caseId, entry\)/)
	assert.doesNotMatch(screen, /<li key=\{file\.id \|\| file\.name\} data-testid="case-document">\{file\.name\}<\/li>/, 'no plain unlinked list any more')
	const nl = {
		Decision: 'Besluit',
		'Sent by you': 'Door u gestuurd',
		'There are no documents on this case yet.': 'Er staan nog geen documenten bij deze zaak.',
		'The document could not be opened. Try again later.': 'Het document kon niet worden geopend. Probeer het later opnieuw.',
	}
	for (const locale of ['en', 'nl']) {
		const bundle = JSON.parse(readFileSync(join(ROOT, 'src', 'shared', 'i18n', `${locale}.json`), 'utf8'))
		for (const [key, dutch] of Object.entries(nl)) {
			assert.equal(bundle[key], locale === 'nl' ? dutch : key, `${locale}: ${key}`)
		}
	}
})

// The Vue port on the site (site-reaches-portal-parity T21, REQ-SRP-042).

const { renderSfc, loadSfc } = await import('./support/render-sfc.mjs')
const t = (key, vars = {}) => key.replace(/\{(\w+)\}/g, (_, name) => String(vars[name] ?? ''))
const SITE_CASE = 'src/site/components/e/CitizenCase.vue'
const COLLECTION = { id: 'mijnZaken', register: 'zaken', schema: 'zaak' }
const ANSWER = {
	case: { naam: 'Jansen', adres: 'Straat 1' },
	writableSet: {
		status: { label: 'In behandeling', description: 'We bekijken uw aanvraag.' },
		window: { open: true },
		fields: { naam: { writable: false, reason: 'Uw naam komt uit de BRP.' }, adres: { writable: true } },
		documents: { open: true },
	},
	documents: [
		{ id: 'upload:5', title: 'bewijs.pdf', kind: 'yours', date: '' },
		{ id: 'brief-1', title: 'Brief', kind: 'document', date: '2026-08-01' },
		{ id: 'besluit-1', title: 'Besluit', kind: 'decision', date: '2026-09-01' },
	],
}

test('site: the case shows its status, closed answers with their reason, open answers as inputs, and the documents grouped decision first', async () => {
	const html = await renderSfc(SITE_CASE, { api: {}, t, collection: COLLECTION, row: { id: 'zaak-1' }, initialData: ANSWER })
	assert.match(html, /data-testid="case-status"[\s\S]*In behandeling[\s\S]*We bekijken uw aanvraag\./)
	assert.match(html, /data-testid="case-value-naam"[^>]*>Jansen</)
	assert.match(html, /data-testid="case-reason-naam"[^>]*>Uw naam komt uit de BRP\.</)
	assert.match(html, /<label for="pq-case-field-adres"[^>]*>adres<\/label><input id="pq-case-field-adres"[^>]*value="Straat 1"/)
	assert.match(html, /data-testid="case-save" disabled/)
	const decision = html.indexOf('>Decision<')
	const documents = html.indexOf('>Documents<', html.indexOf('pq-case-documents-documents'))
	const yours = html.indexOf('>Sent by you<')
	assert.ok(decision > 0 && decision < documents && documents < yours, 'decision, then documents, then what you sent')
	assert.match(html, /<button[^>]*pq-case-document[^>]*>Besluit<\/button>/)
	assert.match(html, /<label for="pq-case-add-document"[^>]*>Add a document<\/label>/)
})

test('site: no documents says so, a closed window and a closed document slot give their reason, no row asks for one', async () => {
	const closed = {
		case: { naam: 'Jansen' },
		writableSet: { window: { open: false, reason: 'De termijn is voorbij.' }, documents: { open: false, reason: 'Er kan niets meer bij.' }, fields: {} },
		documents: [],
	}
	const html = await renderSfc(SITE_CASE, { api: {}, t, collection: COLLECTION, row: { id: 'zaak-1' }, initialData: closed })
	assert.match(html, /There are no documents on this case yet\./)
	assert.match(html, /data-testid="case-window-closed"[^>]*>De termijn is voorbij\.</)
	assert.match(html, /data-testid="case-documents-closed"[^>]*>Er kan niets meer bij\.</)
	assert.match(html, /This answer cannot be changed from the portal\./)
	assert.doesNotMatch(html, /case-save/)
	assert.doesNotMatch(html, /type="file"/)
	assert.match(await renderSfc(SITE_CASE, { api: {}, t, collection: COLLECTION, row: null }), /Select a case\./)
})

test('site: a document opens through the case route, a failure says so, and an added document is named', async () => {
	const screen = await loadSfc(SITE_CASE)
	const opened = []
	const vm = {
		t,
		collection: COLLECTION,
		caseId: 'zaak-1',
		notice: '',
		busy: false,
		draft: {},
		load() { this.loaded = true },
		api: {
			downloadCitizenDocument: async (c, id, entry) => { opened.push([c.id, id, entry.id]); return { ok: entry.id !== 'gone' } },
			addCitizenDocument: async (c, id, file) => ({ ok: true, document: { name: file.name } }),
			amendCitizenCase: async (c, id, fields) => ({ ok: false, message: `Niet: ${Object.keys(fields).join()}` }),
		},
	}
	await screen.methods.onOpenDocument.call(vm, { id: 'besluit-1' })
	assert.equal(vm.notice, '')
	await screen.methods.onOpenDocument.call(vm, { id: 'gone' })
	assert.equal(vm.notice, 'The document could not be opened. Try again later.')
	assert.deepEqual(opened, [['mijnZaken', 'zaak-1', 'besluit-1'], ['mijnZaken', 'zaak-1', 'gone']])

	const input = { files: [{ name: 'bewijs.pdf' }], value: 'C:\\bewijs.pdf' }
	await screen.methods.onAddDocument.call(vm, { target: input })
	assert.equal(vm.notice, 'bewijs.pdf has been added to your case.')
	assert.equal(input.value, '')
	assert.equal(vm.loaded, true, 'the case is read again so it shows under what you sent')

	screen.methods.onFieldChange.call(vm, 'adres', 'Laan 2')
	await screen.methods.onSave.call(vm)
	assert.equal(vm.notice, 'Niet: adres', 'a refusal is the server\'s sentence')
})
