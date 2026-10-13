#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// example-resident.spec.mjs: every example-set fragment portaliq ships
// (lib/Settings/example-sets/*.json) held against what this app can check
// without an instance:
//
//   - it names its example resident: a shipped example site, a portal account
//     object and a sign-in change of its own, and a mode the portal schema allows;
//   - the portal account fits the `portalAccount` schema of the register this
//     app ships;
//   - every placeholder is one OpenRegister's example-set loader fills, the
//     resident is a cast member, every lookup is named by the object that
//     uses it, and every object it quotes was declared before it;
//   - the sign-in card and every text a resident reads follow the house rules
//     (no em-dash, no Title Case on the card);
//   - the Zuiddrecht resident keeps the five cases the Woo journey and the
//     resident boards depend on.
//
// The cases live in another app's register and the cast lives in
// OpenRegister, so `occ openregister:example-set:install <set> --check`
// proves the rest on a live instance. This check is the half that runs
// without one.
//
// Usage:
//   node --test tests/example-resident.spec.mjs
//
// @spec openspec/changes/resident-on-example-set/specs/example-resident/spec.md

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
const fragments = readdirSync(new URL('lib/Settings/example-sets/', root))
	.filter((f) => f.endsWith('.json'))
	.map((file) => ({
		file,
		fragment: JSON.parse(read(`lib/Settings/example-sets/${file}`)),
	}))

