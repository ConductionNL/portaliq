#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// form-save-and-resume.spec.mjs: saving asks first, reopening asks first,
// and Mijn zaken lists the unfinished forms (form-save-and-resume-dialogs,
// boards FormulierOpslaan, FormulierHervatten, MijnZaken).
//
// Usage:
//   node --test tests/form-save-and-resume.spec.mjs
//
// @spec openspec/changes/form-save-and-resume-dialogs/specs/site-forms/spec.md

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createPortalApi } from '../src/shared/portalApi.js'
import { formRoute } from '../src/site/components/forms/unfinished.js'
import { mountSfc } from './support/mount-sfc.mjs'
import { renderSfc } from './support/render-sfc.mjs'

const FORM = 'src/site/components/c/SchemaForm.vue'

const ACTION = {
	id: 'woo-request',
	label: 'Woo-verzoek',
	appId: 'dossiq',
	type: 'create',
	register: 'dossiq',
	schema: 'woo',
	fields: ['onderwerp', 'omschrijving'],
	fieldConfigs: {
		onderwerp: { label: 'Onderwerp', required: true },
		omschrijving: { label: 'Omschrijving', required: true },
	},
	steps: [
		{ id: 'one', title: 'Onderwerp', fields: ['onderwerp'] },
		{ id: 'two', title: 'Omschrijving', fields: ['omschrijving'] },
	],
	draft: { retentionDays: 30 },
}

/**
 * A portal api double that records the draft calls.
 *
 * @param {object|null} stored The draft getDraft answers.
 * @return {object} The api and its call log.
 */
function fakeApi(stored = null) {
	const calls = { saved: [], discarded: [] }
	return {
		calls,
		async fetchOptions() {
			return []
		},
		async getDraft() {
			return stored
		},
		async saveDraft(app, id, draft) {
			calls.saved.push({ app, id, draft })
			return { ...draft, id: 'draft-1', expiresAt: '2026-11-20T12:00:00+00:00' }
		},
		async discardDraft(app, id) {
			calls.discarded.push({ app, id })
			return true
		},
	}
}

test('saving asks first, names the retention, and stopping saves then leaves', async () => {
	const api = fakeApi()
	const form = await mountSfc(FORM, { action: ACTION, api, locale: 'nl' })
	await form.flush()

	await form.fire(form.find('schema-form-save-draft'), 'click')
	await form.flush()
	assert.ok(form.find('form-save-draft-dialog'), 'the dialog shows')
	assert.equal(api.calls.saved.length, 0, 'nothing is saved before the choice')
	assert.match(form.textOf(form.find('form-save-draft-keep')), /30 days/)

	await form.fire(form.find('form-save-draft-save'), 'click')
	await form.flush()
	assert.equal(api.calls.saved.length, 1)
	assert.equal(form.emitted.cancel?.length, 1, 'the form is left as on cancel')
})

test('continuing closes the dialog; stopping without saving saves nothing', async () => {
	const api = fakeApi()
	const form = await mountSfc(FORM, { action: ACTION, api, locale: 'nl' })
	await form.flush()

	await form.fire(form.find('schema-form-save-draft'), 'click')
	await form.flush()
	await form.fire(form.find('form-save-draft-continue'), 'click')
	await form.flush()
	assert.equal(form.find('form-save-draft-dialog'), null)
	assert.equal(form.emitted.cancel, undefined)

	await form.fire(form.find('schema-form-save-draft'), 'click')
	await form.flush()
	await form.fire(form.find('form-save-draft-leave'), 'click')
	await form.flush()
	assert.equal(api.calls.saved.length, 0)
	assert.equal(form.emitted.cancel?.length, 1)
})

test('reopening asks first; starting again discards the draft and leaves the fields empty', async () => {
	const api = fakeApi({
		id: 'draft-1',
		updatedAt: '2026-10-21T10:12:00',
		expiresAt: '2026-11-20T10:12:00+00:00',
		answers: { onderwerp: 'Rotonde' },
	})
	const form = await mountSfc(FORM, { action: ACTION, api, locale: 'nl' })
	await form.flush()

	const dialog = form.find('form-resume-draft-dialog')
	assert.ok(dialog, 'the question shows before anything is filled in')
	assert.match(form.textOf(dialog), /10\.12/)
	assert.equal(form.vm.values.onderwerp ?? '', '')

	await form.fire(form.find('form-resume-draft-restart'), 'click')
	await form.flush()
	assert.deepEqual(api.calls.discarded, [{ app: 'dossiq', id: 'woo-request' }])
	assert.equal(form.find('form-resume-draft-dialog'), null)
	assert.equal(form.vm.values.onderwerp ?? '', '')
})

test('Mijn zaken lists each live draft as an unfinished form that opens it', async () => {
	const html = await renderSfc('src/site/components/forms/UnfinishedForms.vue', {
		api: {},
		t: (key, vars = {}) => key.replace(/\{(\w+)\}/g, (_, name) => vars[name] ?? ''),
		locale: 'nl',
		initialDrafts: [
			{ app: 'dossiq', action: 'startWoo', label: 'Woo-verzoek', updatedAt: '2026-10-08T09:00:00', expiresAt: '2026-11-07T09:00:00' },
		],
	})
	assert.equal((html.match(/data-testid="unfinished-form"/g) || []).length, 1)
	assert.match(html, /Unfinished form/)
	assert.match(html, />Woo-verzoek</)
	assert.match(html, /Saved on 8 oktober 2026\. Not sent yet\./)
	assert.match(html, /data-testid="unfinished-form-open"[^>]*>\s*Finish the form/)
	assert.equal(formRoute({ app: 'dossiq', action: 'startWoo' }), '/mijn/dossiq/startWoo')

	const none = await renderSfc('src/site/components/forms/UnfinishedForms.vue', {
		api: {},
		t: (key) => key,
		initialDrafts: [],
	})
	assert.doesNotMatch(none, /unfinished-form/)
})

test('the list call reads the drafts, and is empty when it cannot', async () => {
	const realFetch = globalThis.fetch
	try {
		globalThis.fetch = async () => ({ ok: true, json: async () => ({ drafts: [{ app: 'a', action: 'b' }] }) })
		const api = createPortalApi({ apiBase: '/portal/api' }, { getToken: () => 'x' })
		assert.deepEqual(await api.listDrafts(), [{ app: 'a', action: 'b' }])
		globalThis.fetch = async () => ({ ok: false })
		assert.deepEqual(await api.listDrafts(), [])
		globalThis.fetch = async () => {
			throw new Error('offline')
		}
		assert.deepEqual(await api.listDrafts(), [])
	} finally {
		globalThis.fetch = realFetch
	}
})
