#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// staff-account-screens.spec.mjs: the admin screens of
// identity-staff-account-screens (T04-T07). Issuing an account goes through
// the provision route and names a duplicate identity; an invitation is sent,
// listed and withdrawn; only a pending account can be withdrawn; the
// registration policy is saved on the portal record and the registrations
// waiting for approval are approved or refused. The wiring tests read the
// manifest, the handler map and the widget registry.
//
// Usage:
//   node --test tests/staff-account-screens.spec.mjs

import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import {
	createRegistrationSettings,
	parseDomains,
	portalWithRegistration,
	registrationOf,
} from '../src/lib/registrationSettings.js'
import {
	canWithdrawAccount,
	createStaffAccountActions,
	createStaffAccountHandlers,
} from '../src/lib/staffAccountActions.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

/**
 * A transport that records each call and answers from a table.
 *
 * @param {(method: string, url: string, body: object) => {status: number, data: object}} answer The answer per call.
 * @return {{calls: Array, get: Function, post: Function, put: Function}}
 */
function transport(answer = () => ({ status: 200, data: {} })) {
	const calls = []
	const send = async (method, url, body) => {
		calls.push({ method, url, body })
		const { status, data } = answer(method, url, body)
		if (status >= 400) {
			const error = new Error('HTTP ' + status)
			error.response = { status, data }
			throw error
		}
		return { data }
	}
	return {
		calls,
		get: (url) => send('GET', url),
		post: (url, body) => send('POST', url, body),
		put: (url, body) => send('PUT', url, body),
	}
}

/**
 * Nextcloud's URL generator, reduced to placeholder substitution.
 *
 * @param {string} path The path with `{name}` placeholders.
 * @param {object} params The values.
 * @return {string}
 */
function generateUrl(path, params = {}) {
	return path.replace(/\{(\w+)\}/g, (all, name) => encodeURIComponent(params[name] ?? ''))
}

/**
 * The staff account actions over a transport.
 *
 * @param {object} http The transport.
 * @return {object}
 */
function staffActions(http) {
	return createStaffAccountActions({
		post: http.post,
		generateUrl,
		translate: (text, vars = {}) =>
			text.replace(/\{(\w+)\}/g, (all, name) => vars[name] ?? all),
		formatDate: (iso) => String(iso).slice(0, 10),
	})
}

test('issuing an account posts the validated provision route and says it is issued', async () => {
	const http = transport(() => ({ status: 200, data: { subjectRef: 's-1', isNew: true, status: 'pending' } }))
	const outcome = await staffActions(http).issue({
		audience: 'client',
		organisation: 'gemeente-x',
		email: ' ans@example.org ',
		verifiedEmail: true,
		displayName: 'Ans',
	})
	assert.equal(http.calls.length, 1)
	assert.equal(http.calls[0].url, '/apps/portaliq/api/accounts/provision')
	assert.deepEqual(http.calls[0].body, {
		audience: 'client',
		organisation: 'gemeente-x',
		identityType: '',
		identityRef: '',
		email: 'ans@example.org',
		verifiedEmail: true,
		displayName: 'Ans',
	})
	assert.equal(outcome.ok, true)
	assert.match(outcome.message, /issued/)
})

test('a duplicate identity is refused in words and no second account is claimed', async () => {
	const http = transport(() => ({ status: 200, data: { subjectRef: 's-old', isNew: false, status: 'active' } }))
	const outcome = await staffActions(http).issue({
		audience: 'client',
		organisation: 'gemeente-x',
		identityType: 'bsn',
		identityRef: '999993653',
	})
	assert.equal(outcome.ok, false)
	assert.equal(outcome.message, 'An account for this identity already exists, so no second account was made.')
})

test('an issue without organisation or identity posts nothing and says what is missing', async () => {
	const http = transport()
	const actions = staffActions(http)
	assert.equal((await actions.issue({ audience: 'client', email: 'a@b.nl' })).ok, false)
	const outcome = await actions.issue({ audience: 'client', organisation: 'gemeente-x' })
	assert.equal(outcome.ok, false)
	assert.equal(outcome.message, 'Give an identity reference or an e-mail address.')
	assert.equal(http.calls.length, 0)
})

test('a refused or forbidden issue says why', async () => {
	const refused = await staffActions(transport(() => ({ status: 400, data: { error: 'refused' } }))).issue({
		audience: 'client', organisation: 'gemeente-x', email: 'a@b.nl',
	})
	assert.equal(refused.message, 'The account could not be issued. Check the organisation and the identity.')
	const forbidden = await staffActions(transport(() => ({ status: 403, data: { error: 'forbidden' } }))).issue({
		audience: 'client', organisation: 'gemeente-x', email: 'a@b.nl',
	})
	assert.equal(forbidden.message, 'You may not issue or withdraw portal accounts. Ask an administrator for this right.')
})

