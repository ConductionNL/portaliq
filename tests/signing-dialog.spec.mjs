#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// signing-dialog.spec.mjs: signing and declining a document from its row
// (case-actions-sign-a-document T06, T07, T08). The sign step shows the
// document first, asks the resident to confirm they read it, and only then
// sends `{consent: true}` through the row-scoped forward; without a way to
// show the document it offers no sign button. Declining asks why.
//
// Usage:
//   node --test tests/signing-dialog.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { createPortalApi } from '../src/shared/portalApi.js'
import * as signing from '../src/shared/signing.js'
import { mountSfc } from './support/mount-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

const COLLECTION = {
	id: 'signerSigningRequests',
	register: 'filinq',
	schema: 'signingRequest',
}
const ROW = { id: 'req-1', documentName: 'Huurcontract.pdf', status: 'pending' }
const SIGN = {
	id: 'sign',
	label: 'Sign',
	endpoint: '/apps/filinq/api/portal/signing/sign',
	rowField: 'signingRequestId',
}
const DECLINE = {
	id: 'decline',
	label: 'Decline to sign',
	endpoint: '/apps/filinq/api/portal/signing/decline',
	rowField: 'signingRequestId',
}
const VIEW = {
	id: 'viewDocument',
	label: 'View document',
	endpoint: '/apps/filinq/api/portal/signing/viewDocument',
	rowField: 'signingRequestId',
}

/**
 * A translator that returns the English key with its placeholders filled.
 *
 * @param {string} key The English source string.
 * @param {object} vars The placeholder values.
 * @return {string} The text.
 */
function t(key, vars = {}) {
	return key.replace(/\{(\w+)\}/g, (_, name) => String(vars[name] ?? ''))
}

test('the row forward sends the answers the dialog collected', async () => {
	const calls = []
	globalThis.window = {
		localStorage: { getItem: () => 'token-1', setItem() {}, removeItem() {} },
	}
	globalThis.fetch = async (url, init) => {
		calls.push({ url: String(url), init })
		return { ok: true, status: 200, json: async () => ({ status: 'signed' }) }
	}
	const api = createPortalApi({ apiBase: '/apps/portaliq/portal/api' })
	await api.forwardRowAction(COLLECTION, 'req-1', 'sign', { consent: true })
	assert.equal(
		calls[0].url,
		'/apps/portaliq/portal/api/collections/filinq/signingRequest/req-1/actions/sign?collection=signerSigningRequests',
	)
	assert.deepEqual(JSON.parse(calls[0].init.body), { consent: true })
	await api.forwardRowAction(COLLECTION, 'req-1', 'pay')
	assert.deepEqual(JSON.parse(calls[1].init.body), {})
})

test('sign and decline open their own dialogs, other endpoint actions the plain confirm', () => {
	assert.equal(signing.dialogFor(SIGN), 'sign')
	assert.equal(signing.dialogFor(DECLINE), 'decline')
	assert.equal(
		signing.dialogFor({ id: 'pay', endpoint: '/x', rowField: 'r' }),
		'confirm',
	)
})

test('viewing the document is not a row button of its own', () => {
	assert.deepEqual(
		signing.tableRowActions([SIGN, DECLINE, VIEW]).map((a) => a.id),
		['sign', 'decline'],
	)
})

test('a document the receiver returned is shown, and a large one is offered as a download only', () => {
	const small = signing.documentView({
		ok: true,
		status: 200,
		body: {
			documentName: 'Huurcontract.pdf',
			mimeType: 'application/pdf',
			contentBase64: 'JVBERi0x',
		},
	})
	assert.equal(small.state, 'shown')
	assert.equal(small.inline, true)
	assert.equal(small.href, 'data:application/pdf;base64,JVBERi0x')
	assert.equal(small.name, 'Huurcontract.pdf')

	const large = signing.documentView({
		ok: true,
		status: 200,
		body: {
			documentName: 'Groot.pdf',
			mimeType: 'application/pdf',
			contentBase64: 'A'.repeat(signing.INLINE_LIMIT + 4),
		},
	})
	assert.equal(large.state, 'shown')
	assert.equal(large.inline, false)

	assert.equal(
		signing.documentView({ ok: false, status: 403, body: {} }).state,
		'unavailable',
	)
	assert.equal(
		signing.documentView({ ok: true, status: 200, body: {} }).state,
		'unavailable',
	)
	// Only a PDF or an image is rendered inline; anything else is a download.
	const other = signing.documentView({
		ok: true,
		status: 200,
		body: {
			documentName: 'x.html',
			mimeType: 'text/html',
			contentBase64: 'PGI+',
		},
	})
	assert.equal(other.inline, false)
	assert.equal(other.href, 'data:application/octet-stream;base64,PGI+')
})

