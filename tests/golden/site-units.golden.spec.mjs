#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-units.golden.spec.mjs: pins what the site's form, sign-in, QR, cell
// and grouping helpers answer over a spread of inputs
// (tests/Fixtures/Golden/site-units.json).
//
// Usage:
//   node --test tests/golden/site-units.golden.spec.mjs

import { test } from 'node:test'
import { groupRows } from '../../src/shared/collectionGroups.js'
import { readable } from '../../src/site/components/collections/cells.js'
import { fieldErrors, fieldInput, formBody, withSingleOptions } from '../../src/site/components/c/forms.js'
import { signInRoutes } from '../../src/site/lib/authApi.js'
import { encodeQr } from '../../src/site/lib/qr.js'
import { golden } from '../support/golden.mjs'

const t = (key, vars = {}) => `[${key}]${Object.entries(vars).map(([k, v]) => `${k}=${v}`).join(',')}`

const ACTION = {
	fields: ['name', 'when', 'at', 'count', 'mail', 'story', 'pick', 'child', 'upload', 'agree', 'amount', 'free', 42],
	fieldConfigs: {
		name: { label: 'Naam', required: true },
		when: { input: 'date', required: true, requiredMessage: 'Vul een datum in' },
		at: { input: 'datetime' },
		count: { widget: 'count' },
		mail: { input: 'email', required: true, requiredMessage: '  ' },
		story: { size: 'large' },
		pick: { required: true },
		child: { required: true, label: 'Kind' },
		upload: { required: true, label: 'Bestand', type: 'file' },
		agree: { valueType: 'boolean' },
		amount: { input: 'number', valueType: 'number' },
		free: { size: 'full' },
	},
	optionsProviders: { pick: { type: 'static', options: [{ value: 'a', label: 'A' }] }, child: { type: 'collection' } },
	fileFields: ['upload'],
	files: ['upload'],
}

const VALUES = [
	{},
	{ name: 'Vera', when: '31-02-2026', at: '2026-03-01T10:00', count: '3', mail: 'a@b.nl', story: 'x', pick: '', child: null, agree: 'ja', amount: '1,5', free: ' ' },
	{ name: ' ', when: '2026-03-01', count: 'drie', agree: 'misschien', amount: 'veel', pick: 'b', child: 'c1' },
]
const OPTIONS = [{}, { pick: [{ value: 'a' }], child: [{ value: 'c1', label: 'Vera' }] }, { child: [{ value: '' }] }, null]
const FILES = [{}, { upload: [{ name: 'f' }] }, null]

const SITES = [
	null,
	{ authentication: { modes: ['public', 'nextcloud', 'local', 'oidc', 'digid', 'eherkenning', 'eidas', 'email-link', 'odd'] } },
	{
		slug: 'zuid drecht',
		lostPasswordUrl: '/lost',
		registerUrl: '/register',
		authentication: {
			modes: ['nextcloud', 'digid', 'email-link'],
			modeLabels: { digid: { button: 'Met DigiD', title: 'Inwoners' }, nextcloud: { text: 'Account' } },
		},
	},
	{ slug: 'x', authentication: { modes: ['digid', 'local'] } },
]
const DEMOS = [
	undefined,
	[],
	[{ key: 'vera', name: 'Vera' }, { key: 'sami', name: '', card: { button: 'Sami', title: 'S', text: 'tekst' } }, null, { key: '' }],
	'not a list',
]

const CELLS = [null, undefined, '', true, false, 3.5, Number.NaN, 1234567, 'tekst', '  ', '2026-03-01', '2026-03-01T10:15:00Z', 'f47ac10b-58cc-4372-a567-0e02b2c3d479', ['a', 2, null, ['x', ['y', ['z', ['deep']]]]], { name: 'Naam' }, { title: '  ', a: 1, '@self': { id: 'x' }, b: { c: { d: { e: 'te diep' } } } }, { label: 'Groep 7', id: 'x' }, () => 1]