test('an invitation is sent and the clerk reads its expiry, never a link', async () => {
	const http = transport(() => ({ status: 200, data: { state: 'sent', expiresAt: '2026-10-07T09:00:00+00:00' } }))
	const outcome = await staffActions(http).invite({ email: 'piet@leverancier.nl', organisation: 'gemeente-x', audience: 'client' })
	assert.equal(http.calls[0].url, '/apps/portaliq/api/invitations')
	assert.deepEqual(http.calls[0].body, { email: 'piet@leverancier.nl', organisation: 'gemeente-x', audience: 'client' })
	assert.equal(outcome.ok, true)
	assert.equal(outcome.message, 'Invitation sent to piet@leverancier.nl. It is valid until 2026-10-07.')
	assert.doesNotMatch(outcome.message, /token|http/)
})

test('an invitation whose mail was not sent says nobody was invited', async () => {
	const outcome = await staffActions(transport(() => ({ status: 503, data: { error: 'mail_not_sent' } }))).invite({
		email: 'piet@leverancier.nl', organisation: 'gemeente-x',
	})
	assert.equal(outcome.ok, false)
	assert.equal(outcome.message, 'The invitation mail could not be sent, so nobody was invited. Try again.')
})

test('withdrawing an invitation posts its revoke route with the row organisation', async () => {
	const http = transport(() => ({ status: 200, data: { state: 'revoked' } }))
	const outcome = await staffActions(http).withdrawInvitation({ uuid: 'inv-1', organisation: 'gemeente-x' })
	assert.equal(http.calls[0].url, '/apps/portaliq/api/invitations/inv-1/revoke')
	assert.deepEqual(http.calls[0].body, { organisation: 'gemeente-x' })
	assert.equal(outcome.ok, true)
})

test('an accepted invitation cannot be withdrawn and the clerk reads why', async () => {
	const outcome = await staffActions(transport(() => ({ status: 400, data: { error: 'already_accepted' } }))).withdrawInvitation({
		id: 'inv-2', organisation: 'gemeente-x',
	})
	assert.equal(outcome.ok, false)
	assert.equal(outcome.message, 'This invitation was already accepted, so it cannot be withdrawn.')
})

test('only a pending account offers withdrawal, and withdrawing needs a reason', async () => {
	assert.equal(canWithdrawAccount({ status: 'pending' }), true)
	assert.equal(canWithdrawAccount({ status: 'active' }), false)
	assert.equal(canWithdrawAccount({ status: 'void' }), false)
	assert.equal(canWithdrawAccount(null), false)

	const http = transport(() => ({ status: 200, data: { status: 'void' } }))
	const actions = staffActions(http)
	assert.equal((await actions.voidAccount({ subjectRef: 's-1' }, '  ')).ok, false)
	assert.equal(http.calls.length, 0)
	const outcome = await actions.voidAccount({ subjectRef: 's-1' }, ' Wrong address ')
	assert.equal(http.calls[0].url, '/apps/portaliq/api/accounts/void')
	assert.deepEqual(http.calls[0].body, { subjectRef: 's-1', reason: 'Wrong address' })
	assert.equal(outcome.ok, true)

	const used = await staffActions(transport(() => ({ status: 400, data: { error: 'not_pending' } }))).voidAccount({ subjectRef: 's-1' }, 'x')
	assert.equal(used.message, 'Only an account that was never used can be withdrawn.')
})

/**
 * The row handlers over recording collaborators.
 *
 * @param {object} overrides Collaborators to replace.
 * @return {{handlers: object, calls: object}}
 */
function handlers(overrides = {}) {
	const calls = { notices: [], errors: [], reloads: 0, withdrawn: [] }
	const built = createStaffAccountHandlers({
		actions: {
			issue: async () => ({ ok: true, message: 'issued' }),
			invite: async () => ({ ok: true, message: 'sent' }),
			withdrawInvitation: async (row) => {
				calls.withdrawn.push(row)
				return { ok: true, message: 'withdrawn' }
			},
		},
		openIssue: async (submit) => (await submit({})).message,
		openInvite: async (submit) => (await submit({})).message,
		confirmWithdrawInvitation: async () => true,
		notify: (text) => calls.notices.push(text),
		notifyError: (text) => calls.errors.push(text),
		reload: () => {
			calls.reloads++
		},
		...overrides,
	})
	return { handlers: built, calls }
}

