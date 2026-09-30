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
import { test } from 'node:test'
import { humanSize, toDocuments } from '../src/site/lib/publicationDetail.js'

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
