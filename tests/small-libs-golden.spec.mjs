#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// small-libs-golden.spec.mjs: how a document to sign is shown, how a
// resident's dossier collection and save bodies are read, what a forwarded
// resident action sends and answers, and which search suggestions an answer
// gives, pinned in a golden file before each was split into named steps.
//
// Usage:
//   node --test tests/small-libs-golden.spec.mjs
//   GOLDEN_RECORD=1 node --test tests/small-libs-golden.spec.mjs

import { test } from 'node:test'
import { documentView, INLINE_LIMIT } from '../src/shared/signing.js'
import { addToCollectionBody, dossierCollectionOf, postAction } from '../src/site/lib/residentActions.js'
import { toSuggestions } from '../src/site/lib/searchSuggestions.js'
import { golden } from './support/golden.mjs'

test('documents, dossiers, bodies and suggestions', async () => {
	const file = golden('small-libs')
	const big = 'A'.repeat(INLINE_LIMIT + 1)
	file.check('documentView', [
		null,
		{ ok: false, body: { contentBase64: 'QQ==' } },
		{ ok: true, body: null },
		{ ok: true, body: { contentBase64: '' } },
		{ ok: true, body: { contentBase64: 3 } },
		{ ok: true, body: { contentBase64: 'QQ==', mimeType: 'Application/PDF', documentName: 'Besluit.pdf' } },
		{ ok: true, body: { contentBase64: 'QQ==', mimeType: 'text/html', documentName: '' } },
		{ ok: true, body: { contentBase64: 'QQ==', mimeType: 3, documentName: 7 } },
		{ ok: true, body: { contentBase64: big, mimeType: 'image/png' } },
	].map((result) => {
		const view = documentView(result)
		return view.href ? { ...view, href: view.href.slice(0, 40) } : view
	}))
	const manifest = {
		contributions: [
			null,
			{ app: 'other', collections: [{ schema: 'dossier', id: 'x' }] },
			{ app: 'dossiq', collections: 'x' },
			{ app: 'dossiq', collections: [null, { schema: 'case' }, { schema: 'dossier', id: 7, register: null }] },
			{ app: 'dossiq', collections: [{ schema: 'dossier', id: 'later' }] },
		],
	}
	file.check('dossierCollectionOf', [
		dossierCollectionOf(manifest, 'dossiq', 'dossier'),
		dossierCollectionOf(manifest, 'dossiq', 'none'),
		dossierCollectionOf(null, 'dossiq', 'dossier'),
		dossierCollectionOf({ contributions: [{ app: 'a', collections: [{ schema: 0 }] }] }, 'a', 0),
	])
	file.check('addToCollectionBody', [
		{ collection: 'c1', publication: 'p', attachment: 0 },
		{ title: '  Mijn map ', publication: 3, attachment: '' },
		{ title: null, attachment: null },
		{ collection: '', title: 'T', attachment: undefined, publication: 'p' },
		{ collection: 5, attachment: 'doc' },
	].map((input) => addToCollectionBody(input)))
	const answers = []
	const calls = []
	const fetchOf = (respond) => async (url, init) => {
		calls.push([url, init.method, init.headers, init.body])
		return respond()
	}
	answers.push(await postAction({ authBase: '/api', appId: 'a b', actionId: 'save/1', body: { x: 1 }, token: 'tok', options: { submissionKey: 'k'.repeat(20) } }, fetchOf(() => ({ ok: true, status: 201, json: async () => ({ id: 1 }) }))))
	answers.push(await postAction({ authBase: '/api', appId: 'a', actionId: 'b' }, fetchOf(() => ({ ok: 1, status: 200, json: async () => null }))))
	answers.push(await postAction({ authBase: '/api', appId: 'a', actionId: 'b', token: '' }, fetchOf(() => ({ ok: false, status: 502, json: async () => { throw new Error('html') } }))))
	answers.push(await postAction({ authBase: '/api', appId: 'a', actionId: 'b' }, async () => { throw new Error('offline') }))
	file.check('postAction', { answers, calls })
	file.check('toSuggestions', [
		toSuggestions(null),
		toSuggestions({ results: 'x' }),
		toSuggestions({
			results: [
				null,
				{ '@self': { id: 's1', name: 'Self name' } },
				{ id: 'r2', title: ' Titel ' },
				{ id: 'r3', name: 'Naam', title: 'Titel', resultType: 'document' },
				{ id: 'r4' },
				{ name: 'no id' },
				{ id: 'r5', name: '  ' },
				{ id: 'r6', name: 'Zes' },
				{ id: 'r7', name: 'Zeven' },
				{ id: 'r8', name: 'Acht' },
				{ id: 'r9', name: 'Negen' },
			],
		}),
	])
	file.save()
})
