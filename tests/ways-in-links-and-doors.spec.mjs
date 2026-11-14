#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// ways-in-links-and-doors.spec.mjs: which mailed links the site takes and
// which it refuses, what it strips from the address bar and when, which
// doors the site config opens, and how the ways-in translator fills a
// sentence (src/site/lib/waysIn.js), pinned before they were split into
// named steps.
//
// Usage:
//   node --test tests/ways-in-links-and-doors.spec.mjs

import assert from 'node:assert/strict'
import { test } from 'node:test'
import {
	captureEmailLink,
	takeWayInLink,
	waysInFrom,
	waysInTranslator,
} from '../src/site/lib/waysIn.js'

/**
 * A history that notes every address it is given.
 *
 * @return {object} The history.
 */
function noting() {
	const seen = []
	return { seen, replaceState: (state, title, url) => seen.push([state, title, url]) }
}

test('a mailed link of a known kind is taken once and stripped from the address bar', () => {
	for (const kind of ['activate', 'invitation', 'reference', 'email-link']) {
		const history = noting()
		const link = takeWayInLink({ hash: `#${kind}=a%2Bb`, href: `https://s.nl/site?x=1#${kind}=a%2Bb` }, history)
		assert.deepEqual(link, { kind, token: 'a+b' })
		assert.deepEqual(history.seen, [[null, '', 'https://s.nl/site?x=1']])
	}
	const plain = takeWayInLink({ hash: '#activate=t' }, null)
	assert.deepEqual(plain, { kind: 'activate', token: 't' }, 'no history to strip, still taken')
	assert.deepEqual(takeWayInLink({ hash: '#activate=t', href: 'h#x' }, {}), { kind: 'activate', token: 't' })
	assert.deepEqual(takeWayInLink({ hash: '#activate=x#y', href: 'h#activate=x#y' }, null), { kind: 'activate', token: 'x#y' })
	const noHref = noting()
	takeWayInLink({ hash: '#activate=t' }, noHref)
	assert.deepEqual(noHref.seen, [[null, '', '']])
})

test('anything else is refused and the address bar left alone', () => {
	for (const hash of ['', '#', '#guest/a', '#token=x', '#activate=', '#activate=a&b=c', '#Activate=x', 'activate=x']) {
		const history = noting()
		assert.equal(takeWayInLink({ hash, href: 'h' + hash }, history), null, hash)
		assert.deepEqual(history.seen, [], hash)
	}
	assert.equal(takeWayInLink(null, noting()), null)
	assert.equal(takeWayInLink({}, noting()), null)
})

test('a malformed secret throws after the take stripped it', () => {
	const history = noting()
	assert.throws(() => takeWayInLink({ hash: '#activate=%E0%A4%A', href: 'h#activate=%E0%A4%A' }, history), URIError)
	assert.deepEqual(history.seen, [[null, '', 'h']])
})

test('at boot only an e-mail link is captured, and the next take hands it over once', () => {
	const history = noting()
	assert.equal(captureEmailLink({ hash: '#email-link=s%3D1', href: 'https://s.nl/#email-link=s%3D1' }, history), true)
	assert.deepEqual(history.seen, [[null, '', 'https://s.nl/']])
	const later = noting()
	assert.deepEqual(takeWayInLink({ hash: '#activate=other', href: 'x' }, later), { kind: 'email-link', token: 's=1' })
	assert.deepEqual(later.seen, [], 'the captured link is handed over without touching the address bar')
	assert.deepEqual(takeWayInLink({ hash: '#activate=other', href: 'x' }, later), { kind: 'activate', token: 'other' })

	for (const hash of ['#activate=x', '#invitation=x', '#reference=x', '', '#email-link=']) {
		const refused = noting()
		assert.equal(captureEmailLink({ hash, href: 'h' + hash }, refused), false, hash)
		assert.deepEqual(refused.seen, [], hash)
	}
	assert.equal(captureEmailLink(null, noting()), false)
	assert.equal(captureEmailLink({ hash: '#email-link=t' }, null), true)
	assert.equal(captureEmailLink({ hash: '#email-link=u' }, { replaceState: 'no' }), true)
	assert.deepEqual(takeWayInLink({ hash: '' }, null), { kind: 'email-link', token: 'u' }, 'the latest capture wins')
	const bare = noting()
	captureEmailLink({ hash: '#email-link=v' }, bare)
	assert.deepEqual(bare.seen, [[null, '', '']])
	takeWayInLink({ hash: '' }, null)
})

test('a malformed e-mail link throws before anything is captured or stripped', () => {
	const history = noting()
	assert.throws(() => captureEmailLink({ hash: '#email-link=%E0%A4%A', href: 'h' }, history), URIError)
	assert.deepEqual(history.seen, [])
	assert.equal(takeWayInLink({ hash: '' }, null), null, 'nothing was captured')
})

test('the doors are closed unless the config opens them', () => {
	const closed = { register: false, reference: false, emailSignIn: '', referenceCaseTypes: [], emailLink: false }
	assert.deepEqual(waysInFrom(undefined), closed)
	assert.deepEqual(waysInFrom(null), closed)
	assert.deepEqual(waysInFrom({ waysIn: null }), closed)
	assert.deepEqual(waysInFrom({ waysIn: { register: 'true', reference: 1, emailSignIn: 3, emailLink: 'yes', referenceCaseTypes: 'x' } }), closed)
	const type = { register: 'r', schema: 's', caseType: 'c', label: 'Bezwaar' }
	const broken = [null, 0, { register: 'r', schema: 's' }, { register: 'r', caseType: 'c' }, { schema: 's', caseType: 'c' }, { register: 1, schema: 's', caseType: 'c' }]
	assert.deepEqual(
		waysInFrom({ waysIn: { register: true, reference: true, emailSignIn: 'Inloggen', emailLink: true, referenceCaseTypes: [...broken, type] } }),
		{ register: true, reference: true, emailSignIn: 'Inloggen', referenceCaseTypes: [type], emailLink: true },
	)
	assert.equal(waysInFrom({ waysIn: { reference: true, referenceCaseTypes: broken } }).reference, false)
	assert.equal(waysInFrom({ waysIn: { reference: false, referenceCaseTypes: [type] } }).reference, false)
	assert.deepEqual(waysInFrom({ waysIn: { referenceCaseTypes: [type] } }).referenceCaseTypes, [type])
})

test('the translator prefers the site, falls back to its own strings and fills every variable', () => {
	const site = (key) => (key === 'Known' ? 'Bekend {n}' : key)
	const t = waysInTranslator(site, 'nl_NL')
	assert.equal(t('Known', { n: 3 }), 'Bekend 3')
	assert.equal(t('Not a key anywhere'), 'Not a key anywhere')
	assert.equal(t('Not a key {a}{a} {b}', { a: 1, b: null }), 'Not a key 11 null')
	assert.equal(t('Known'), 'Bekend {n}')
	assert.equal(waysInTranslator(null, '')('Plain {x}', { x: 'y' }), 'Plain y')
	assert.equal(waysInTranslator('no', 'zz')('Plain'), 'Plain')
	const number = waysInTranslator(() => 5, 'en')
	assert.equal(number('Any'), 5, 'a non-string site answer without variables is returned as is')
	assert.equal(number('Any', {}), 5)
	assert.equal(number('Any', { a: 1 }), '5')
})