const PLACEHOLDER = /\{\{([^{}]+)\}\}/g
const FORMS = [
	/^cast:[a-z0-9-]+\.[A-Za-z.]+$/,
	/^lookup:[A-Za-z][A-Za-z0-9]*$/,
	/^object:[a-z0-9-]+\.[A-Za-z]+$/,
	/^date:[+-]?\d{1,4}$/,
	/^datetime:[+-]?\d{1,4} ([01]\d|2[0-3]):[0-5]\d$/,
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

test('there is at least one shipped example-set fragment', () => {
	assert.ok(fragments.length > 0)
})

for (const { file, fragment } of fragments) {
	const resident = fragment.exampleResident
	const account = fragment.objects.find((object) => object.key === resident?.account)
	const signIn = (fragment.changes || []).find((change) => change.key === resident?.signIn)

	test(`${file}: names its example resident, its site and its way in`, () => {
		assert.ok(resident, 'exampleResident is declared')
		assert.equal(fragment.set ?? file.replace(/\.json$/, ''), file.replace(/\.json$/, ''))
		assert.ok(siteIds.includes(resident.portal), `${resident.portal} is a shipped example site`)
		assert.match(resident.cast, /^[a-z0-9-]+$/)
		assert.ok(account, `${resident.account} is an object of this fragment`)
		assert.equal(account.schema, 'portalAccount')
		assert.ok(signIn, `${resident.signIn} is a change of this fragment`)
		assert.equal(signIn.schema, 'portal')
		assert.equal(signIn.where.slug, resident.portal)
		const modes = schemas.portal.properties.authentication.properties.modes.items.enum
		assert.ok(modes.includes(resident.mode), `${resident.mode} is a sign-in mode`)
		assert.notEqual(resident.mode, 'public', 'a resident signs in')
		assert.equal(signIn.append['authentication.modes'], resident.mode)
		const label = signIn.set[`authentication.modeLabels.${resident.mode}`]
		for (const key of ['title', 'text', 'button']) {
			assert.equal(typeof label[key], 'string')
		}
	})

	test(`${file}: the portal account fits the portalAccount schema`, () => {
		const schema = schemas.portalAccount
		for (const key of schema.required || []) {
			assert.ok(key in account.data, `${key} is required`)
		}
		for (const [key, value] of Object.entries(account.data)) {
			const property = schema.properties[key]
			assert.ok(property, `${key} is a property of portalAccount`)
			assert.equal(typeof value, property.type)
			if (property.enum) {
				assert.ok(property.enum.includes(value), `${key}: ${value} is allowed`)
			}
		}
		assert.equal(account.data.subjectRef, `{{cast:${resident.cast}.uid}}`)
		assert.equal(account.data.identityRef, 'example-resident')
		assert.ok(!('email' in account.data), 'no e-mail address, so the resident meets the prompt for one')
	})

	test(`${file}: every object is well formed and every placeholder can be filled`, () => {
		const seen = new Set()
		for (const object of fragment.objects) {
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
				assert.ok(!text.includes('{{subject}}'), `${path}: the resident is a cast member now`)
				for (const match of text.matchAll(PLACEHOLDER)) {
					const inner = match[1].trim()
					assert.ok(FORMS.some((form) => form.test(inner)), `${path}: {{${inner}}} is a form the loader fills`)
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

	test(`${file}: the texts follow the house rules`, () => {
		const texts = strings(fragment).filter(([path]) => !path.startsWith('$comment'))
		for (const [path, text] of texts) {
			assert.ok(!text.includes('—'), `${path}: no em-dash`)
			assert.ok(!text.includes('--'), `${path}: no double dash`)
		}
		for (const [key, text] of Object.entries(signIn.set[`authentication.modeLabels.${resident.mode}`])) {
			const words = text.split(' ')
			const capitals = words.slice(1).filter((word) => /^[A-Z][a-z]/.test(word))
			assert.ok(
				capitals.length < Math.max(2, words.length / 2),
				`sign-in card ${key}: sentence case, not Title Case`,
			)
		}
	})
}

test('zuiddrecht.json: every portal account and mandate fits its schema, and only Sanne is the one-click resident', () => {
	const zuiddrecht = fragments.find(({ file }) => file === 'zuiddrecht.json').fragment
	const fits = (object) => {
		const schema = schemas[object.schema]
		for (const key of schema.required || []) {
			assert.ok(key in object.data, `${object.key}: ${key} is required`)
		}
		for (const [key, value] of Object.entries(object.data)) {
			const property = schema.properties[key]
			assert.ok(property, `${object.key}: ${key} is a property of ${object.schema}`)
			assert.equal(typeof value, property.type, `${object.key}: ${key} is a ${property.type}`)
			if (property.enum && !value.includes('{{')) {
				assert.ok(property.enum.includes(value), `${object.key}: ${key} ${value} is allowed`)
			}
		}
	}
	const accounts = zuiddrecht.objects.filter((object) => object.schema === 'portalAccount')
	const mandates = zuiddrecht.objects.filter((object) => object.schema === 'portalMandate')
	assert.deepEqual(
		accounts.map((object) => object.data.subjectRef),
		['sanne', 'henk', 'linda', 'fatima', 'janwillem', 'mark'].map((key) => `{{cast:${key}.uid}}`),
	)
	accounts.forEach(fits)
	mandates.forEach(fits)
	for (const mandate of mandates) {
		assert.match(mandate.data.onBehalfOf, /^(subject|kvk):\{\{cast:[a-z]+\.(uid|kvk)\}\}$/, `${mandate.key} names a typed party`)
	}
	assert.deepEqual(
		accounts.filter((object) => object.data.identityRef === 'example-resident').map((object) => object.key),
		[zuiddrecht.exampleResident.account],
		'the one-click demo sign-in stays Sanne-only',
	)
})

test('zuiddrecht.json keeps the five cases of the Woo journey and the resident boards', () => {
	const zuiddrecht = fragments.find(({ file }) => file === 'zuiddrecht.json').fragment
	const cases = zuiddrecht.objects.filter((object) => object.schema === 'case').map((object) => object.data.title)
	assert.deepEqual(cases, [
		'Verlichting fietspad Lindelaan',
		'Dakkapel Lindelaan 14',
		'Losse stoeptegel bij de bushalte Lindelaan',
		'Kapotte lantaarnpaal Kerkplein',
		'Subsidie buurtfeest Lindelaan',
	])
	assert.deepEqual(zuiddrecht.after, ['dossiq'])
	assert.equal(zuiddrecht.exampleResident.cast, 'sanne')
})
