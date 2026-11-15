#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-software-catalogue.spec.mjs: the Zuiddrecht example site places
// stackiq's public software catalogue (board SkPubliek), and only where stackiq
// is installed.
//
// Usage:
//   node --test tests/site-software-catalogue.spec.mjs
//
// @spec openspec/specs/example-site/spec.md

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const site = JSON.parse(readFileSync(new URL('../lib/Settings/sites/zuiddrecht.json', import.meta.url), 'utf8'))
const page = site.pages.find((p) => p.route === '/softwarecatalogus')

test('the site has a Softwarecatalogus page that needs stackiq', () => {
	assert.ok(page, 'no /softwarecatalogus page')
	assert.equal(page.status, 'published')
	assert.equal(page.requiresApp, 'stackiq')
})

test('the page places the public records block on stackiq softwareCatalogue', () => {
	const block = page.body.widgets.find((w) => w.widgetKey === 'publicRecords')
	assert.ok(block, 'no publicRecords block')
	assert.equal(block.props.app, 'stackiq')
	assert.equal(block.props.list, 'softwareCatalogue')
	assert.ok(block.props.searchLabel, 'the list needs a search label')
})

// @spec openspec/specs/example-site/spec.md#requirement-the-example-site-lets-an-organisation-register-from-the-software-catalogue
test('the page carries the registration form, which saves into stackiq as pending', () => {
	const form = (site.forms || []).find((f) => f.externalReference === 'organisation-registration')
	assert.ok(form, 'no registration form declared')
	assert.equal(form.requiresApp, 'stackiq')
	assert.equal(form.status, 'active')
	assert.deepEqual(form.destination, { register: 'stackiq', schema: 'organization', fixed: { registrationStatus: 'pending' } })
	assert.deepEqual(form.fields.map((f) => f.id), ['name', 'type', 'registrationNumber', 'registrationContactName', 'registrationContactEmail', 'website'])
	assert.ok(form.fields.every((f) => f.id !== 'registrationStatus'), 'the status is fixed by the server, never a field')

	const block = page.body.widgets.find((w) => w.widgetKey === 'form')
	assert.ok(block, 'no form block on the page')
	assert.equal(block.props.formKey, 'organisation-registration')
	assert.deepEqual(block.props.fields, form.fields)
})