const GROUP_ROWS = [
	{ learnerRef: 'vera', id: 1 },
	{ learnerRef: 'daan', id: 2 },
	{ learnerRef: 7, id: 3 },
	{ id: 4 },
	{ learnerRef: 'vera', id: 5 },
	{ learnerRef: 'item 10', id: 6 },
	{ learnerRef: 'item 9', id: 7 },
]
const LABEL_ROWS = [{ id: 'vera', name: 'Vera' }, { '@self': { id: 'daan' }, givenName: 'Daan' }, { id: '7', title: '' }, null]

test('the site helpers answer as pinned', () => {
	const file = golden('site-units')
	for (const field of [...ACTION.fields, 'unknown']) {
		for (const [i, options] of [undefined, [], [{ value: 'a' }]].entries()) {
			file.check(`fieldInput:${field}:${i}`, fieldInput(ACTION, field, options))
		}
	}
	VALUES.forEach((values, v) => {
		OPTIONS.forEach((options, o) => {
			file.check(`withSingleOptions:${v}:${o}`, withSingleOptions(ACTION, values, options))
			file.check(`formBody:${v}:${o}`, formBody(ACTION, values, options || {}))
		})
		FILES.forEach((files, f) => {
			file.check(`fieldErrors:${v}:${f}`, fieldErrors(ACTION, values, files, t))
			file.check(`fieldErrors:noT:${v}:${f}`, fieldErrors(ACTION, values, files))
		})
	})

	for (const [s, site] of SITES.entries()) {
		file.check(`signIn:${s}:plain`, signInRoutes(site, { authBase: '/auth' }))
		file.check(`signIn:${s}:t`, signInRoutes(site, { authBase: '/auth', t }))
		file.check(`signIn:${s}:emailLinkTruthy`, signInRoutes(site, { authBase: '/auth', t, emailLink: 'yes' }))
		for (const [d, demos] of DEMOS.entries()) {
			file.check(`signIn:${s}:demo:${d}`, signInRoutes(site, { authBase: '/auth', t, exampleResident: 'set-1', demoResidents: demos, emailLink: true }))
			file.check(`signIn:${s}:noDemo:${d}`, signInRoutes(site, { authBase: '/auth', t, exampleResident: '', demoResidents: demos, emailLink: false }))
		}
	}
	globalThis.window = { location: { pathname: '/mijn/x', search: '?a=1' } }
	try {
		file.check('signIn:window', signInRoutes(SITES[2], { authBase: '/auth', t, exampleResident: 'set 1', demoResidents: DEMOS[2], emailLink: true }))
		file.check('signIn:windowNoSlug', signInRoutes(SITES[1], { authBase: '/auth', t, exampleResident: 'set', demoResidents: [], emailLink: false }))
	} finally {
		delete globalThis.window
	}

	for (const text of ['', 'a', 'https://example.org/zaak/123', 'é'.repeat(60), 'x'.repeat(150), 'y'.repeat(400), 'z'.repeat(700), 'q'.repeat(2000)]) {
		const qr = encodeQr(text)
		file.check(`qr:${text.length}`, qr && { version: qr.version, size: qr.size, rows: qr.modules.map((row) => row.map((dark) => (dark ? '1' : '0')).join('')) })
	}

	for (const [i, value] of CELLS.entries()) {
		file.check(`readable:${i}`, readable(value, { locale: 'nl-NL', t }))
		file.check(`readable:deep:${i}`, readable(value, { locale: 'en-GB', t }, 2))
		file.check(`readable:deeper:${i}`, readable(value, { locale: 'nl-NL', t }, 3))
	}

	file.check('groupRows:labels', groupRows(GROUP_ROWS, 'learnerRef', LABEL_ROWS))
	file.check('groupRows:noLabels', groupRows(GROUP_ROWS, 'learnerRef'))
	file.check('groupRows:nullLabels', groupRows(GROUP_ROWS, 'learnerRef', null))
	file.check('groupRows:one', groupRows([{ k: 'a' }, { k: 'a' }], 'k'))
	file.check('groupRows:onlyEmpty', groupRows([{ k: 'a' }, {}], 'k'))
	file.check('groupRows:noField', groupRows(GROUP_ROWS, ''))
	file.check('groupRows:none', groupRows([], 'k'))
	file.check('groupRows:notRows', groupRows(null, 'k'))
	file.save()
})