test('the answer after signing or declining names the document, a refusal keeps the dialog open', () => {
	assert.deepEqual(
		signing.outcome('sign', { ok: true, status: 200 }, 'Huurcontract.pdf'),
		{
			done: true,
			key: 'You signed {documentName}.',
			vars: { documentName: 'Huurcontract.pdf' },
		},
	)
	assert.deepEqual(
		signing.outcome('decline', { ok: true, status: 200 }, 'Huurcontract.pdf'),
		{
			done: true,
			key: 'You declined to sign {documentName}.',
			vars: { documentName: 'Huurcontract.pdf' },
		},
	)
	const refused = signing.outcome(
		'sign',
		{ ok: false, status: 403 },
		'Huurcontract.pdf',
	)
	assert.equal(refused.done, false)
	assert.equal(refused.key, 'This can no longer be done for this item.')
})

test('the site page opens the sign and decline dialogs from a row', () => {
	const read = (path) => readFileSync(join(ROOT, path), 'utf8')
	const step = read('src/site/components/c/RowActionDialog.vue')
	assert.match(step, /import\('\.\.\/\.\.\/modals\/c\/SigningDialog\.vue'\)/)
	assert.match(step, /import\('\.\.\/\.\.\/modals\/c\/DeclineDialog\.vue'\)/)
	assert.match(step, /<SigningDialog\s+v-if="kind === 'sign'"/)
	assert.match(step, /<DeclineDialog\s+v-else-if="kind === 'decline'"/)
	const page = read('src/site/pages/collections/ContributionPage.vue')
	assert.match(page, /dialog: dialogFor\(action\)/)
	const blocks = read('src/site/pages/collections/pageBlocks.js')
	assert.match(blocks, /tableRowActions\(/)
})

test('every new string has a Dutch translation', () => {
	const nl = JSON.parse(
		readFileSync(join(ROOT, 'src', 'shared', 'i18n', 'nl.json'), 'utf8'),
	)
	const en = JSON.parse(
		readFileSync(join(ROOT, 'src', 'shared', 'i18n', 'en.json'), 'utf8'),
	)
	for (const key of [
		'Sign',
		'Decline to sign',
		'I have read this document and I sign it.',
		'Why do you decline?',
		'You signed {documentName}.',
		'You declined to sign {documentName}.',
		'This document cannot be shown here.',
		'Download {documentName}',
		'This document is too large to show here. Download it to read it.',
		'Give a reason.',
		'Loading the document…',
	]) {
		assert.ok(
			typeof nl[key] === 'string' && nl[key] !== '',
			`nl.json lacks "${key}"`,
		)
		assert.equal(en[key], key, `en.json lacks "${key}"`)
		assert.doesNotMatch(nl[key], /—/)
	}
})

// The site's Vue dialogs (site-reaches-portal-parity T13, REQ-SRP-029), in
// src/site/modals/c/ on the shared src/shared/signing.js.

/**
 * A fake api: the view answers a PDF, the sign and decline answer `answer`.
 *
 * @param {object} answer What sign and decline answer.
 * @return {object} The api with `calls`.
 */
function signingApi(answer) {
	const calls = []
	return {
		calls,
		async forwardRowAction(collection, rowId, actionId, answers = {}) {
			calls.push({ actionId, answers })
			if (actionId === 'viewDocument') {
				return {
					ok: true,
					status: 200,
					body: {
						contentBase64: 'JVBERi0=',
						mimeType: 'application/pdf',
						documentName: 'Huurcontract.pdf',
					},
				}
			}
			return answer
		},
	}
}

test('the site sign dialog shows the document, then signs only after the tick', async () => {
	const api = signingApi({ ok: true, status: 200, body: {} })
	const dialog = await mountSfc('src/site/modals/c/SigningDialog.vue', {
		action: SIGN,
		viewAction: VIEW,
		collection: COLLECTION,
		row: ROW,
		api,
		t,
	})
	await dialog.flush()

	const preview = dialog.findAll((n) => n.tag === 'object')[0]
	assert.equal(preview.props.data, 'data:application/pdf;base64,JVBERi0=')
	assert.equal(
		dialog.find('signing-download').props.href,
		'data:application/pdf;base64,JVBERi0=',
	)
	assert.equal(dialog.find('signing-download').props.download, 'Huurcontract.pdf')
	const consent = dialog.findAll(
		(n) => n.tag === 'label' && n.props.for === 'signing-read-req-1',
	)[0]
	assert.equal(dialog.textOf(consent), 'I have read this document and I sign it.')
	assert.equal(
		dialog.find('signing-submit').props.disabled,
		true,
		'no signing before the tick',
	)
	await dialog.fire(dialog.find('signing-read'), 'change', { checked: true })
	assert.equal(dialog.find('signing-submit').props.disabled, false)
	await dialog.fire(dialog.find('signing-submit'), 'click')

	assert.deepEqual(
		api.calls.map((c) => c.actionId),
		['viewDocument', 'sign'],
	)
	assert.deepEqual(api.calls[1].answers, { consent: true })
	assert.equal(
		dialog.textOf(dialog.find('signing-status')),
		'You signed Huurcontract.pdf.',
	)
	assert.equal(dialog.emitted.done.length, 1)
})

test('the site sign dialog without a view action has nothing to sign', async () => {
	const dialog = await mountSfc('src/site/modals/c/SigningDialog.vue', {
		action: SIGN,
		viewAction: null,
		collection: COLLECTION,
		row: ROW,
		api: signingApi({}),
		t,
	})
	assert.equal(
		dialog.textOf(dialog.find('signing-unavailable')),
		'This document cannot be shown here.',
	)
	assert.doesNotMatch(dialog.text(), /I have read this document and I sign it\./)
	assert.equal(dialog.find('signing-submit'), null)
})

test('the site decline dialog asks why, forwards the reason and keeps a refusal open', async () => {
	const refused = signingApi({ ok: false, status: 409, body: {} })
	const dialog = await mountSfc('src/site/modals/c/DeclineDialog.vue', {
		action: DECLINE,
		collection: COLLECTION,
		row: ROW,
		api: refused,
		t,
	})
	const form = dialog.findAll((n) => n.tag === 'form')[0]
	const why = dialog.findAll(
		(n) => n.tag === 'label' && n.props.for === 'decline-reason-req-1',
	)[0]
	assert.equal(dialog.textOf(why), 'Why do you decline?')
	assert.equal(dialog.find('decline-reason').props.id, 'decline-reason-req-1')
	assert.match(dialog.text(), /Decline to sign/)

	await dialog.fire(form, 'submit')
	assert.equal(refused.calls.length, 0)
	assert.equal(dialog.textOf(dialog.find('decline-status')), 'Give a reason.')

	await dialog.fire(dialog.find('decline-reason'), 'input', {
		value: ' Verkeerde datum ',
	})
	await dialog.fire(form, 'submit')
	assert.deepEqual(refused.calls, [
		{ actionId: 'decline', answers: { reason: 'Verkeerde datum' } },
	])
	assert.equal(
		dialog.textOf(dialog.find('decline-status')),
		'This can no longer be done for this item.',
	)
	assert.ok(dialog.find('decline-submit'), 'a refused decline stays open')
})

test('the site row action step opens the sign dialog with the view action', async () => {
	const api = signingApi({ ok: true, status: 200, body: {} })
	const step = await mountSfc('src/site/components/c/RowActionDialog.vue', {
		action: SIGN,
		rowActions: [SIGN, DECLINE, VIEW],
		collection: COLLECTION,
		row: ROW,
		api,
		t,
	})
	await step.flush()
	assert.ok(step.find('signing-dialog'))
	assert.deepEqual(
		api.calls.map((c) => c.actionId),
		['viewDocument'],
	)

	const decline = await mountSfc('src/site/components/c/RowActionDialog.vue', {
		action: DECLINE,
		rowActions: [SIGN, DECLINE, VIEW],
		collection: COLLECTION,
		row: ROW,
		api,
		t,
	})
	await decline.flush()
	assert.ok(decline.find('decline-dialog'))
})