test('the header actions open their dialog and reload after a success only', async () => {
	const { handlers: h, calls } = handlers()
	assert.equal(await h.issueAccount(), true)
	assert.equal(await h.inviteSomeone(), true)
	assert.deepEqual(calls.notices, ['issued', 'sent'])
	assert.equal(calls.reloads, 2)

	const cancelled = handlers({ openIssue: async () => null })
	assert.equal(await cancelled.handlers.issueAccount(), false)
	assert.equal(cancelled.calls.reloads, 0)
})

test('withdraw invitation asks first, and a refusal is shown without a reload', async () => {
	const declined = handlers({ confirmWithdrawInvitation: async () => false })
	assert.equal(await declined.handlers.withdrawInvitation({ item: { id: 'inv-1' } }), false)
	assert.equal(declined.calls.withdrawn.length, 0)

	const refused = handlers({
		actions: { withdrawInvitation: async () => ({ ok: false, message: 'accepted' }) },
	})
	assert.equal(await refused.handlers.withdrawInvitation({ item: { id: 'inv-1' } }), false)
	assert.deepEqual(refused.calls.errors, ['accepted'])
	assert.equal(refused.calls.reloads, 0)

	const done = handlers()
	assert.equal(await done.handlers.withdrawInvitation({ item: { id: 'inv-1' } }), true)
	assert.deepEqual(done.calls.withdrawn, [{ id: 'inv-1' }])
	assert.equal(done.calls.reloads, 1)
})

const PORTAL = {
	'@self': { id: 'p-1', updated: '2026-09-30T10:00:00+00:00' },
	id: 'p-1',
	title: 'Open Tilburg',
	slug: 'open-tilburg',
	status: 'published',
	organisation: 'gemeente-x',
	authentication: { modes: ['public', 'oidc'], minTrust: 'low' },
}

test('the registration of a portal reads off when it declares none', () => {
	assert.deepEqual(registrationOf(PORTAL), { policy: 'off', allowedDomains: [] })
	assert.deepEqual(
		registrationOf({ authentication: { registration: { policy: 'approval', allowedDomains: ['x.nl'] } } }),
		{ policy: 'approval', allowedDomains: ['x.nl'] },
	)
	assert.deepEqual(registrationOf({ authentication: { registration: { policy: 'nonsense' } } }).policy, 'off')
})

test('allowed domains are one per line, lower case, without @, without repeats', () => {
	assert.deepEqual(parseDomains(' Gemeente-X.nl\n@leverancier.nl, gemeente-x.nl\n\n'), ['gemeente-x.nl', 'leverancier.nl'])
	assert.deepEqual(parseDomains(''), [])
})

test('the saved portal keeps its other sign-in settings and drops the envelope', () => {
	const saved = portalWithRegistration(PORTAL, { policy: 'approval', allowedDomains: ['gemeente-x.nl'] })
	const fixture = JSON.parse(readFileSync(join(ROOT, 'tests/fixtures/registration-save.json'), 'utf8'))
	// The same body the PHP test validates against the real portal schema.
	assert.deepEqual(saved, fixture)
	assert.equal('@self' in saved, false)
	assert.deepEqual(saved.authentication.modes, ['public', 'oidc'])
})

test('saving reads the portal fresh, writes it back and says so', async () => {
	const http = transport((method) => (method === 'GET' ? { status: 200, data: PORTAL } : { status: 200, data: {} }))
	const settings = createRegistrationSettings({ get: http.get, put: http.put, post: http.post, url: generateUrl, translate: (t) => t })
	const outcome = await settings.save('p-1', { policy: 'activation', allowedDomains: [] })
	assert.deepEqual(http.calls.map((c) => c.method + ' ' + c.url), [
		'GET /apps/openregister/api/objects/portaliq/portal/p-1',
		'PUT /apps/openregister/api/objects/portaliq/portal/p-1',
	])
	assert.equal(http.calls[1].body.authentication.registration.policy, 'activation')
	assert.equal(outcome.ok, true)

	const denied = transport((method) => (method === 'GET' ? { status: 200, data: PORTAL } : { status: 403, data: {} }))
	const refused = await createRegistrationSettings({ get: denied.get, put: denied.put, post: denied.post, url: generateUrl, translate: (t) => t })
		.save('p-1', { policy: 'off', allowedDomains: [] })
	assert.equal(refused.ok, false)
	assert.equal(refused.message, 'Only an administrator can change who may register.')
})

