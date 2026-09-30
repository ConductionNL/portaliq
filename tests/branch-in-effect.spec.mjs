#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// branch-in-effect.spec.mjs: the portal header names the branch (vestiging)
// a business session acts for (signin-eherkenning-branch T06).
//
// Usage:
//   node --test tests/branch-in-effect.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { branchInEffect } from '../src/portal/lib/branch.js'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const t = (key, vars = {}) => key.replace(/\{(\w+)\}/g, (_, name) => String(vars[name] ?? ''))

test('a login for one branch is named as such', () => {
	assert.equal(branchInEffect({ branch: '000012345678', branchRestricted: true }, t), 'Signed in for branch 000012345678')
})

test('a chosen branch is named without the login wording', () => {
	assert.equal(branchInEffect({ branch: '000012345678', branchRestricted: false }, t), 'Branch 000012345678')
})

test('a whole-company session and a malformed branch show nothing', () => {
	assert.equal(branchInEffect({ branch: '' }, t), '')
	assert.equal(branchInEffect({ branch: 'shop-12', branchRestricted: true }, t), '')
	assert.equal(branchInEffect(null, t), '')
})

test('both strings are translated for every locale the portal ships', () => {
	for (const locale of ['nl', 'en']) {
		const strings = JSON.parse(readFileSync(join(ROOT, 'src', 'portal', 'i18n', `${locale}.json`), 'utf8'))
		for (const key of ['Signed in for branch {number}', 'Branch {number}']) {
			assert.ok(typeof strings[key] === 'string' && strings[key] !== '', `${locale}: ${key}`)
		}
	}
})

test('the header shows the branch in effect', () => {
	const app = readFileSync(join(ROOT, 'src', 'portal', 'App.jsx'), 'utf8')
	assert.match(app, /import \{ branchInEffect \} from '@portal\/lib\/branch\.js'/)
	assert.match(app, /branchInEffect\(state\.session, t\)/)
})
