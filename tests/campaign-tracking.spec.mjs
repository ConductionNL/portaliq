#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// campaign-tracking.spec.mjs — first-party UTM capture (landing-page-provisioning).
//
// Usage:
//   node tests/campaign-tracking.spec.mjs
//
// Run as a plain node script, matching tests/site-auth.spec.mjs and
// tests/federated-search.spec.mjs — this app has no JS test runner. A
// minimal in-memory `window`/`document` stub stands in for the browser
// globals campaignTracking.js reads (sessionStorage, location.search,
// document.referrer), since this module is exercised outside a browser.

class FakeStorage {
	constructor() {
		this.store = new Map()
	}

	getItem(key) {
		return this.store.has(key) ? this.store.get(key) : null
	}

	setItem(key, value) {
		this.store.set(key, String(value))
	}
}

globalThis.window = {
	sessionStorage: new FakeStorage(),
	location: { search: '' },
}
globalThis.document = { referrer: '' }

const { capturedReferrer, captureLanding, firstTouch, lastTouch } =
	await import('../src/site/lib/campaignTracking.js')

let failures = 0

/**
 * Assert deep equality, reporting the difference rather than a bare boolean.
 *
 * @param {string} what     What is being asserted.
 * @param {*}      actual   The value produced.
 * @param {*}      expected The value wanted.
 * @return {void}
 */
function assertEqual(what, actual, expected) {
	const a = JSON.stringify(actual)
	const e = JSON.stringify(expected)
	if (a === e) {
		console.log(`  ok   ${what}`)
		return
	}
	console.error(`  FAIL ${what}\n       expected ${e}\n       actual   ${a}`)
	failures += 1
}

console.log('captureLanding / firstTouch / lastTouch')

assertEqual(
	'no portal means nothing is captured',
	(() => {
		captureLanding('', '?utm_campaign=x')
		return firstTouch('')
	})(),
	{ campaign: null, source: null, medium: null, term: null, content: null },
)

assertEqual(
	'no utm_* params means first/last touch stay unset',
	(() => {
		captureLanding('p1', '?ref=nothing-to-do-with-utm')
		return firstTouch('p1')
	})(),
	{ campaign: null, source: null, medium: null, term: null, content: null },
)

document.referrer = 'https://www.linkedin.com/'
captureLanding('p2', '?utm_campaign=a&utm_source=first-link&utm_medium=email')
assertEqual('first touch is captured', firstTouch('p2'), {
	campaign: 'a',
	source: 'first-link',
	medium: 'email',
	term: null,
	content: null,
})
assertEqual('last touch equals first touch on the first landing', lastTouch('p2'), {
	campaign: 'a',
	source: 'first-link',
	medium: 'email',
	term: null,
	content: null,
})
assertEqual(
	'referrer is captured at first touch',
	capturedReferrer('p2'),
	'https://www.linkedin.com/',
)

// A later landing, same session, different UTM params AND a different
// (internal) referrer.
document.referrer = 'https://portal.example.org/campagne/a'
captureLanding('p2', '?utm_campaign=a&utm_source=second-link&utm_medium=social')

assertEqual('first touch is preserved, not overwritten', firstTouch('p2'), {
	campaign: 'a',
	source: 'first-link',
	medium: 'email',
	term: null,
	content: null,
})
assertEqual('last touch is overwritten by the later landing', lastTouch('p2'), {
	campaign: 'a',
	source: 'second-link',
	medium: 'social',
	term: null,
	content: null,
})
assertEqual(
	'the referrer captured at FIRST touch is kept, not the later internal one',
	capturedReferrer('p2'),
	'https://www.linkedin.com/',
)

// A DIFFERENT portal on the same browser gets its own, independent capture.
assertEqual('a different portal has no capture of its own yet', firstTouch('p3'), {
	campaign: null,
	source: null,
	medium: null,
	term: null,
	content: null,
})

console.log(
	failures === 0
		? '\nall campaign-tracking assertions held'
		: `\n${failures} campaign-tracking assertion(s) FAILED`,
)
process.exit(failures === 0 ? 0 : 1)