test('the waiting list reads the organisation\'s pending self-registrations only', async () => {
	const rows = [{ subjectRef: 's-9', email: 'new@x.nl', status: 'pending', provisionedBy: 'self-registration' }]
	const http = transport(() => ({ status: 200, data: { results: rows } }))
	const settings = createRegistrationSettings({ get: http.get, put: http.put, post: http.post, url: generateUrl, translate: (t) => t })
	assert.deepEqual(await settings.waiting('gemeente-x'), rows)
	const url = new URL(http.calls[0].url, 'http://x')
	assert.equal(url.pathname, '/apps/openregister/api/objects/portaliq/portalAccount')
	assert.equal(url.searchParams.get('status'), 'pending')
	assert.equal(url.searchParams.get('provisionedBy'), 'self-registration')
	assert.equal(url.searchParams.get('organisation'), 'gemeente-x')
	assert.deepEqual(await settings.waiting(''), [])
})

test('approve and refuse post the account routes, and a refusal needs a reason', async () => {
	const http = transport(() => ({ status: 200, data: {} }))
	const settings = createRegistrationSettings({ get: http.get, put: http.put, post: http.post, url: generateUrl, translate: (t) => t })
	assert.equal((await settings.approve({ subjectRef: 's-9' })).ok, true)
	assert.equal((await settings.refuse({ subjectRef: 's-9' }, ' ')).ok, false)
	assert.equal((await settings.refuse({ subjectRef: 's-9' }, 'Not a resident')).ok, true)
	assert.deepEqual(http.calls.map((c) => [c.url, c.body]), [
		['/apps/portaliq/api/accounts/s-9/approve', {}],
		['/apps/portaliq/api/accounts/s-9/refuse', { reason: 'Not a resident' }],
	])
	const gone = createRegistrationSettings({
		get: http.get,
		put: http.put,
		post: transport(() => ({ status: 400, data: { error: 'not_pending' } })).post,
		url: generateUrl,
		translate: (t) => t,
	})
	assert.equal((await gone.approve({ subjectRef: 's-9' })).message, 'Someone already decided on this registration.')
})

const manifest = JSON.parse(readFileSync(join(ROOT, 'src/manifest.json'), 'utf8'))
const page = (id) => manifest.pages.find((p) => p.id === id)

test('the accounts index offers "Issue an account" instead of the generic add', () => {
	const config = page('PortalAccounts').config
	assert.equal(config.showAdd, false)
	assert.deepEqual(
		(config.headerActions || []).map((a) => a.handler),
		['issueAccount', 'inviteSomeone'],
	)
})

test('an Invitations page lists the invitations with a withdraw action and a menu entry', () => {
	const invitations = page('Invitations')
	assert.equal(invitations.type, 'index')
	assert.equal(invitations.config.schema, 'portalInvitation')
	assert.equal(invitations.config.showAdd, false)
	for (const column of ['email', 'state', 'sentAt', 'expiresAt', 'invitedBy']) {
		assert.ok(JSON.stringify(invitations.config.columns).includes(`"${column}"`), column)
	}
	const withdraw = invitations.config.actions.find((a) => a.handler === 'withdrawInvitation')
	assert.equal(withdraw.label, 'Withdraw invitation')
	assert.ok(manifest.menu.some((m) => m.route === 'Invitations'))
})

test('the handlers are in the map the index action dispatcher reads', () => {
	const source = readFileSync(join(ROOT, 'src/customComponents.js'), 'utf8')
	assert.match(source, /createStaffAccountHandlers\(/)
	assert.match(source, /\.\.\.staffAccountHandlers/)
	for (const dialog of ['IssueAccountDialog', 'InviteDialog', 'VoidAccountDialog']) {
		assert.ok(existsSync(join(ROOT, `src/dialogs/${dialog}.vue`)), dialog)
	}
})

test('the account page and the portal page carry the withdraw and registration widgets', () => {
	const registry = readFileSync(join(ROOT, 'src/registry.js'), 'utf8')
	const account = page('PortalAccountDetail').config
	assert.ok(account.widgets.some((w) => w.type === 'PortalAccountWithdraw'))
	assert.ok(account.layout.some((l) => l.widgetId === account.widgets.find((w) => w.type === 'PortalAccountWithdraw').id))
	const portal = page('PortalDetail').config
	assert.ok(portal.widgets.some((w) => w.type === 'PortalRegistration'))
	assert.ok(portal.layout.some((l) => l.widgetId === portal.widgets.find((w) => w.type === 'PortalRegistration').id))
	assert.match(registry, /PortalAccountWithdraw: \{/)
	assert.match(registry, /PortalRegistration: \{/)
})
