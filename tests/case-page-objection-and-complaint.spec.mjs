#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// case-page-objection-and-complaint.spec.mjs: the actions a case app places on
// its case page (`onCase`). The case screen draws the server's list in "More
// actions for this case", a closed action as its sentence; the form leaves out
// the field naming the case and sends through `?onCase=`; a closed window
// shows the server's sentence and a sent form gives way to the receipt.
//
// Usage:
//   node --test tests/case-page-objection-and-complaint.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	caseActionForm,
	caseActionsOf,
	caseReference,
	receiptReference,
} from '../src/shared/caseActions.js'
import { createPortalApi } from '../src/shared/portalApi.js'

const { renderSfc, loadSfc } = await import('./support/render-sfc.mjs')

const SITE_CASE = 'src/site/components/e/CitizenCase.vue'
const SITE_FORM = 'src/site/components/e/CaseActionForm.vue'
const COLLECTION = { id: 'mijnZaken', register: 'dossiq', schema: 'zaak' }

/**
 * A translator that only fills placeholders.
 *
 * @param {string} key The string.
 * @param {object} vars The placeholder values.
 * @return {string} The filled string.
 */
function t(key, vars = {}) {
	return key.replace(/\{(\w+)\}/g, (_, name) => String(vars[name] ?? ''))
}

/**
 * One case action as the server lists it.
 *
 * @param {object} extra Keys over the default.
 * @return {object}
 */
function entry(extra = {}) {
	return {
		app: 'dossiq',
		action: 'createBezwaar',
		label: 'Bezwaar maken',
		open: true,
		crossRef: 'againstCaseId',
		form: {
			id: 'createBezwaar',
			type: 'create',
			register: 'dossiq',
			schema: 'portaalVerzoek',
			label: 'Bezwaar maken',
			fields: ['againstCaseId', 'grounds'],
			requiredFields: ['againstCaseId', 'grounds'],
			fieldConfigs: {
				againstCaseId: { label: 'Zaak' },
				grounds: { label: 'Uw gronden' },
			},
		},
		...extra,
	}
}

test('the form leaves out the field naming the case, everywhere it appears', () => {
	const form = caseActionForm(
		entry({
			form: {
				...entry().form,
				steps: [
					{ id: 's1', fields: ['againstCaseId'] },
					{ id: 's2', fields: ['grounds'] },
					{ id: 'review', review: true, fields: [] },
				],
			},
		}),
	)

	assert.deepEqual(form.fields, ['grounds'])
	assert.deepEqual(form.requiredFields, ['grounds'])
	assert.deepEqual(Object.keys(form.fieldConfigs), ['grounds'])
	assert.deepEqual(
		form.steps.map((step) => step.id),
		['s2', 'review'],
	)
})

test('only well formed entries are drawn, and the case reference needs every part', () => {
	assert.deepEqual(
		caseActionsOf({
			caseActions: [entry(), { action: '' }, null, { action: 'x' }],
		}).length,
		1,
	)
	assert.deepEqual(caseActionsOf({}), [])
	assert.equal(caseReference(COLLECTION, 'case-1'), 'dossiq/zaak/case-1')
	assert.equal(caseReference(COLLECTION, ''), '')
	assert.equal(caseReference(COLLECTION, 'a/b'), '')
	assert.equal(receiptReference({ reference: 'B-2026-0001' }), 'B-2026-0001')
	assert.equal(receiptReference({ id: 'uuid' }), '')
})

test('createObjectOnCase sends the action and the case in the query, and keeps a refusal sentence', async () => {
	const calls = []
	globalThis.window = {
		localStorage: { getItem: () => 'token-1', setItem() {}, removeItem() {} },
	}
	globalThis.fetch = async (url, init) => {
		calls.push({ url, init })
		return {
			ok: false,
			status: 409,
			json: async () => ({
				error: 'case_action_closed',
				message: 'De termijn voor bezwaar is voorbij.',
			}),
		}
	}
	const api = createPortalApi({ apiBase: '/apps/portaliq/portal/api' })

	const result = await api.createObjectOnCase(entry().form, 'dossiq/zaak/case-1', {
		grounds: 'Niet eens',
	})

	assert.equal(
		calls[0].url,
		'/apps/portaliq/portal/api/collections/dossiq/portaalVerzoek?actionId=createBezwaar&onCase=dossiq%2Fzaak%2Fcase-1',
	)
	assert.equal(calls[0].init.method, 'POST')
	assert.deepEqual(JSON.parse(calls[0].init.body), { grounds: 'Niet eens' })
	assert.equal(result.status, 409)
	assert.equal(result.error, 'case_action_closed')
	assert.equal(result.message, 'De termijn voor bezwaar is voorbij.')
})

