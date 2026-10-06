#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// example-resident.spec.mjs: every shipped example resident
// (lib/Settings/sites/residents/*.json) held against what this app can
// check without an instance:
//
//   - it belongs to a shipped example site, and names a resident and a way in
//     the portal schema allows;
//   - the portal account the install writes fits the `portalAccount` schema of
//     the register this app ships;
//   - every placeholder is one ExampleResidentValues fills, every lookup is
//     named by the object that uses it, and every object it quotes was
//     declared before it;
//   - the sign-in card and every text a resident reads follow the house rules
//     (no em-dash, no Title Case on the card).
//
// The cases live in another app's register, so their keys are proven on a
// live instance by the install itself (ExampleResidentInstaller reads back
// what it wrote). This check is the half that runs without one.
//
// Usage:
//   node --test tests/example-resident.spec.mjs
//
// @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md

import assert from 'node:assert/strict'
import { readdirSync, readFileSync } from 'node:fs'
import { test } from 'node:test'

const root = new URL('../', import.meta.url)
const read = (path) => readFileSync(new URL(path, root), 'utf8')

const register = JSON.parse(read('lib/Settings/portaliq_register.json'))
const schemas = register.components.schemas
const siteIds = readdirSync(new URL('lib/Settings/sites/', root))
	.filter((f) => f.endsWith('.json'))
	.map((f) => f.replace(/\.json$/, ''))
const residents = readdirSync(new URL('lib/Settings/sites/residents/', root))
	.filter((f) => f.endsWith('.json'))
	.map((file) => ({
		file,
		resident: JSON.parse(read(`lib/Settings/sites/residents/${file}`)),
	}))

const PLACEHOLDER = /\{\{([^{}]+)\}\}/g
const FORMS = [
	/^subject$/,
	/^lookup:[A-Za-z][A-Za-z0-9]*$/,
	/^object:[a-z0-9-]+\.[A-Za-z]+$/,
	/^date:-?\d{1,4}$/,
	/^datetime:-?\d{1,4} ([01]\d|2[0-3]):[0-5]\d$/,
]

/**
 * Every string in a value, with its path.
 *
 * @param {*} value The value.
 * @param {string} path Where we are.
 * @return {Array<[string, string]>} Path and text pairs.
 */
function strings(value, path = '') {
	if (typeof value === 'string') {
		return [[path, value]]
	}
	if (Array.isArray(value)) {
		return value.flatMap((item, index) => strings(item, `${path}[${index}]`))
	}
	if (value && typeof value === 'object') {
		return Object.entries(value).flatMap(([key, child]) =>
			strings(child, path ? `${path}.${key}` : key),
		)
	}
	return []
}

/**
 * The portal account the install writes, as ExampleResidentWayIn builds it.
 *
 * @param {object} resident The declaration.
 * @return {object} The account row.
 */
function accountOf(resident) {
	return {
		subjectRef: resident.resident.userId,
		audience: resident.resident.audience,
		organisation: resident.resident.organisation,
		displayName: resident.resident.displayName,
		identityType: 'dev',
		identityRef: 'example-resident',
		status: 'active',
		contactChannel: 'portal',
	}
}

test('there is at least one shipped example resident', () => {
	assert.ok(residents.length > 0)
})

for (const { file, resident } of residents) {
	test(`${file}: names itself, its site, its resident and its way in`, () => {
		assert.equal(resident.id, file.replace(/\.json$/, ''))
		assert.ok(
			siteIds.includes(resident.portal),
			`${resident.portal} is a shipped example site`,
		)
		const site = JSON.parse(read(`lib/Settings/sites/${resident.portal}.json`))
		assert.equal(site.portal.slug, resident.portal)
		for (const key of ['userId', 'displayName', 'audience', 'organisation']) {
			assert.equal(typeof resident.resident[key], 'string')
			assert.notEqual(resident.resident[key], '')
		}
		assert.match(resident.resident.userId, /^[a-z0-9._-]+$/)
		assert.ok(
			['citizen', 'client'].includes(resident.resident.audience),
			'dossiq contributes to a citizen or a client',
		)
		const modes = schemas.portal.properties.authentication.properties.modes.items.enum
		assert.ok(modes.includes(resident.signIn.mode), `${resident.signIn.mode} is a sign-in mode`)
		assert.notEqual(resident.signIn.mode, 'public', 'a resident signs in')
		for (const key of ['title', 'text', 'button']) {
			assert.equal(typeof resident.signIn.label[key], 'string')
		}
	})

	test(`${file}: the portal account fits the portalAccount schema`, () => {
		const schema = schemas.portalAccount
		const account = accountOf(resident)
		for (const key of schema.required || []) {
			assert.ok(key in account, `${key} is required`)
		}
		for (const [key, value] of Object.entries(account)) {
			const property = schema.properties[key]
			assert.ok(property, `${key} is a property of portalAccount`)
			assert.equal(typeof value, property.type)
			if (property.enum) {
				assert.ok(property.enum.includes(value), `${key}: ${value} is allowed`)
			}
		}
		assert.ok(!('email' in account), 'no e-mail address, so the resident meets the prompt for one')
	})

	test(`${file}: every object is well formed and every placeholder can be filled`, () => {
		const seen = new Set()
		for (const object of resident.objects) {
			assert.match(object.key, /^[a-z0-9-]+$/)
			assert.ok(!seen.has(object.key), `${object.key} is declared once`)
			assert.equal(typeof object.register, 'string')
			assert.equal(typeof object.schema, 'string')
			assert.ok(object.data && typeof object.data === 'object')
			for (const field of object.jsonFields || []) {
				assert.ok(field in object.data, `${object.key}: jsonFields names ${field}, which data holds`)
			}
			const lookups = Object.keys(object.lookups || {})
			for (const [name, lookup] of Object.entries(object.lookups || {})) {
				assert.equal(typeof lookup.schema, 'string', `${object.key}: lookup ${name} names a schema`)
				assert.ok(Object.keys(lookup.where || {}).length > 0, `${object.key}: lookup ${name} asks for something`)
			}
			const texts = [
				...strings(object.data, `${object.key}.data`),
				...strings(object.lookups || {}, `${object.key}.lookups`),
			]
			for (const [path, text] of texts) {
				for (const match of text.matchAll(PLACEHOLDER)) {
					const inner = match[1].trim()
					assert.ok(FORMS.some((form) => form.test(inner)), `${path}: {{${inner}}} is a form the install fills`)
					if (inner.startsWith('lookup:')) {
						assert.ok(lookups.includes(inner.slice(7)), `${path}: lookup ${inner.slice(7)} is declared on this object`)
					}
					if (inner.startsWith('object:')) {
						const key = inner.slice(7).split('.')[0]
						assert.ok(seen.has(key), `${path}: ${key} was declared before this object`)
					}
				}
			}
			seen.add(object.key)
		}
	})

	test(`${file}: the resident's texts follow the house rules`, () => {
		const texts = strings(resident).filter(([path]) => !path.startsWith('$comment'))
		for (const [path, text] of texts) {
			assert.ok(!text.includes('—'), `${path}: no em-dash`)
			assert.ok(!text.includes('--'), `${path}: no double dash`)
		}
		for (const [key, text] of Object.entries(resident.signIn.label)) {
			const words = text.split(' ')
			const capitals = words.slice(1).filter((word) => /^[A-Z][a-z]/.test(word))
			assert.ok(
				capitals.length < Math.max(2, words.length / 2),
				`signIn.label.${key}: sentence case, not Title Case`,
			)
		}
	})
}
