#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// schema-form-file-field.spec.mjs: a declared file field renders as a file
// picker, and the form creates the record before it uploads each file
// (assignment-portal-file-upload).
//
// Usage:
//   node --test tests/schema-form-file-field.spec.mjs
//
// The submit flow lives in src/shared/fileFieldSubmit.js so it can be
// driven here against a fake api: the live attach fails on a fresh instance
// (portaliq#29), so a browser run would prove the instance, not the form.
// SchemaForm is JSX; it is compiled with the same Babel preset
// webpack.portal.js uses and written to node_modules/.cache next to a .mjs
// copy of the submit module, which is where its relative import points.

import assert from 'node:assert/strict'
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'

const require = createRequire(import.meta.url)
const babel = require('@babel/core')
const React = require('react')
const { renderToStaticMarkup } = require('react-dom/server')

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const OUT_DIR = join(ROOT, 'node_modules', '.cache', 'portaliq-tests')
mkdirSync(OUT_DIR, { recursive: true })

const SUBMIT_SOURCE = join(ROOT, 'src', 'shared', 'fileFieldSubmit.js')
const SUBMIT_OUT = join(OUT_DIR, 'fileFieldSubmit.mjs')
writeFileSync(SUBMIT_OUT, readFileSync(SUBMIT_SOURCE, 'utf8'))

const FORM_SOURCE = join(ROOT, 'src', 'portal', 'components', 'SchemaForm.jsx')
const FORM_OUT = join(OUT_DIR, 'SchemaForm.mjs')
const compiled = babel.transformSync(readFileSync(FORM_SOURCE, 'utf8'), {
	filename: FORM_SOURCE,
	babelrc: false,
	configFile: false,
	presets: [['@babel/preset-react', { runtime: 'automatic' }]],
})
writeFileSync(FORM_OUT, compiled.code.replace("'../../shared/fileFieldSubmit.js'", "'./fileFieldSubmit.mjs'"))

const { default: SchemaForm } = await import(pathToFileURL(FORM_OUT).href)
const submit = await import(pathToFileURL(SUBMIT_OUT).href)

const action = {
	id: 'createSubmission',
	type: 'create',
	register: 'learniq',
	schema: 'submission',
	label: 'Hand in an assignment',
	fields: ['assignmentId', 'attachmentRefs'],
	fieldConfigs: {
		assignmentId: { label: 'Assignment', size: 'medium' },
		attachmentRefs: { label: 'Your work', type: 'file', multiple: true, accept: ['.pdf'], maxSizeMb: 1, size: 'medium' },
	},
}

/**
 * A stand-in for a browser File: a name and a size is all the flow reads.
 *
 * @param {string} name The file name.
 * @param {number} size The size in bytes.
 * @return {{name: string, size: number}} The file.
 */
function file(name, size = 10) {
	return { name, size }
}

/**
 * A fake portal api that records every call.
 *
 * @param {object} options How the fake answers.
 * @param {boolean} [options.createOk] Whether the create succeeds.
 * @param {string[]} [options.failNames] File names whose upload fails.
 * @return {object} The fake api with `calls`.
 */
function fakeApi({ createOk = true, failNames = [] } = {}) {
	const calls = { created: [], uploads: [] }
	return {
		calls,
		async createObject(a, body) {
			calls.created.push(body)
			return createOk ? { ok: true, object: { id: 'submission-1', ...body } } : { ok: false, status: 502, object: null }
		},
		async uploadFieldFile(a, id, field, f) {
			calls.uploads.push({ action: a.id, id, field, name: f.name })
			return failNames.includes(f.name) ? { ok: false, status: 502, error: 'upload_failed' } : { ok: true, file: { id: '4711' } }
		},
		async fetchOptions() {
			return []
		},
	}
}

test('renders a file input for a file field', () => {
	const html = renderToStaticMarkup(React.createElement(SchemaForm, { action, api: fakeApi(), t: (key, vars) => `${key}|${JSON.stringify(vars || {})}` }))

	assert.match(html, /<label for="f-createSubmission-attachmentRefs">Your work<\/label>/)
	assert.match(html, /<input id="f-createSubmission-attachmentRefs" type="file" multiple="" accept="\.pdf"\/>/)
	// The size limit is shown through the translator.
	assert.match(html, /Up to \{size\} MB per file\|\{&quot;size&quot;:1\}/)
	// The other field is still a text box.
	assert.match(html, /<input id="f-createSubmission-assignmentId" type="text"/)
})

test('a form without a translator still renders the English source', () => {
	const html = renderToStaticMarkup(React.createElement(SchemaForm, { action, api: fakeApi() }))

	assert.match(html, /Up to 1 MB per file/)
})

test('a single file field renders without multiple', () => {
	const single = { ...action, fieldConfigs: { attachmentRefs: { type: 'file', size: 'medium' } } }
	const html = renderToStaticMarkup(React.createElement(SchemaForm, { action: single, api: fakeApi() }))

	assert.match(html, /<input id="f-createSubmission-attachmentRefs" type="file"\/>/)
	assert.match(html, /Up to 20 MB per file/)
})

test('creates then uploads each file and names a failed one', async () => {
	const api = fakeApi({ failNames: ['bijlage.pdf'] })
	const result = await submit.submitWithFiles(
		api,
		action,
		{ assignmentId: 'assignment-1', attachmentRefs: 'C:\\fakepath\\essay.pdf' },
		{ attachmentRefs: [file('essay.pdf'), file('bijlage.pdf')] },
	)

	assert.equal(result.ok, true)
	assert.equal(result.id, 'submission-1')
	// The file field never travels in the create body.
	assert.deepEqual(api.calls.created, [{ assignmentId: 'assignment-1' }])
	assert.deepEqual(api.calls.uploads, [
		{ action: 'createSubmission', id: 'submission-1', field: 'attachmentRefs', name: 'essay.pdf' },
		{ action: 'createSubmission', id: 'submission-1', field: 'attachmentRefs', name: 'bijlage.pdf' },
	])
	assert.deepEqual(result.failed.map((f) => f.file.name), ['bijlage.pdf'])
})

test('a failed create uploads nothing', async () => {
	const api = fakeApi({ createOk: false })
	const result = await submit.submitWithFiles(api, action, { assignmentId: 'assignment-1' }, { attachmentRefs: [file('essay.pdf')] })

	assert.equal(result.ok, false)
	assert.deepEqual(api.calls.uploads, [])
})

test('a form with no picked file only creates', async () => {
	const api = fakeApi()
	const result = await submit.submitWithFiles(api, action, { assignmentId: 'assignment-1' }, {})

	assert.equal(result.ok, true)
	assert.deepEqual(result.failed, [])
	assert.deepEqual(api.calls.uploads, [])
})

test('oversized files are named before anything is saved', () => {
	const tooLarge = submit.oversizedFiles(action, { attachmentRefs: [file('small.pdf', 1024), file('huge.pdf', (2 * 1024 * 1024))] })

	assert.deepEqual(tooLarge, ['huge.pdf'])
	assert.deepEqual(submit.fileFields(action), ['attachmentRefs'])
})

test('the saved id is read wherever the server put it', () => {
	assert.equal(submit.objectIdOf({ id: 'a' }), 'a')
	assert.equal(submit.objectIdOf({ uuid: 'b' }), 'b')
	assert.equal(submit.objectIdOf({ '@self': { id: 'c' } }), 'c')
	assert.equal(submit.objectIdOf(null), '')
})
