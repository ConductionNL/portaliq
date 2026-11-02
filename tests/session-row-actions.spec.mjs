#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// session-row-actions.spec.mjs: the Sessions list offers "Naar het account"
// and "Sessie intrekken" on a row (portaliq/PtSessies). Revoking asks first,
// posts the row's token id to the admin route and names a refusal; the
// account link opens the account the session was issued for.
//
// Usage:
//   node --test tests/session-row-actions.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { createSessionHandlers, SESSION_REVOKE_PATH } from '../src/lib/sessionActions.js'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const NL = JSON.parse(read('l10n/nl.json')).translations
const ROUTES = read('appinfo/routes.php')
const SESSIONS = MANIFEST.pages.find((page) => page.id === 'PortalSessions').config

/**
 * Handlers over recording collaborators.
 *
 * @param {object} options What the collaborators answer.
 * @return {{handlers: object, log: object}}
 */
function harness({ confirm = true, fail = null } = {}) {
	const log = { posts: [], notified: [], errors: [], reloads: 0, opened: [] }
	const handlers = createSessionHandlers({
		post: async (url, body) => {
			log.posts.push([url, body])
			if (fail) {
				const error = new Error('refused')
				error.response = fail
				throw error
			}
			return { data: { revoked: true } }
		},
		generateUrl: (path) => `/index.php${path}`,
		confirmRevoke: async () => confirm,
		notify: (text) => log.notified.push(text),
		notifyError: (text) => log.errors.push(text),
		translate: (text) => text,
		reload: () => { log.reloads++ },
		open: (url) => log.opened.push(url),
	})
	return { handlers, log }
}

const ROW = { jti: 'jti-1', accountRef: 'acc-1', subjectRef: 's1', revoked: false }

test('revoking posts the token id to the admin route and reloads', async () => {
	const { handlers, log } = harness()
	assert.equal(await handlers.revokeSession({ item: ROW }), true)
	assert.deepEqual(log.posts, [[`/index.php${SESSION_REVOKE_PATH}`, { jti: 'jti-1' }]])
	assert.equal(log.reloads, 1)
	assert.deepEqual(log.notified, ['The session is revoked.'])
})

test('a cancelled confirmation sends nothing', async () => {
	const { handlers, log } = harness({ confirm: false })
	assert.equal(await handlers.revokeSession({ item: ROW }), false)
	assert.deepEqual(log.posts, [])
})

test('a refusal is named, not swallowed', async () => {
	const forbidden = harness({ fail: { status: 403, data: {} } })
	assert.equal(await forbidden.handlers.revokeSession({ item: ROW }), false)
	assert.deepEqual(forbidden.log.errors, ['Only an administrator may revoke a session.'])
	const gone = harness({ fail: { status: 404, data: { error: 'not_found' } } })
	await gone.handlers.revokeSession({ item: ROW })
	assert.deepEqual(gone.log.errors, ['This session was not found or is already revoked.'])
})

test('a row without a token id is refused before any request', async () => {
	const { handlers, log } = harness()
	assert.equal(await handlers.revokeSession({ item: { accountRef: 'acc-1' } }), false)
	assert.deepEqual(log.posts, [])
	assert.equal(log.errors.length, 1)
})

test('the account link opens the account the session was issued for', async () => {
	const { handlers, log } = harness()
	assert.equal(await handlers.openSessionAccount({ item: ROW }), true)
	assert.deepEqual(log.opened, ['/index.php/apps/portaliq/accounts/acc-1'])
	assert.equal(await handlers.openSessionAccount({ item: { jti: 'x' } }), false)
})

test('the route answers at the admin controller', () => {
	assert.match(ROUTES, /'sessionAdmin#revokeSession', 'url' => '\/api\/session-admin\/revoke-session', 'verb' => 'POST'/)
	assert.equal(SESSION_REVOKE_PATH, '/apps/portaliq/api/session-admin/revoke-session')
})

test('the Sessions list carries both row actions, revoke only on a live session', () => {
	const byHandler = Object.fromEntries((SESSIONS.actions || []).map((a) => [a.handler, a]))
	assert.ok(byHandler.openSessionAccount, 'no account action')
	assert.ok(byHandler.revokeSession, 'no revoke action')
	assert.deepEqual(byHandler.revokeSession.visibleWhen, { field: 'revoked', op: 'neq', value: true })
	for (const action of Object.values(byHandler)) {
		assert.equal(action.type, 'handler')
		assert.ok(NL[action.label], `"${action.label}" is not in the Dutch catalogue`)
	}
	for (const text of ['The session is revoked.', 'Only an administrator may revoke a session.', 'This session was not found or is already revoked.', 'Revoke this session?']) {
		assert.ok(NL[text], `"${text}" is not in the Dutch catalogue`)
	}
})
