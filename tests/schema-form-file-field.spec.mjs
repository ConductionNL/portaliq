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
// The form is the site's Vue SchemaForm, mounted in plain node.

import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as submit from '../src/shared/fileFieldSubmit.js'
import { mountSfc } from './support/mount-sfc.mjs'

const VUE_FORM = 'src/site/components/c/SchemaForm.vue'

const action = {
	id: 'createSubmission',
	type: 'create',
	register: 'learniq',
	schema: 'submission',
	label: 'Hand in an assignment',
	fields: ['assignmentId', 'attachmentRefs'],
	fieldConfigs: {
		assignmentId: { label: 'Assignment', size: 'medium' },
		attachmentRefs: {
			label: 'Your work',
			type: 'file',
			multiple: true,
			accept: ['.pdf'],
			maxSizeMb: 1,
			size: 'medium',
		},
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
			return createOk
				? { ok: true, object: { id: 'submission-1', ...body } }
				: { ok: false, status: 502, object: null }
		},
		async uploadFieldFile(a, id, field, f) {
			calls.uploads.push({ action: a.id, id, field, name: f.name })
			return failNames.includes(f.name)
				? { ok: false, status: 502, error: 'upload_failed' }
				: { ok: true, file: { id: '4711' } }
		},
		async fetchOptions() {
			return []
		},
	}
}

test('renders a file input for a file field, labelled, with the limit through the translator', async () => {
	const form = await mountSfc(VUE_FORM, {
		action,
		api: fakeApi(),
		t: (key, vars) => `${key}|${JSON.stringify(vars || {})}`,
	})
	const byId = (id) => form.findAll((n) => n.props.id === id)[0]
	const labelFor = (id) =>
		form.findAll((n) => n.tag === 'label' && n.props.for === id)[0]

	const picker = byId('f-createSubmission-attachmentRefs')
	assert.equal(picker.tag, 'input')
	assert.equal(picker.props.type, 'file')
	// An optional field says so inside its label (REQ-SMF-001).
	assert.equal(form.textOf(labelFor(picker.props.id)), 'Your work (optional)|{}')
	assert.equal(picker.props['aria-required'], undefined)
	// The size limit is shown through the translator.
	assert.match(form.text(), /Up to \{size\} MB per file\|\{"size":1\}/)
	// The other field is still a text box.
	assert.equal(byId('f-createSubmission-assignmentId').props.type, 'text')
})

test('a single file field renders without multiple, at the default limit', async () => {
	const single = {
		...action,
		fieldConfigs: { attachmentRefs: { type: 'file', size: 'medium' } },
	}
	const form = await mountSfc(VUE_FORM, { action: single, api: fakeApi() })
	const picker = form.findAll(
		(n) => n.props.id === 'f-createSubmission-attachmentRefs',
	)[0]

	assert.equal(picker.props.type, 'file')
	assert.equal(picker.props.multiple, false)
	assert.equal(picker.props.accept, undefined)
	assert.match(form.text(), /Up to 20 MB per file/)
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
		{
			action: 'createSubmission',
			id: 'submission-1',
			field: 'attachmentRefs',
			name: 'essay.pdf',
		},
		{
			action: 'createSubmission',
			id: 'submission-1',
			field: 'attachmentRefs',
			name: 'bijlage.pdf',
		},
	])
	assert.deepEqual(
		result.failed.map((f) => f.file.name),
		['bijlage.pdf'],
	)
})

test('a failed create uploads nothing', async () => {
	const api = fakeApi({ createOk: false })
	const result = await submit.submitWithFiles(
		api,
		action,
		{ assignmentId: 'assignment-1' },
		{ attachmentRefs: [file('essay.pdf')] },
	)

	assert.equal(result.ok, false)
	assert.deepEqual(api.calls.uploads, [])
})

test('a form with no picked file only creates', async () => {
	const api = fakeApi()
	const result = await submit.submitWithFiles(
		api,
		action,
		{ assignmentId: 'assignment-1' },
		{},
	)

	assert.equal(result.ok, true)
	assert.deepEqual(result.failed, [])
	assert.deepEqual(api.calls.uploads, [])
})

test('oversized files are named before anything is saved', () => {
	const tooLarge = submit.oversizedFiles(action, {
		attachmentRefs: [file('small.pdf', 1024), file('huge.pdf', 2 * 1024 * 1024)],
	})

	assert.deepEqual(tooLarge, ['huge.pdf'])
	assert.deepEqual(submit.fileFields(action), ['attachmentRefs'])
})

test('the saved id is read wherever the server put it', () => {
	assert.equal(submit.objectIdOf({ id: 'a' }), 'a')
	assert.equal(submit.objectIdOf({ uuid: 'b' }), 'b')
	assert.equal(submit.objectIdOf({ '@self': { id: 'c' } }), 'c')
	assert.equal(submit.objectIdOf(null), '')
})

// The site form driven end to end (site-reaches-portal-parity T11,
// REQ-SRP-023) on the shared flow from src/shared/fileFieldSubmit.js.

test('a form without a translator renders the English source and the picker options', async () => {
	const form = await mountSfc(VUE_FORM, {
		action,
		api: fakeApi(),
	})
	const picker = form.findAll(
		(n) => n.props.id === 'f-createSubmission-attachmentRefs',
	)[0]

	assert.equal(picker.props.type, 'file')
	assert.equal(picker.props.multiple, true)
	assert.equal(picker.props.accept, '.pdf')
	assert.match(
		form.textOf(form.find('schema-field-attachmentRefs')),
		/Your work \(optional\) Up to 1 MB per file/,
	)
})

test('the site form creates, uploads, names the failed file and retries only that one', async () => {
	const api = fakeApi({ failNames: ['bijlage.pdf'] })
	const form = await mountSfc(VUE_FORM, {
		action,
		api,
	})
	const field = (name) =>
		form.findAll((n) => n.props.id === `f-createSubmission-${name}`)[0]
	await form.fire(field('assignmentId'), 'input', { value: 'assignment-1' })
	await form.fire(field('attachmentRefs'), 'change', {
		files: [file('essay.pdf'), file('bijlage.pdf')],
	})
	await form.fire(form.find('schema-form'), 'submit')

	assert.deepEqual(api.calls.created, [{ assignmentId: 'assignment-1' }])
	assert.deepEqual(
		api.calls.uploads.map((u) => u.name),
		['essay.pdf', 'bijlage.pdf'],
	)
	assert.equal(
		form.textOf(form.find('schema-form-error')),
		'Saved, but these files were not attached: bijlage.pdf',
	)

	await form.fire(form.find('schema-form-retry'), 'click')
	assert.deepEqual(
		api.calls.uploads.map((u) => u.name),
		['essay.pdf', 'bijlage.pdf', 'bijlage.pdf'],
	)
	assert.equal(
		api.calls.created.length,
		1,
		'a retry never creates the record again',
	)
})

test('the site form refuses an oversized file before saving', async () => {
	const api = fakeApi()
	const form = await mountSfc(VUE_FORM, {
		action,
		api,
	})
	const picker = form.findAll(
		(n) => n.props.id === 'f-createSubmission-attachmentRefs',
	)[0]
	await form.fire(picker, 'change', { files: [file('huge.pdf', 2 * 1024 * 1024)] })
	await form.fire(form.find('schema-form'), 'submit')

	assert.deepEqual(api.calls.created, [])
	assert.equal(
		form.textOf(form.find('schema-form-error')),
		'These files are too large: huge.pdf',
	)
})
