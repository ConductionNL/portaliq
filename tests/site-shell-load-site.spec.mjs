#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-shell-load-site.spec.mjs: how the site shell (src/site/App.vue) boots:
// the portal, its menus and glossary, the contributed surfaces, the session,
// the confirmation link, the kept invitations (claim, contact, mandate) and
// then the account or a silent sign-in, in that order, with what a refusal or
// a failure leaves behind. Pinned before loadSite was split into named steps.
//
// Usage:
//   node --test tests/site-shell-load-site.spec.mjs

import assert from 'node:assert/strict'
import { register } from 'node:module'
import { test } from 'node:test'
import { pathToFileURL } from 'node:url'
import { instance } from './support/page-instance.mjs'
import { sfcOptionsLoaded } from './support/sfc-options-loaded.mjs'

// The compiled shell sits in a cache directory; its lazy imports
// (`import('../shared/…')`) resolve against the shell's own directory.
register(
	'data:text/javascript,' + encodeURIComponent(
		'const SHELL = ' + JSON.stringify(pathToFileURL(process.cwd() + '/src/site/App.vue').href) + ';'
		+ 'export async function resolve(specifier, context, next) {'
		+ " if (specifier.startsWith('.') && String(context.parentURL || '').includes('/sfc-options-loaded/')) {"
		+ ' return next(new URL(specifier, SHELL).href, context) }'
		+ ' return next(specifier, context) }',
	),
)

const App = await sfcOptionsLoaded('src/site/App.vue')

/**
 * A browser whose content and auth APIs answer from a table: a body, or a
 * number for an error status. Unlisted paths answer 404.
 *
 * @param {object} answers Path (after the API base) to answer.
 * @param {object} kept What sessionStorage holds.
 * @return {{reads: Array<string>, storage: object}} The paths read and the storage.
 */
function browser(answers, kept = {}) {
	const reads = []
	const items = { ...kept }
	const storage = {
		items,
		getItem: (key) => (Object.hasOwn(items, key) ? items[key] : null),
		setItem: (key, value) => { items[key] = String(value) },
		removeItem: (key) => { delete items[key] },
	}
	globalThis.window = {
		location: { hash: '', search: '', pathname: '/', origin: 'https://s.nl', href: 'https://s.nl/' },
		localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
		sessionStorage: storage,
		history: { replaceState() {} },
	}
	globalThis.fetch = async (url) => {
		const path = new URL(url, 'https://s.nl').pathname
		reads.push(path)
		const key = Object.keys(answers).find((p) => path.endsWith(p))
		const answer = key === undefined ? 404 : answers[key]
		if (typeof answer === 'number') {
			return { ok: false, status: answer, json: async () => ({}) }
		}
		return { ok: true, status: 200, json: async () => answer }
	}
	return { reads, storage }
}

/**
 * The shell on a portal, its account steps recorded rather than run.
 *
 * @return {object} The stand-in shell, with `steps`.
 */
function shell() {
	const vm = instance(App, { portalSlug: 'demo' })
	vm.steps = []
	vm.chosenLocale = 'nl'
	for (const step of ['loadAccount', 'trySilentSignIn', 'reloadSession', 'watchIdle', 'applyDocumentTitle']) {
		vm[step] = async () => vm.steps.push([step, vm.session === null ? null : 'session'])
	}
	return vm
}

const SITE = { title: 'Gemeente X', slug: 'demo', assistantEnabled: true }

/**
 * What a boot leaves behind.
 *
 * @param {object} vm The shell.
 * @return {object} The fields loadSite writes.
 */
function stateOf(vm) {
	return {
		site: vm.site,
		menus: vm.menus,
		glossary: vm.glossary,
		error: vm.error ? vm.error.status : null,
		contributions: vm.contributions,
		session: vm.session,
		sessionKnown: vm.sessionKnown,
		confirmMessage: vm.confirmMessage,
		claimMessage: vm.claimMessage,
		steps: vm.steps,
	}
}

test('a public portal boots without a session and tries a silent sign-in last', async () => {
	const { reads } = browser({
		'/site': SITE,
		'/menus': { menus: [{ id: 'main' }] },
		'/glossary': { terms: [{ term: 'Wmo' }] },
		'/contributions': { contributions: [{ app: 'dossiq' }] },
		'/session': 401,
	})
	const vm = shell()
	await vm.loadSite()
	assert.deepEqual(stateOf(vm), {
		site: SITE,
		menus: [{ id: 'main' }],
		glossary: [{ term: 'Wmo' }],
		error: null,
		contributions: [{ app: 'dossiq' }],
		session: null,
		sessionKnown: true,
		confirmMessage: null,
		claimMessage: null,
		steps: [['watchIdle', null], ['applyDocumentTitle', null], ['trySilentSignIn', null]],
	})
	assert.ok(reads.indexOf(reads.find((p) => p.endsWith('/contributions'))) > 2, 'contributions after the portal')
})

test('a portal that fails to answer stops the boot, a failing contribution does not', async () => {
	browser({ '/site': 500, '/menus': { menus: [] }, '/glossary': { terms: [] } })
	const failed = shell()
	await failed.loadSite()
	assert.equal(failed.error.status, 500)
	assert.deepEqual(failed.steps, [])
	assert.equal(failed.sessionKnown, false)

	browser({ '/site': SITE, '/menus': 503, '/glossary': { terms: [] } })
	const menus = shell()
	await menus.loadSite()
	assert.equal(menus.error.status, 503, 'a failing menu read that is no sign-in refusal fails the portal')

	browser({ '/site': SITE, '/menus': 401, '/glossary': 403, '/contributions': 500, '/session': 401 })
	const behindSignIn = shell()
	await behindSignIn.loadSite()
	assert.deepEqual([behindSignIn.menus, behindSignIn.glossary, behindSignIn.contributions], [[], [], []])
	assert.equal(behindSignIn.error, null)
	assert.deepEqual(behindSignIn.steps.map((s) => s[0]), ['watchIdle', 'applyDocumentTitle', 'trySilentSignIn'])
})

