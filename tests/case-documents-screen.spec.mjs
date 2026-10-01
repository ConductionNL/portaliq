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

const { createPortalApi } = await load('lib/portalApi.js')
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
		const bundle = JSON.parse(readFileSync(join(ROOT, 'src', 'portal', 'i18n', `${locale}.json`), 'utf8'))
		for (const [key, dutch] of Object.entries(nl)) {
			assert.equal(bundle[key], locale === 'nl' ? dutch : key, `${locale}: ${key}`)
		}
	}
})
