#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// account-page-one-inset.spec.mjs: the Mijn account page title stands where
// the MijnAccount boards draw it (y 221), one 24px inset under the crumbs.
// AccountPage's root is a `.pq-account` and, as the root of a child, carries
// AccountArea's scope, so the shell's inset must name the shell alone.
//
// Usage:
//   node --test tests/account-page-one-inset.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const area = readFileSync(new URL('../src/site/components/AccountArea.vue', import.meta.url), 'utf8')
const page = readFileSync(new URL('../src/site/pages/e/AccountPage.vue', import.meta.url), 'utf8')
const css = area.slice(area.indexOf('<style scoped>'))

test('the account page root shares the shell\'s class', () => {
	assert.match(page, /<section\s+v-else\s+class="pq-account"/)
	assert.match(area, /class="container pq-account"/)
})

test('the shell inset names the shell, not every .pq-account', () => {
	assert.doesNotMatch(css, /\n\.pq-account \{[^}]*padding-block/)
	assert.match(css, /\n\.container\.pq-account \{\s*padding-block: 24px;/)
})
