#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// way-in-link-golden.spec.mjs: what the page a mailed way in opens does with
// each kind of link (activate, reference, e-mail sign-in, invitation):
// signed in, refused, already used, the address asked for, pinned before the
// page was split into named steps.
//
// Usage:
//   node --test tests/way-in-link-golden.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { loadSfc } from './support/render-sfc.mjs'

const PAGE = await loadSfc('src/site/components/WayInLink.vue')
const t = (text, vars) => (vars ? `${text}|${JSON.stringify(vars)}` : text)

/**
 * The page's state with a fake identity api.
 *
 * @param {object} answers The answer per api call.
 * @param {object} state What differs.
 * @return {object} The page and its calls.
 */
function page(answers, state = {}) {
	const calls = []
	const api = new Proxy({}, {
		get: (target, name) => async (arg) => {
			calls.push([name, arg])
			return answers[name] || { ok: false, error: '', data: {} }
		},
	})
	const vm = { ...PAGE.data(), t, emailSignIn: '', api, calls, ...state }
	for (const [name, method] of Object.entries(PAGE.methods)) {
		vm[name] = method.bind(vm)
	}
	return vm
}

/**
 * Open the page on an address with a way-in fragment.
 *
 * @param {string} hash The fragment.
 * @param {object} vm The page.
 * @return {Promise<void>}
 */
async function openOn(hash, vm) {
	const replaced = []
	globalThis.window = {
		location: { hash, href: `https://site.example/pagina${hash}`, reload: () => replaced.push('reload') },
		history: { replaceState: (s, title, url) => replaced.push(url) },
	}
	try {
		await PAGE.mounted.call(vm)
	} finally {
		vm.replaced = replaced
		delete globalThis.window
	}
}

test('no way-in link: nothing happens', async () => {
	const vm = page({})
	await openOn('', vm)
	assert.equal(vm.link, null)
	assert.deepEqual(vm.calls, [])
})

test('an activation: ready, ready with the e-mail sign-in, refused with and without a code', async () => {
	const ok = page({ activateAccount: { ok: true } })
	await openOn('#activate=tok%201', ok)
	assert.deepEqual(ok.calls, [['activateAccount', 'tok 1']])
	assert.deepEqual(ok.result, { role: 'status', text: 'Your account is ready. You can sign in now.' })
	assert.equal(ok.busy, false)
	assert.deepEqual(ok.replaced, ['https://site.example/pagina'])
	const withMail = page({ activateAccount: { ok: true } }, { emailSignIn: 'e-mail' })
	await openOn('#activate=t', withMail)
	assert.deepEqual(withMail.result, { role: 'status', text: 'Your account is ready. Sign in with {provider}.|{"provider":"e-mail"}' })
	const refused = page({ activateAccount: { ok: false, error: 'link_used' } })
	await openOn('#activate=t', refused)
	assert.equal(refused.result.role, 'alert')
	const bare = page({ activateAccount: { ok: false } })
	await openOn('#activate=t', bare)
	assert.equal(bare.result.role, 'alert')
	assert.notEqual(bare.result.text, refused.result.text)
})

test('a reference link: the case opened read only, or no longer valid', async () => {
	const ok = page({ redeemReferenceLink: { ok: true, data: { bearer: 'b', caseReference: 'Z-1' } }, referenceCase: { case: { omschrijving: 'Lamp', id: 'x' } } })
	await openOn('#reference=r', ok)
	assert.deepEqual(ok.calls.map((call) => call[0]), ['redeemReferenceLink', 'referenceCase'])
	assert.equal(ok.calls[1][1], 'b')
	assert.equal(ok.opened.caseReference, 'Z-1')
	assert.ok(Array.isArray(ok.opened.fields))
	const own = page({ redeemReferenceLink: { ok: true, data: { bearer: 'b', caseReference: 'Z-1' } }, referenceCase: { case: {}, caseReference: 'Z-2' } })
	await openOn('#reference=r', own)
	assert.equal(own.opened.caseReference, 'Z-2')
	for (const answers of [{ redeemReferenceLink: { ok: false, data: {} } }, { redeemReferenceLink: { ok: true, data: {} } }, { redeemReferenceLink: { ok: true, data: { bearer: 'b' } }, referenceCase: null }]) {
		const vm = page(answers)
		await openOn('#reference=r', vm)
		assert.deepEqual(vm.result, { role: 'alert', text: 'This link is no longer valid.' })
		assert.equal(vm.opened, null)
		assert.equal(vm.busy, false)
	}
})

test('an e-mail sign-in link: described, refused, signed in, the address asked for or wrong', async () => {
	const described = page({ describeEmailLink: { ok: true, data: { nonce: 'n', sameBrowser: true } } })
	await openOn('#email-link=e', described)
	assert.deepEqual(described.emailLink, { nonce: 'n', sameBrowser: true })
	const refused = page({ describeEmailLink: { ok: true, data: {} } })
	await openOn('#email-link=e', refused)
	assert.equal(refused.result.role, 'alert')
	assert.equal(refused.emailLink, null)

	const stored = []
	const signIn = async (answer, emailLink, typed = 'a@x.nl') => {
		const vm = page({ redeemEmailLink: answer }, { link: { kind: 'email-link', token: 'e' }, emailLink, typedAddress: typed })
		globalThis.window = { location: { reload: () => stored.push('reload') }, sessionStorage: { setItem: (k, v) => stored.push(v), getItem: () => null }, localStorage: { getItem: () => null, removeItem() {} } }
		try {
			await vm.signInWithLink()
		} finally {
			delete globalThis.window
		}
		return vm
	}
	const same = await signIn({ ok: true, data: { bearer: 'tok' } }, { nonce: 'n', sameBrowser: true })
	assert.deepEqual(same.calls, [['redeemEmailLink', { token: 'e', nonce: 'n', email: '' }]])
	assert.ok(stored.includes('reload'))
	assert.ok(stored.includes('tok'))
	const other = await signIn({ ok: true, data: { bearer: 'tok' } }, { nonce: 'n', sameBrowser: false })
	assert.equal(other.calls[0][1].email, 'a@x.nl')
	const needed = await signIn({ ok: false, error: 'address_needed' }, { nonce: 'n', sameBrowser: true })
	assert.deepEqual(needed.emailLink, { nonce: 'n', sameBrowser: false })
	assert.equal(needed.result, null)
	const wrong = await signIn({ ok: false, error: 'address_wrong' }, { nonce: 'n', sameBrowser: false })
	assert.equal(wrong.result.role, 'alert')
	assert.deepEqual(wrong.emailLink, { nonce: 'n', sameBrowser: false })
	const used = await signIn({ ok: false, error: 'link_used' }, { nonce: 'n', sameBrowser: true })
	assert.equal(used.emailLink, null)
	assert.equal(used.result.role, 'alert')
	const noBearer = await signIn({ ok: true, data: {} }, { nonce: 'n', sameBrowser: true })
	assert.equal(noBearer.emailLink, null)
	assert.equal(noBearer.busy, false)
})

test('an invitation: accepted, refused with and without a code', async () => {
	for (const [answer, role] of [[{ ok: true }, 'status'], [{ ok: false, error: 'invitation_used' }, 'alert'], [{ ok: false }, 'alert']]) {
		const vm = page({ acceptInvitation: answer }, { link: { kind: 'invitation', token: 'i' } })
		await vm.accept()
		assert.deepEqual(vm.calls, [['acceptInvitation', 'i']])
		assert.equal(vm.result.role, role)
		assert.equal(vm.busy, false)
	}
})