test('a resident with a session and no kept invitation goes to the account', async () => {
	const session = { authenticated: true, subjectRef: 'bsn-1', displayName: 'Jan' }
	browser({ '/site': SITE, '/menus': { menus: [] }, '/glossary': { terms: [] }, '/contributions': { contributions: [] }, '/session': session })
	const vm = shell()
	await vm.loadSite()
	assert.equal(vm.sessionKnown, true)
	assert.ok(vm.session)
	assert.deepEqual(vm.steps, [['watchIdle', 'session'], ['applyDocumentTitle', 'session'], ['loadAccount', 'session']])
	assert.equal(vm.claimMessage, null)
})

const SESSION = { authenticated: true, subjectRef: 'bsn-1', displayName: 'Jan' }
const PORTAL = { '/site': SITE, '/menus': { menus: [] }, '/glossary': { terms: [] }, '/contributions': { contributions: [] } }
const REDEEM = '/identity/invitation/redeem'
const CONTACT = '/contacts/accept-invitation'
const MANDATE = '/mandates/invitations/accept'
const KEPT = {
	'portaliq.claimInvitation': 'claim-secret',
	'portaliq.contactInvitation': 'contact-secret',
	'portaliq.mandateInvitation': 'mandate-secret',
}

/**
 * Boot a signed-in shell with kept invitations.
 *
 * @param {object} answers The redeem routes' answers.
 * @param {object} kept What sessionStorage holds.
 * @return {Promise<object>} The shell, the paths read after the session and what is still kept.
 */
async function bootWith(answers, kept) {
	const { reads, storage } = browser({ ...PORTAL, '/session': SESSION, ...answers }, kept)
	const vm = shell()
	await vm.loadSite()
	const afterSession = reads.slice(reads.indexOf('/portal/api/session') + 1)
	return { vm, asked: afterSession, kept: { ...storage.items } }
}

test('a kept claim is redeemed first and the session read again before the account', async () => {
	const { vm, asked, kept } = await bootWith({ [REDEEM]: {} }, KEPT)
	assert.equal(vm.claimMessage.claimed, true)
	assert.deepEqual(asked, ['/portal/api' + REDEEM], 'the contact and mandate redeemers are not asked once a claim answered')
	assert.deepEqual(vm.steps.map((s) => s[0]), ['watchIdle', 'applyDocumentTitle', 'reloadSession', 'loadAccount'])
	assert.deepEqual(kept, { 'portaliq.contactInvitation': 'contact-secret', 'portaliq.mandateInvitation': 'mandate-secret' })
})

test('a refused claim is the message, and the session is not read again', async () => {
	const { vm, asked, kept } = await bootWith({}, KEPT)
	assert.deepEqual({ ...vm.claimMessage, text: typeof vm.claimMessage.text }, { role: 'alert', text: 'string', claimed: false })
	assert.deepEqual(asked, ['/portal/api' + REDEEM])
	assert.deepEqual(vm.steps.map((s) => s[0]), ['watchIdle', 'applyDocumentTitle', 'loadAccount'])
	assert.deepEqual(Object.keys(kept), ['portaliq.contactInvitation', 'portaliq.mandateInvitation'])
})

test('without a claim the contact invitation answers, and the mandate waits', async () => {
	const accepted = await bootWith({ [CONTACT]: {} }, { 'portaliq.contactInvitation': 'c', 'portaliq.mandateInvitation': 'm' })
	assert.equal(accepted.vm.claimMessage.role, 'status')
	assert.deepEqual(accepted.asked, ['/portal/api' + CONTACT])
	assert.deepEqual(accepted.kept, { 'portaliq.mandateInvitation': 'm' })
	assert.deepEqual(accepted.vm.steps.map((s) => s[0]), ['watchIdle', 'applyDocumentTitle', 'loadAccount'])

	const refused = await bootWith({}, { 'portaliq.contactInvitation': 'c', 'portaliq.mandateInvitation': 'm' })
	assert.equal(refused.vm.claimMessage.role, 'alert')
	assert.deepEqual(refused.asked, ['/portal/api' + CONTACT], 'a refused contact invitation is the message too')
})

test('a mandate invitation alone is redeemed, accepted or refused', async () => {
	const refused = await bootWith({}, { 'portaliq.mandateInvitation': 'm' })
	assert.equal(refused.vm.claimMessage.role, 'alert')
	assert.deepEqual(refused.asked, ['/portal/api' + MANDATE])
	assert.deepEqual(refused.kept, {})
	const accepted = await bootWith({ [MANDATE]: { ok: true } }, { 'portaliq.mandateInvitation': 'm' })
	assert.notEqual(accepted.vm.claimMessage, null)
	assert.deepEqual(accepted.asked, ['/portal/api' + MANDATE])
	assert.deepEqual(accepted.vm.steps.at(-1)[0], 'loadAccount')
})

test('signed out, a kept invitation is held for after the sign-in', async () => {
	const { storage, reads } = browser({ ...PORTAL, '/session': 401 }, KEPT)
	const vm = shell()
	await vm.loadSite()
	assert.deepEqual(storage.items, KEPT)
	assert.equal(vm.claimMessage.claimed, false)
	assert.equal(vm.claimMessage.role, 'status')
	assert.equal(reads.at(-1), '/portal/api/session', 'nothing is redeemed without a session')
	assert.deepEqual(vm.steps.map((s) => s[0]), ['watchIdle', 'applyDocumentTitle', 'trySilentSignIn'])
})
