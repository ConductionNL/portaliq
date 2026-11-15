#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// invitation-resend.spec.mjs: "Opnieuw versturen" on an Invitations row
// (portaliq/PtUitnodigingen). It posts the row's resend route with the row's
// organisation, tells the clerk until when the new link works and never shows
// the secret, names each refusal, and is offered only on an invitation that
// is still open or ran out.
//
// Usage:
//   node --test tests/invitation-resend.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { createStaffAccountActions, createStaffAccountHandlers } from '../src/lib/staffAccountActions.js'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const NL = JSON.parse(read('l10n/nl.json')).translations
const ROUTES = read('appinfo/routes.php')
const SCHEMA = JSON.parse(read('lib/Settings/portaliq_register.json')).components.schemas.portalInvitation.properties

/**
 * The staff actions over a transport answering `answer`.
 *
 * @param {{status: number, data: object}} answer The answer to every POST.
 * @return {{actions: object, calls: Array}}
 */
function actionsAnswering(answer) {
	const calls = []
	const actions = createStaffAccountActions({
		post: async (url, body) => {
			calls.push({ url, body })
			if (answer.status >= 400) {
				const error = new Error('refused')
				error.response = answer
				throw error
			}
			return { data: answer.data }
		},
		generateUrl: (path, params = {}) => path.replace(/\{(\w+)\}/g, (_, key) => params[key]),
		translate: (text, vars = {}) => text.replace(/\{(\w+)\}/g, (_, key) => vars[key] ?? `{${key}}`),
		formatDate: (iso) => iso.slice(0, 10),
	})
	return { actions, calls }
}

test('sending again posts the resend route with the row organisation and never shows the secret', async () => {
	const { actions, calls } = actionsAnswering({ status: 200, data: { state: 'sent', expiresAt: '2026-10-27T09:00:00+00:00' } })
	const outcome = await actions.resendInvitation({ uuid: 'inv-1', email: 'ans@example.org', organisation: 'gemeente-x' })
	assert.deepEqual(calls, [{ url: '/apps/portaliq/api/invitations/inv-1/resend', body: { organisation: 'gemeente-x' } }])
	assert.equal(outcome.ok, true)
	assert.equal(outcome.message, 'Invitation sent again to ans@example.org. It is valid until 2026-10-27.')
})

test('each refusal is named', async () => {
	const said = async (error, status = 400) => (await actionsAnswering({ status, data: { error } }).actions.resendInvitation({ id: 'inv-1' })).message
	assert.equal(await said('already_accepted'), 'This invitation was already accepted, so it is not sent again.')
	assert.equal(await said('withdrawn'), 'This invitation was withdrawn. Invite the address again instead.')
	assert.equal(await said('mail_not_sent', 503), 'The invitation mail could not be sent, so nobody was invited. Try again.')
	assert.equal(await said('', 403), 'You may not issue or withdraw portal accounts. Ask an administrator for this right.')
	const empty = actionsAnswering({ status: 200, data: {} })
	assert.equal((await empty.actions.resendInvitation({})).ok, false)
	assert.equal(empty.calls.length, 0)
})

test('the row handler reports and reloads after a success only', async () => {
	const log = { notices: [], errors: [], reloads: 0 }
	const build = (outcome) => createStaffAccountHandlers({
		actions: { resendInvitation: async () => outcome },
		openIssue: async () => null,
		openInvite: async () => null,
		confirmWithdrawInvitation: async () => true,
		notify: (text) => log.notices.push(text),
		notifyError: (text) => log.errors.push(text),
		reload: () => { log.reloads++ },
	})
	assert.equal(await build({ ok: true, message: 'sent again' }).resendInvitation({ item: { id: 'inv-1' } }), true)
	assert.equal(await build({ ok: false, message: 'accepted' }).resendInvitation({ item: { id: 'inv-1' } }), false)
	assert.deepEqual(log, { notices: ['sent again'], errors: ['accepted'], reloads: 1 })
})

test('the Invitations list offers it on an open or expired invitation only', () => {
	const config = MANIFEST.pages.find((page) => page.id === 'Invitations').config
	const resend = config.actions.find((action) => action.handler === 'resendInvitation')
	assert.ok(resend, 'no resend action')
	assert.equal(resend.type, 'handler')
	assert.deepEqual(resend.visibleWhen, { field: 'state', op: 'in', value: ['sent', 'opened', 'expired'] })
	for (const state of resend.visibleWhen.value) assert.ok(SCHEMA.state.enum.includes(state), state)
	for (const text of [resend.label, 'This invitation was already accepted, so it is not sent again.', 'Invitation sent again to {email}. It is valid until {date}.', 'This invitation was withdrawn. Invite the address again instead.']) {
		assert.ok(NL[text], `"${text}" is not in the Dutch catalogue`)
	}
	assert.match(ROUTES, /'portalAccountAdmin#resendInvitation', 'url' => '\/api\/invitations\/\{id\}\/resend', 'verb' => 'POST'/)
})
