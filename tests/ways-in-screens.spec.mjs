#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// ways-in-screens.spec.mjs: the ways into the Vue site for a visitor without
// an account (identity-ways-in-screens T02-T04, T06, T07): the doors the site
// config opens, the mailed links read once from the fragment, the challenge
// the browser solves, the identity routes, the sentences in both locales, and
// the wiring into App.vue and AccountArea.vue.
//
// Usage:
//   node --test tests/ways-in-screens.spec.mjs

import assert from 'node:assert/strict'
import { createHash, webcrypto } from 'node:crypto'
import { readdirSync, readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const lib = await import(pathToFileURL(join(ROOT, 'src/site/lib/waysIn.js')).href)
const read = (path) => readFileSync(join(ROOT, path), 'utf8')
const own = (
	await import(pathToFileURL(join(ROOT, 'src/site/lib/waysInStrings.js')).href)
).default
const nl = { ...JSON.parse(read('src/shared/i18n/nl.json')), ...own.nl }
const en = { ...JSON.parse(read('src/shared/i18n/en.json')), ...own.en }

/**
 * A fetch double that records each call and answers from a table.
 *
 * @param {object} answers Path suffix to `{status, body}`.
 * @return {{fetchImpl: Function, calls: Array<object>}}
 */
function fakeFetch(answers) {
	const calls = []
	const fetchImpl = async (url, options = {}) => {
		calls.push({
			url,
			options,
			body: options.body ? JSON.parse(options.body) : null,
		})
		const key = Object.keys(answers).find((suffix) =>
			url.split('?')[0].endsWith(suffix),
		)
		const { status = 200, body = {} } = key
			? answers[key]
			: { status: 404, body: { error: 'not_found' } }
		return { ok: status >= 200 && status < 300, status, json: async () => body }
	}
	return { fetchImpl, calls }
}

test('the site translator goes first, the ways-in strings fill its gaps', () => {
	const site = (key) => ({ Accept: 'Akkoord' })[key] || key
	const t = lib.waysInTranslator(site, 'nl-NL')
	assert.equal(t('Accept'), 'Akkoord')
	assert.equal(t('Case {reference}', { reference: 'Z-1' }), 'Zaak Z-1')
	assert.equal(lib.waysInTranslator(null, 'en')('Kind of case'), 'Kind of case')
})

test('the doors stay closed unless the site config opens them (REQ-IWI-005)', () => {
	assert.deepEqual(lib.waysInFrom({}), {
		register: false,
		reference: false,
		emailSignIn: '',
		referenceCaseTypes: [],
	})
	const type = {
		register: 'cases',
		schema: 'case',
		caseType: 'parking',
		label: 'Parking permit',
	}
	const open = lib.waysInFrom({
		waysIn: {
			register: true,
			reference: true,
			emailSignIn: 'E-mail',
			referenceCaseTypes: [type, { label: 'broken' }],
		},
	})
	assert.deepEqual(open, {
		register: true,
		reference: true,
		emailSignIn: 'E-mail',
		referenceCaseTypes: [type],
	})
	assert.equal(
		lib.waysInFrom({ waysIn: { reference: true, referenceCaseTypes: [] } })
			.reference,
		false,
	)
})

test('a mailed link is read once from the fragment and stripped from the address bar', () => {
	const replaced = []
	const history = { replaceState: (_s, _t, url) => replaced.push(url) }
	const location = {
		hash: '#activate=abc%2Bdef',
		href: 'https://x.test/apps/portaliq/site?portal=p#activate=abc%2Bdef',
	}
	assert.equal(lib.hasWayInLink(location), true)
	assert.deepEqual(lib.takeWayInLink(location, history), {
		kind: 'activate',
		token: 'abc+def',
	})
	assert.deepEqual(replaced, ['https://x.test/apps/portaliq/site?portal=p'])
	assert.equal(
		lib.takeWayInLink({ hash: '#guest/a/b/c', href: '' }, history),
		null,
	)
	assert.equal(lib.takeWayInLink({ hash: '#token=xyz', href: '' }, history), null)
})

test('the browser solves the challenge the way PortalChallengeService::solves() checks it', async () => {
	const solution = await lib.solveChallenge('nonce-1', 8, webcrypto.subtle)
	const digest = createHash('sha256').update(`nonce-1:${solution}`).digest()
	assert.ok(lib.leadingZeroBits(new Uint8Array(digest)) >= 8)
	assert.equal(await lib.solveChallenge('', 8, webcrypto.subtle), '')
})

test('registration sends the portal, the challenge, the solution and the honeypot', async () => {
	const { fetchImpl, calls } = fakeFetch({
		'/identity/challenge': {
			body: {
				nonce: 'n',
				expiresAt: 9,
				signature: 's',
				difficulty: 4,
				honeypotField: 'website',
			},
		},
		'/identity/register': {
			body: { status: 'pending', awaiting: 'activation' },
		},
	})
	const api = lib.waysInApi('/apps/portaliq/portal/api', 'wilgenboom', fetchImpl)
	const challenge = await api.challenge('registration')
	assert.match(
		calls[0].url,
		/\/identity\/challenge\?surface=registration&portal=wilgenboom$/,
	)
	const answer = await api.registerAccount({
		email: 'a@example.org',
		displayName: 'A',
		challenge,
		solution: '7',
		honeypot: { field: 'website', value: '' },
	})
	assert.deepEqual(calls[1].body, {
		portal: 'wilgenboom',
		email: 'a@example.org',
		displayName: 'A',
		nonce: 'n',
		expiresAt: 9,
		signature: 's',
		solution: '7',
		website: '',
	})
	assert.equal(answer.ok, true)
	assert.equal(
		lib.registrationOutcomeText(answer.data.awaiting),
		'We sent you an e-mail. Follow the link in it to activate your account.',
	)
})

test('the other ways in call their own routes and keep the refusal', async () => {
	const { fetchImpl, calls } = fakeFetch({
		'/identity/activate': {
			status: 403,
			body: { error: 'activation_not_valid' },
		},
		'/identity/reference-link': { body: {} },
		'/identity/reference-link/redeem': {
			body: { caseReference: 'Z-1', bearer: 'b' },
		},
		'/identity/reference-case': {
			body: {
				case: { title: 'Parking', '@self': {}, id: '1' },
				caseReference: 'Z-1',
				readOnly: true,
			},
		},
		'/identity/invitation/accept': {
			status: 403,
			body: { error: 'invitation_not_valid' },
		},
	})
	const api = lib.waysInApi('/b', 'p', fetchImpl)
	assert.equal((await api.activateAccount('t1')).error, 'activation_not_valid')
	await api.requestReferenceLink({
		register: 'r',
		schema: 's',
		caseType: 'c',
		caseReference: 'Z-1',
		email: 'e@x.nl',
	})
	assert.deepEqual(calls[1].body, {
		portal: 'p',
		register: 'r',
		schema: 's',
		caseType: 'c',
		caseReference: 'Z-1',
		email: 'e@x.nl',
	})
	const redeemed = await api.redeemReferenceLink('t2')
	const opened = await api.referenceCase(redeemed.data.bearer)
	assert.equal(calls[3].options.headers.Authorization, 'Bearer b')
	assert.deepEqual(lib.referenceCaseFields(opened.case), [
		{ key: 'title', value: 'Parking' },
	])
	assert.equal(
		lib.wayInRefusalText((await api.acceptInvitation('t3')).error),
		'This invitation is no longer valid.',
	)
	assert.equal(
		lib.wayInRefusalText('something_else'),
		'That did not work. Try again later.',
	)
})

test('the screens: a labelled form, a trap out of sight, a read-only case, an accept button', () => {
	const ways = read('src/site/components/WaysIn.vue')
	assert.match(ways, /for="pq-register-email"/)
	assert.match(ways, /autocomplete="email"/)
	assert.match(ways, /class="pq-way-in__trap"[\s\S]*tabindex="-1"/)
	assert.match(ways, /v-if="ways\.referenceCaseTypes\.length > 1"/)
	const link = read('src/site/components/WayInLink.vue')
	assert.match(link, /data-testid="reference-case"/)
	assert.doesNotMatch(link, /<input|<textarea/)
	assert.match(link, /data-testid="way-in-accept"/)
})

test('every string of the ways in is in both locales, without em-dashes', () => {
	const sources = [
		read('src/site/components/WaysIn.vue'),
		read('src/site/components/WayInLink.vue'),
		read('src/site/lib/waysIn.js'),
	].join('\n')
	const keys = new Set()
	for (const match of sources.matchAll(/\bt\(\s*'([^']+)'/g)) {
		keys.add(match[1])
	}
	const texts = read('src/site/lib/waysIn.js')
	for (const match of texts.matchAll(/^\t\t\w+: '([^']+)',?$/gm)) {
		keys.add(match[1])
	}
	for (const match of texts.matchAll(/return '([^']+)'/g)) {
		keys.add(match[1])
	}
	assert.ok(keys.size > 20)
	for (const key of keys) {
		assert.ok(nl[key], `nl lacks "${key}"`)
		assert.ok(en[key], `en lacks "${key}"`)
		assert.doesNotMatch(nl[key] + en[key], /—/, key)
	}
})

