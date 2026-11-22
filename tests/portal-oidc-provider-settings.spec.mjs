#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// portal-oidc-provider-settings.spec.mjs: where an administrator names the
// one OIDC provider a portal accepts (portal-scoping-and-auth Task 4). The
// portal's Instellingen tab carries an "OIDC provider" section whose form
// writes `authentication.oidc.issuer` and `authentication.oidc.clientId`,
// the two paths PortalAuthConfig reads; nothing else in the authentication
// block is written by it, and every visible text is in the Dutch catalogue.
//
// Usage:
//   node --test tests/portal-oidc-provider-settings.spec.mjs
//
// @spec openspec/specs/portal-scoping-and-auth/spec.md#requirement-each-portal-must-declare-its-authentication-failing-closed

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const read = (name) => readFileSync(new URL(`../${name}`, import.meta.url), 'utf8')
const MANIFEST = JSON.parse(read('src/manifest.json'))
const SCHEMAS = JSON.parse(read('lib/Settings/portaliq_register.json')).components.schemas
const NL = JSON.parse(read('l10n/nl.json')).translations

const page = (id) => MANIFEST.pages.find((p) => p.id === id)
const widget = (pageId, id) => page(pageId).config.widgets.find((w) => w.id === id)

/**
 * The schema fragment at a dot-path, or undefined.
 *
 * @param {object} schema The schema.
 * @param {string} path The dot-path.
 * @return {object|undefined}
 */
function propertyAt(schema, path) {
	let node = schema
	for (const key of path.split('.')) {
		node = node?.properties?.[key]
	}
	return node
}

test('the portal settings carry an OIDC provider section right after sign-in', () => {
	const sections = widget('PortalDetail', 'portal-settings').content.sections
	const at = sections.findIndex((s) => s.key === 'oidc')
	assert.ok(at > 0, 'no OIDC provider section on the portal settings')
	assert.equal(sections[at].widgetId, 'portal-settings-oidc')
	assert.equal(sections[at - 1].key, 'signin', 'the OIDC provider section sits under Sign in')
	assert.ok(NL[sections[at].title], sections[at].title)
})

test('the form writes exactly the issuer and the client id the schema declares', () => {
	const form = widget('PortalDetail', 'portal-settings-oidc')
	assert.equal(form.type, 'settings-form')
	const keys = form.content.fields.map((f) => f.key).sort()
	const declared = Object.keys(SCHEMAS.portal.properties.authentication.properties.oidc.properties)
		.map((k) => `authentication.oidc.${k}`).sort()
	assert.deepEqual(keys, declared)
	for (const field of form.content.fields) {
		assert.equal(propertyAt(SCHEMAS.portal, field.key)?.type, 'string', field.key)
		assert.equal(field.required, undefined, `${field.key} may stay empty: empty names no provider`)
	}
})

test('the issuer must be an https address or empty', () => {
	const field = widget('PortalDetail', 'portal-settings-oidc').content.fields
		.find((f) => f.key === 'authentication.oidc.issuer')
	const pattern = new RegExp(field.pattern)
	assert.ok(pattern.test(''), 'an empty issuer is allowed')
	assert.ok(pattern.test('https://login.example.nl'))
	assert.ok(pattern.test('https://login.example.nl/realms/portal/'))
	assert.equal(pattern.test('http://login.example.nl'), false)
	assert.equal(pattern.test('login.example.nl'), false)
	assert.equal(pattern.test('https://login example.nl'), false)
})

test('every visible text of the form is in the Dutch catalogue', () => {
	const form = widget('PortalDetail', 'portal-settings-oidc')
	for (const text of [form.title, form.content.saveLabel, form.content.savedText]) {
		assert.ok(NL[text], `"${text}" is not in the Dutch catalogue`)
	}
	for (const field of form.content.fields) {
		for (const key of ['label', 'hint', 'patternMessage']) {
			if (field[key]) {
				assert.ok(NL[field[key]], `"${field[key]}" is not in the Dutch catalogue`)
			}
		}
	}
})
