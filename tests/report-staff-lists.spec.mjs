#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// report-staff-lists.spec.mjs: the staff lists of reports made without an
// account (portaliq/PtMeldingZonderAccount, intake-report-pages T08/T09).
// "Meldingen zonder account" lists the reports the caller handles and "Verzoeken
// om inzage" the reveal requests the caller guards, both read from portaliq's
// own routes and never from a generic OpenRegister index. Allowing asks for a
// reason and shows what the reporter gave once, in a dialog; refusing asks for
// a reason; neither ever puts a contact detail in a toast or the list.
//
// Usage:
//   node --test tests/report-staff-lists.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { createRevealHandlers, DECIDE_PATH } from '../src/lib/revealActions.js'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const NL = JSON.parse(read('l10n/nl.json')).translations
const page = (id) => MANIFEST.pages.find((entry) => entry.id === id)

/**
 * Handlers over recording collaborators.
 *
 * @param {object} options What they answer.
 * @return {{handlers: object, log: object}}
 */
function harness({ reason = 'Enige getuige', answer = { state: 'allowed', contact: { name: 'Jan', email: 'jan@example.org' } }, status = 200 } = {}) {
	const log = { posts: [], shown: [], notified: [], errors: [], reloads: 0 }
	const handlers = createRevealHandlers({
		post: async (url, body) => {
			log.posts.push([url, body])
			if (status >= 400) {
				const error = new Error('refused')
				error.response = { status, data: answer }
				throw error
			}
			return { data: answer }
		},
		generateUrl: (path, params = {}) => path.replace(/\{(\w+)\}/g, (_, key) => params[key]),
		askReason: async () => reason,
		showContact: async (contact) => log.shown.push(contact),
		notify: (text) => log.notified.push(text),
		notifyError: (text) => log.errors.push(text),
		translate: (text) => text,
		reload: () => { log.reloads++ },
	})
	return { handlers, log }
}

test('allowing posts the decision and shows the contact once, never in a toast', async () => {
	const { handlers, log } = harness()
	assert.equal(await handlers.allowReveal({ item: { id: 'q-1' } }), true)
	assert.deepEqual(log.posts, [[DECIDE_PATH.replace('{id}', 'q-1'), { allow: true, reason: 'Enige getuige' }]])
	assert.deepEqual(log.shown, [{ name: 'Jan', email: 'jan@example.org' }])
	assert.doesNotMatch(JSON.stringify(log.notified), /jan@example\.org|Jan/)
	assert.equal(log.reloads, 1)
})

test('refusing posts the decision with its reason and shows nothing', async () => {
	const { handlers, log } = harness({ answer: { state: 'refused' } })
	assert.equal(await handlers.refuseReveal({ item: { id: 'q-1' } }), true)
	assert.deepEqual(log.posts, [[DECIDE_PATH.replace('{id}', 'q-1'), { allow: false, reason: 'Enige getuige' }]])
	assert.deepEqual(log.shown, [])
})

test('a cancelled or empty reason sends nothing', async () => {
	for (const reason of [null, '   ']) {
		const { handlers, log } = harness({ reason })
		assert.equal(await handlers.allowReveal({ item: { id: 'q-1' } }), false)
		assert.deepEqual(log.posts, [])
	}
})

test('a refusal of the server is named', async () => {
	const notCustodian = harness({ status: 403, answer: { error: 'not_custodian' } })
	await notCustodian.handlers.allowReveal({ item: { id: 'q-1' } })
	assert.deepEqual(notCustodian.log.errors, ['Only the custodian of this kind of report may answer.'])
	const answered = harness({ status: 409, answer: { error: 'not_pending' } })
	await answered.handlers.refuseReveal({ item: { id: 'q-1' } })
	assert.deepEqual(answered.log.errors, ['This request was already answered.'])
})

test('the reports list reads the handlers\' route and opens nothing generic', () => {
	const reports = page('AccountlessReports')
	assert.equal(reports.route, '/accountless-reports')
	assert.equal(reports.config.entitySource, 'endpoint')
	assert.deepEqual(reports.config.sourceConfig, { url: '/apps/portaliq/api/reports', rowsPath: 'results', totalPath: 'total' })
	assert.equal(reports.config.register, undefined, 'no generic OpenRegister index')
	assert.deepEqual(reports.config.columns.map((c) => c.key), ['subject', 'state', 'receivedAt'])
})

test('the reveal requests list reads the custodian\'s route with allow and refuse', () => {
	const requests = page('RevealRequests')
	assert.equal(requests.route, '/reveal-requests')
	assert.equal(requests.config.entitySource, 'endpoint')
	assert.deepEqual(requests.config.sourceConfig, { url: '/apps/portaliq/api/reveal-requests', rowsPath: 'results', totalPath: 'total' })
	assert.deepEqual(requests.config.actions.map((a) => a.handler), ['allowReveal', 'refuseReveal'])
	for (const action of requests.config.actions) assert.ok(NL[action.label], action.label)
})

test('both sit in the menu under Submissions', () => {
	const ids = MANIFEST.menu.map((entry) => entry.route)
	for (const route of ['AccountlessReports', 'RevealRequests']) {
		assert.ok(ids.includes(route), `${route} is not in the menu`)
		const item = MANIFEST.menu.find((entry) => entry.route === route)
		assert.ok(NL[item.label], item.label)
	}
})