test('the site consumes the link and the sign-in screen opens only the doors waysIn opens', () => {
	const app = read('src/site/App.vue')
	assert.match(app, /<WayInLink[\s\S]*?v-else-if="wayInLink"/)
	assert.match(app, /wayInLink: hasWayInLink\(window\.location\)/)
	assert.match(app, /waysInFrom\(this\.signinConfig\)/)
	const area = read('src/site/components/AccountArea.vue')
	assert.match(area, /<WaysIn[\s\S]*?v-if="ways\.register \|\| ways\.reference"/)
})

test('a button link ships its stylesheet, so a sign-in link never falls back to browser blue', () => {
	// Seen on a themed portal's welcome page (2026-10-05): the second
	// "Inloggen met DigiD" carried utrecht-button-link classes, no stylesheet
	// in the site defined them, and the browser drew rgb(0, 0, 238).
	const sheet = '@utrecht/button-link-css'
	const declared = JSON.parse(read('package.json')).dependencies
	assert.ok(declared[sheet], `${sheet} is a dependency of its own`)
	assert.match(
		read('node_modules/@utrecht/button-link-css/dist/index.css'),
		/\.utrecht-button-link--primary-action/,
	)
	const users = [
		'src/site/components/AccountArea.vue',
		'src/site/components/FooterColumns.vue',
		'src/site/components/IntakeFormBlock.vue',
		'src/site/components/chrome/HeaderTools.vue',
		'src/site/components/chrome/SignInPage.vue',
		'src/site/pages/inbox/InboxPage.vue',
		'src/site/widgets/nlAlert/NlAlert.vue',
	]
	for (const file of users) {
		const source = read(file)
		assert.match(source, /class="utrecht-button-link /, file)
		assert.ok(
			source.includes(`import '${sheet}/dist/index.css'`),
			`${file} imports the stylesheet of the classes it uses`,
		)
	}
	const using = readdirSync(join(ROOT, 'src/site'), { recursive: true })
		.filter((file) => String(file).endsWith('.vue'))
		.map((file) => join('src/site', String(file)))
		.filter((file) => /class="[^"]*utrecht-button-link\b/.test(read(file)))
	assert.deepEqual(using.sort(), users, 'every button link in the site is covered')
})