test('site: the case screen draws the listed actions in their group, a closed one as its sentence', async () => {
	const html = await renderSfc(SITE_CASE, {
		api: {},
		t,
		collection: COLLECTION,
		row: { id: 'case-1' },
		initialData: {
			case: {},
			writableSet: { fields: {} },
			documents: [],
			withdrawal: { declared: true, open: true },
			caseActions: [
				entry(),
				entry({
					action: 'createKlacht',
					label: 'Klacht indienen',
					open: false,
					closedText: '',
				}),
			],
		},
	})

	assert.match(html, /role="group"[^>]*aria-label="More actions for this case"/)
	assert.match(
		html,
		/data-testid="case-action-createBezwaar"[^>]*>\s*Bezwaar maken\s*</,
	)
	assert.match(
		html,
		/data-testid="case-action-closed-createKlacht"[^>]*>\s*You can no longer do this on this case\.\s*</,
	)
	assert.doesNotMatch(html, /data-testid="case-action-createKlacht"/)
	// Withdraw comes after the case app's actions, inside the same group.
	assert.ok(
		html.indexOf('case-action-createBezwaar') < html.indexOf('case-withdraw'),
	)
})

test('site: a case app that places nothing shows no group and no sentence', async () => {
	const html = await renderSfc(SITE_CASE, {
		api: {},
		t,
		collection: COLLECTION,
		row: { id: 'case-1' },
		initialData: {
			case: {},
			writableSet: { fields: {} },
			documents: [],
			withdrawal: { declared: false },
		},
	})

	assert.doesNotMatch(html, /More actions for this case/)
	assert.doesNotMatch(html, /case-action-/)
})

test('site: the button opens the form and closes it again', async () => {
	const screen = await loadSfc(SITE_CASE)
	const vm = { openAction: '', formRound: 0, notice: 'old' }

	screen.methods.toggleAction.call(vm, entry())
	assert.equal(vm.openAction, 'createBezwaar')
	assert.equal(vm.notice, '')
	screen.methods.toggleAction.call(vm, entry())
	assert.equal(vm.openAction, '')
	screen.methods.toggleAction.call(vm, entry())
	assert.equal(vm.formRound, 2)
	screen.methods.closeAction.call(vm)
	assert.equal(vm.openAction, '')
})

test('site: the form sends through the case route; a closed window shows the server sentence', async () => {
	const form = await loadSfc(SITE_FORM)
	const sent = []
	const vm = {
		t,
		entry: entry(),
		collection: COLLECTION,
		caseId: 'case-1',
		closedText: '',
		api: {
			fetchOptions: () => [],
			createObjectOnCase: async (action, onCase, body) => {
				sent.push([action.id, onCase, body])
				return {
					ok: false,
					status: 409,
					error: 'case_action_closed',
					message: '',
				}
			},
		},
	}
	const adapter = form.computed.adapter.call(vm)

	assert.equal(typeof adapter.fetchOptions, 'function')
	await adapter.createObject(entry().form, { grounds: 'Te laat' })
	assert.deepEqual(sent, [
		['createBezwaar', 'dossiq/zaak/case-1', { grounds: 'Te laat' }],
	])
	assert.equal(vm.closedText, 'You can no longer do this on this case.')

	const done = { received: null, $emit() {} }
	form.methods.onSubmitted.call(done, { reference: 'K-2026-0311' })
	assert.deepEqual(done.received, { reference: 'K-2026-0311' })
})

test('site: the form opens on its heading and asks only what the resident writes', async () => {
	const html = await renderSfc(SITE_FORM, {
		t,
		entry: entry({
			form: { ...entry().form, successMessage: 'Uw klacht is ontvangen.' },
		}),
		collection: COLLECTION,
		caseId: 'case-1',
		api: {},
	})

	assert.match(html, /<h3[^>]*tabindex="-1"[^>]*>\s*Bezwaar maken\s*</)
	assert.match(html, /Uw gronden/)
	assert.doesNotMatch(html, />\s*Zaak\s*</)
})
