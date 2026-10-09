#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// email-link-signin.spec.mjs: the site half of sign-in-with-an-email-link
// (tasks 1, 7, 10): the form card only behind the switch, the link taken off
// the address bar at boot, the link page's calls, no fragment in the traffic
// client, no recording of the link page, and the words in both languages.
//
// Usage:
//   node --test tests/email-link-signin.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const read = (path) => readFileSync(join(ROOT, path), 'utf8')
const ways = await import(pathToFileURL(join(ROOT, 'src/site/lib/waysIn.js')).href)
const own = (
	await import(pathToFileURL(join(ROOT, 'src/site/lib/waysInStrings.js')).href)
).default
const nl = { ...JSON.parse(read('src/shared/i18n/nl.json')), ...own.nl }
const en = { ...JSON.parse(read('src/shared/i18n/en.json')), ...own.en }

test('the link leaves the address bar at boot and the link page gets it once', () => {
	const calls = []
	const location = {
		hash: '#email-link=abc123',
		href: 'https://a.example/site?portal=academie#email-link=abc123',
	}
	const history = { replaceState: (...args) => calls.push(args) }

	assert.equal(ways.captureEmailLink(location, history), true)
	assert.deepEqual(calls, [[null, '', 'https://a.example/site?portal=academie']])
	assert.equal(ways.hasWayInLink({ hash: '' }), true)
	assert.deepEqual(ways.takeWayInLink({ hash: '' }, history), {
		kind: 'email-link',
		token: 'abc123',
	})
	assert.equal(ways.takeWayInLink({ hash: '' }, history), null)
})

test('other mailed links are not taken at boot', () => {
	const history = { replaceState: () => assert.fail('no strip') }
	assert.equal(
		ways.captureEmailLink({ hash: '#reference=x', href: 'h' }, history),
		false,
	)
	assert.equal(ways.captureEmailLink({ hash: '', href: 'h' }, history), false)
})

test('the boot takes the link before the app mounts', () => {
	const main = read('src/site/main.js')
	assert.ok(main.indexOf('captureEmailLink(window.location, window.history)') > -1)
	assert.ok(
		main.indexOf('captureEmailLink(window.location')
			< main.indexOf('createApp('),
	)
})

test('the e-mail link card is offered only when the auth edge says so, and opens no registration', async () => {
	const { signInRoutes } = await import(
		pathToFileURL(join(ROOT, 'src/site/lib/authApi.js')).href
	)
	const site = {
		slug: 'academie',
		authentication: { modes: ['email-link', 'eherkenning'] },
	}

	assert.deepEqual(
		signInRoutes(site, '/api', undefined, '', '', false).map((r) => r.mode),
		['eherkenning'],
	)

	const routes = signInRoutes(site, '/api', undefined, '', '', true)
	assert.equal(routes[0].mode, 'email-link')
	assert.equal(routes[0].form, 'email-link')
	assert.equal(routes[0].portal, 'academie')
	assert.equal(routes[0].label, 'Stuur mij een inloglink')
	assert.equal(routes[0].href, '')

	assert.equal(ways.waysInFrom({ waysIn: { emailLink: true } }).emailLink, true)
	assert.equal(ways.waysInFrom({ waysIn: { emailLink: true } }).register, false)
	assert.equal(ways.waysInFrom({}).emailLink, false)
})

test('the form and the link page call the e-mail link routes', async () => {
	const seen = []
	const fetchImpl = async (url, init) => {
		seen.push([url, JSON.parse(init.body)])
		return { ok: true, status: 200, json: async () => ({ sent: true }) }
	}
	const api = ways.waysInApi('/portal/api', 'academie', fetchImpl)

	await api.requestEmailLink('tom@example.nl')
	await api.describeEmailLink('tok')
	await api.redeemEmailLink({ token: 'tok', nonce: 'n1' })

	assert.deepEqual(seen, [
		[
			'/portal/api/identity/email-link',
			{ portal: 'academie', email: 'tom@example.nl' },
		],
		['/portal/api/identity/email-link/describe', { token: 'tok' }],
		[
			'/portal/api/identity/email-link/redeem',
			{ token: 'tok', nonce: 'n1', email: '' },
		],
	])
})

test('the traffic client never sends the fragment', () => {
	assert.match(
		read('src/traffic/client.js'),
		/pageLocation: String\(win\.location\.href\)\s*\.replace\(\/#\.\*\$\/, ''\)/,
	)
})

test('the session recorder skips the link page', () => {
	const recorder = read('src/traffic/recorder.js')
	assert.match(recorder, /#email-link=/)
	assert.match(recorder, /\[data-traffic-no-recording\]/)
	assert.match(
		read('src/site/components/WayInLink.vue'),
		/data-traffic-no-recording/,
	)
})

test('every new sentence is in Dutch and English, without em-dashes', () => {
	const keys = [
		ways.emailLinkSentText(),
		ways.wayInRefusalText('link_used'),
		ways.wayInRefusalText('address_wrong'),
		ways.wayInRefusalText('mode_not_offered'),
		'Send me a sign-in link',
		'The e-mail address you signed up with',
		'Sign in to {portal}',
		'You sign in as {address}.',
		'The e-mail address this link was sent to',
		'Sign in',
	]
	for (const key of keys) {
		assert.ok(nl[key], `nl: ${key}`)
		assert.ok(en[key], `en: ${key}`)
		assert.ok(!nl[key].includes('—') && !en[key].includes('—'), key)
	}
	assert.equal(
		nl[ways.emailLinkSentText()],
		'Als dit adres bij ons bekend is, ontvangt u een link. De link werkt 15 minuten.',
	)
})
