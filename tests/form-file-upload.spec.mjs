#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// form-file-upload.spec.mjs: a landing-page form's file field
// (anonymous-form-file-upload). The visitor's files are checked against the
// field before the form is sent, the submit answer's one-time token comes
// back to the form, and each file then goes to the upload route with that
// token in its header; a file that does not arrive does not stop the others.
//
// Usage:
//   node --test tests/form-file-upload.spec.mjs
//
// @spec openspec/changes/anonymous-form-file-upload/specs/landing-page-provisioning/spec.md

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { acceptList, fileErrors, fitsAccept, pendingUploads } from '../src/site/lib/formFiles.js'

globalThis.window = { location: { origin: 'https://portaal.example.nl' }, PORTALIQ_SITE_CONFIG: { apiBase: '/apps/portaliq/api/content/site' } }
const { submitLandingPageForm, uploadFormFiles, UPLOAD_TOKEN_HEADER } = await import('../src/site/lib/formSubmission.js')

const photo = { id: 'photo', type: 'file', label: 'Foto', required: true, accept: '.jpg, image/png', maxSizeMb: 1 }
const papers = { id: 'papers', type: 'file', label: 'Stukken', multiple: true }

test('the accept line reads as a list, and fits like the browser attribute', () => {
	assert.deepEqual(acceptList(' .JPG, image/png ,'), ['.jpg', 'image/png'])
	assert.deepEqual(acceptList(['.pdf']), ['.pdf'])
	assert.equal(fitsAccept({ name: 'kat.JPG', type: 'image/jpeg' }, ['.jpg']), true)
	assert.equal(fitsAccept({ name: 'kat.gif', type: 'image/gif' }, ['image/*']), true)
	assert.equal(fitsAccept({ name: 'brief.pdf', type: 'application/pdf' }, ['.jpg', 'image/png']), false)
	assert.equal(fitsAccept({ name: 'wat dan ook', type: '' }, []), true)
})

test('a required file field without a file, a file too large and a file of another kind are said in words', () => {
	assert.deepEqual(fileErrors([photo], {}), { photo: 'Foto is verplicht. Kies een bestand.' })
	assert.deepEqual(fileErrors([photo], { photo: [{ name: 'groot.jpg', type: 'image/jpeg', size: 2 * 1024 * 1024 }] }), { photo: 'Foto: groot.jpg is groter dan 1 MB.' })
	assert.match(fileErrors([photo], { photo: [{ name: 'brief.pdf', type: 'application/pdf', size: 10 }] }).photo, /brief\.pdf is geen bestand van een soort die hier kan \(\.jpg, image\/png\)/)
	assert.deepEqual(fileErrors([photo, papers, { id: 'name', type: 'text', required: true }], { photo: [{ name: 'kat.jpg', type: 'image/jpeg', size: 10 }] }), {})
})

test('a field takes one file unless it allows several, in field order', () => {
	const files = { photo: [{ name: 'a.jpg' }, { name: 'b.jpg' }], papers: [{ name: 'c.pdf' }, { name: 'd.pdf' }] }
	assert.deepEqual(pendingUploads([photo, papers], files).map((upload) => `${upload.field}:${upload.file.name}`), ['photo:a.jpg', 'papers:c.pdf', 'papers:d.pdf'])
})

test('the submit answer hands the upload token back with the created object', async () => {
	globalThis.fetch = async () => ({ ok: true, status: 200, json: async () => ({ object: { reference: 'LEAD-8' }, uploadToken: 'tok-abc' }) })
	assert.deepEqual(await submitLandingPageForm({ name: 'Ada' }, {}, 'f-1'), { reference: 'LEAD-8', uploadToken: 'tok-abc' })

	globalThis.fetch = async () => ({ ok: true, status: 200, json: async () => ({ object: { reference: 'LEAD-9' } }) })
	assert.deepEqual(await submitLandingPageForm({ name: 'Ada' }, {}, 'f-1'), { reference: 'LEAD-9' })
})

test('each file goes to its field with the token in the header, and a refused one does not stop the others', async () => {
	const calls = []
	const send = async (url, init) => {
		calls.push({ url, init })
		return { ok: !url.endsWith('/photo') }
	}
	const outcome = await uploadFormFiles('tok-abc', [
		{ field: 'photo', file: new File(['x'], 'kat.jpg', { type: 'image/jpeg' }) },
		{ field: 'papers', file: new File(['y'], 'brief.pdf', { type: 'application/pdf' }) },
	], send)

	assert.deepEqual(outcome, { sent: ['brief.pdf'], failed: ['kat.jpg'] })
	assert.equal(calls[0].url, 'https://portaal.example.nl/apps/portaliq/portal/api/form-upload/photo')
	assert.equal(calls[1].init.headers[UPLOAD_TOKEN_HEADER], 'tok-abc')
	assert.equal(calls[1].init.method, 'POST')
	assert.equal(calls[1].init.body.get('file').name, 'brief.pdf')
})

test('FormBlock draws the file input, keeps the files out of the values and says a file that did not arrive', () => {
	const block = readFileSync(new URL('../src/site/components/FormBlock.vue', import.meta.url), 'utf8')
	assert.match(block, /v-else-if="field\.type === 'file'"/)
	assert.match(block, /type="file"/)
	assert.match(block, /@change="pickFiles\(field, \$event\)"/)
	assert.match(block, /this\.filesNotSent = await filesNotSentAfter\(answerFields\(this\.fields\), this\.files \|\| \{\}, created\)/)
	assert.match(block, /data-testid="form-files-not-sent"/)
	assert.doesNotMatch(block, /v-model="values\[field\.id\]"\s*\n\s*type="file"/)
})
