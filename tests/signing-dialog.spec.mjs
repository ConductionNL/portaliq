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
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'

const require = createRequire(import.meta.url)
const babel = require('@babel/core')
const { createElement } = require('react')
const { renderToStaticMarkup } = require('react-dom/server')

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const OUT_DIR = join(ROOT, 'node_modules', '.cache', 'portaliq-tests', 'signing')

/**
 * Compile one portal source file with the portal build's React preset, keeping
 * its path under src/portal so the relative imports between the compiled files
 * still resolve, and import it from where `react` resolves.
 *
 * @param {string} relative The path under src/portal.
 * @return {Promise<object>} The module.
 */
async function load(relative) {
	const source = join(ROOT, 'src', 'portal', relative)
	const compiled = babel.transformSync(readFileSync(source, 'utf8'), {
		filename: source,
		babelrc: false,
		configFile: false,
		presets: [['@babel/preset-react', { runtime: 'automatic' }]],
	})
	const out = join(OUT_DIR, relative.replace(/\.jsx$/, '.js'))
	mkdirSync(dirname(out), { recursive: true })
	writeFileSync(join(OUT_DIR, 'package.json'), '{"type":"module"}\n')
	writeFileSync(
		out,
		compiled.code.replace(/(from '\.{1,2}\/[^']+)\.jsx'/g, "$1.js'"),
	)
	return import(pathToFileURL(out).href)
}

// Dependencies first, so the compiled files they import exist.
await load('lib/rowAction.js')
const signing = await load('lib/signing.js')
const { createPortalApi } = await load('lib/portalApi.js')
const { default: SigningDialog } = await load('components/SigningDialog.jsx')
const { default: DeclineDialog } = await load('components/DeclineDialog.jsx')

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

test('the sign dialog without a way to show the document offers no sign button', () => {
	const html = renderToStaticMarkup(
		createElement(SigningDialog, {
			action: SIGN,
			viewAction: null,
			collection: COLLECTION,
			row: ROW,
			api: {},
			t,
		}),
	)
	assert.match(html, /This document cannot be shown here\./)
	assert.doesNotMatch(html, /I have read this document and I sign it\./)
	assert.doesNotMatch(html, /data-testid="signing-submit"/)
})

test('the sign dialog shows the document first, and the sign button waits for the tick', () => {
	const html = renderToStaticMarkup(
		createElement(SigningDialog, {
			action: SIGN,
			viewAction: VIEW,
			collection: COLLECTION,
			row: ROW,
			api: {},
			t,
			initialDocument: signing.documentView({
				ok: true,
				status: 200,
				body: {
					documentName: 'Huurcontract.pdf',
					mimeType: 'application/pdf',
					contentBase64: 'JVBERi0x',
				},
			}),
		}),
	)
	assert.match(html, /<object[^>]*data="data:application\/pdf;base64,JVBERi0x"/)
	assert.match(html, /download="Huurcontract.pdf"/)
	assert.match(html, /I have read this document and I sign it\./)
	assert.match(html, /<button[^>]*data-testid="signing-submit"[^>]*disabled=""/)
})

test('the decline dialog asks why', () => {
	const html = renderToStaticMarkup(
		createElement(DeclineDialog, {
			action: DECLINE,
			collection: COLLECTION,
			row: ROW,
			api: {},
			t,
		}),
	)
	assert.match(
		html,
		/<label for="decline-reason-req-1">Why do you decline\?<\/label>/,
	)
	assert.match(html, /Decline to sign/)
})

test('the page opens the sign and decline dialogs from a row', () => {
	const page = readFileSync(
		join(ROOT, 'src', 'portal', 'components', 'PageView.jsx'),
		'utf8',
	)
	assert.match(page, /import SigningDialog from '\.\/SigningDialog\.jsx'/)
	assert.match(page, /import DeclineDialog from '\.\/DeclineDialog\.jsx'/)
	assert.match(page, /dialogFor\(pending\.action\) === 'sign'/)
	assert.match(page, /dialogFor\(pending\.action\) === 'decline'/)
	assert.match(page, /tableRowActions\(/)
})

test('every new string has a Dutch translation', () => {
	const nl = JSON.parse(
		readFileSync(join(ROOT, 'src', 'portal', 'i18n', 'nl.json'), 'utf8'),
	)
	const en = JSON.parse(
		readFileSync(join(ROOT, 'src', 'portal', 'i18n', 'en.json'), 'utf8'),
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
