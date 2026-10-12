#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-domains.spec.mjs: the Domeinen and Beheerders cards refuse a host name
// with a scheme, read a certificate end date and keep editors tidy
// (portal-domains-and-editors REQ-PDE-001, REQ-PDE-002, REQ-PDE-003).
//
// Usage:
//   node --test tests/portal-domains.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import { certificateEnd, editorError, hostnameError, initials, withDomain, withEditor } from '../src/lib/portalDomains.js'

test('a plain host name is accepted and a scheme, port or path is refused', () => {
	assert.equal(hostnameError('nieuw.zuiddrecht.nl'), '')
	for (const bad of ['https://nieuw.zuiddrecht.nl/', 'nieuw.zuiddrecht.nl:8080', 'nieuw.zuiddrecht.nl/pad', 'zuiddrecht', '', ' ']) {
		assert.notEqual(hostnameError(bad), '', bad)
	}
})

test('a domain that is already listed is refused, whatever its case', () => {
	assert.notEqual(hostnameError('Portal.Zuiddrecht.nl', [{ hostname: 'portal.zuiddrecht.nl' }]), '')
})

test('a new domain waits for verification', () => {
	assert.deepEqual(withDomain([{ hostname: 'a.nl', verified: true }], ' Nieuw.Zuiddrecht.nl '), [
		{ hostname: 'a.nl', verified: true },
		{ hostname: 'nieuw.zuiddrecht.nl', verified: false },
	])
})

test('the certificate reads its end date, or nothing when there is none', () => {
	const format = (d) => d.toISOString().slice(0, 10)
	assert.equal(certificateEnd({ certificateValidUntil: '2027-01-14T12:00:00+00:00' }, format), '2027-01-14')
	assert.equal(certificateEnd({ hostname: 'a.nl' }, format), null)
	assert.equal(certificateEnd({ certificateValidUntil: 'rubbish' }, format), null)
})

test('an editor needs a user and is not listed twice', () => {
	assert.notEqual(editorError({ userId: '  ' }), '')
	assert.notEqual(editorError({ userId: 'pieter' }, [{ userId: 'pieter' }]), '')
	assert.equal(editorError({ userId: 'anouk' }, [{ userId: 'pieter' }]), '')
})

test('an editor without a name shows the user id, and an unknown role becomes editor', () => {
	assert.deepEqual(withEditor([], { userId: ' anouk ', displayName: '', role: 'boss' }), [
		{ userId: 'anouk', displayName: 'anouk', role: 'editor' },
	])
	assert.equal(withEditor([], { userId: 'p', role: 'chief-editor' })[0].role, 'chief-editor')
})

test('initials are the first letters of the first two words', () => {
	assert.equal(initials('Pieter Jansen'), 'PJ')
	assert.equal(initials('anouk'), 'A')
	assert.equal(initials(''), '')
})
