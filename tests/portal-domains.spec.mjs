#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-domains.spec.mjs: the Domeinen and Beheerders sections of a portal's
// Instellingen tab refuse a host name with a scheme, show the TXT record of a
// pending domain and keep editors tidy (portal-domains-and-editors REQ-PDE-001,
// REQ-PDE-002, REQ-PDE-003). Both are nextcloud-vue `field-list` widgets in the
// manifest, so this reads their configuration; the side cards still use the
// two helpers in src/lib/portalDomains.js.
//
// Usage:
//   node --test tests/portal-domains.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { certificateEnd, initials } from '../src/lib/portalDomains.js'
import { pageDraws } from './lib/placement.mjs'

const manifest = JSON.parse(readFileSync(new URL('../src/manifest.json', import.meta.url), 'utf8'))
const detail = manifest.pages.find((page) => page.id === 'PortalDetail')
const widget = (id) => detail.config.widgets.find((w) => w.id === id)

test('the Instellingen tab is a sections widget that places both lists', () => {
	const settings = widget('portal-settings')
	assert.equal(settings.type, 'sections')
	const ids = settings.content.sections.map((s) => s.widgetId)
	assert.ok(ids.includes('portal-domains'))
	assert.ok(ids.includes('portal-editors'))
	assert.ok(pageDraws(detail.config, 'field-list'))
})

test('a plain host name is accepted and a scheme, port or path is refused', () => {
	const field = widget('portal-domains').content.add.fields.find((f) => f.key === 'hostname')
	assert.equal(field.required, true)
	const host = new RegExp(field.pattern)
	assert.ok(host.test('nieuw.zuiddrecht.nl'))
	for (const bad of ['https://nieuw.zuiddrecht.nl/', 'nieuw.zuiddrecht.nl:8080', 'nieuw.zuiddrecht.nl/pad', 'zuiddrecht', '', ' ']) {
		assert.equal(host.test(bad), false, bad)
	}
	assert.match(field.patternMessage, /without https:\/\//)
})

test('a new domain waits for verification and is not listed twice', () => {
	const { add } = widget('portal-domains').content
	assert.deepEqual(add.defaults, { verified: false })
	assert.equal(add.unique, 'hostname')
})

test('a pending domain shows its TXT record and can be checked again', () => {
	const { content } = widget('portal-domains')
	assert.deepEqual(content.row.detail.when, { verified: false })
	assert.match(content.row.detail.template, /\{name\} = \{value\}/)
	const verify = content.rowActions.find((a) => a.id === 'verify')
	assert.deepEqual(verify.when, { verified: false })
	// The parameters ride in the payload (nc-vue 2.81 accepts an `@row.` token there).
	assert.equal(verify.request.method, 'POST')
	assert.equal(verify.request.url, '/apps/portaliq/api/cms/domains/verify')
	assert.deepEqual(verify.request.payload, { portal: '@object.slug', hostname: '@row.hostname' })
	assert.equal(content.rowSource.url, '/apps/portaliq/api/cms/domains?portal=@object.slug')
	assert.equal(content.row.aside.path, 'certificateValidUntil')
	assert.equal(content.row.aside.fallback, 'not checked yet')
})

test('an editor needs a user, is not listed twice and shows the user id without a name', () => {
	const { content } = widget('portal-editors')
	assert.equal(content.field, 'editors')
	assert.equal(content.add.unique, 'userId')
	assert.equal(content.add.fields.find((f) => f.key === 'userId').required, true)
	assert.equal(content.row.titleFallback, 'userId')
	const role = content.add.fields.find((f) => f.key === 'role')
	assert.deepEqual(role.options.map((o) => o.value), ['editor', 'chief-editor'])
})

test('the certificate reads its end date, or nothing when there is none', () => {
	const format = (d) => d.toISOString().slice(0, 10)
	assert.equal(certificateEnd({ certificateValidUntil: '2027-01-14T12:00:00+00:00' }, format), '2027-01-14')
	assert.equal(certificateEnd({ hostname: 'a.nl' }, format), null)
	assert.equal(certificateEnd({ certificateValidUntil: 'rubbish' }, format), null)
})

test('initials are the first letters of the first two words', () => {
	assert.equal(initials('Pieter Jansen'), 'PJ')
	assert.equal(initials('anouk'), 'A')
	assert.equal(initials(''), '')
})
