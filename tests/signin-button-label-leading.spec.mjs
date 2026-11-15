#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// signin-button-label-leading.spec.mjs: the sign-in buttons draw their labels
// at the font's own leading, as the Inloggen board does (18px on 21.6, not the
// page's 1.5), and keep their 52px height.
//
// Usage:
//   node --test tests/signin-button-label-leading.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const css = readFileSync(new URL('../css/site-theme.css', import.meta.url), 'utf8')

test('a designed site sets the sign-in button label at the font\'s own leading', () => {
	const rule = css.match(/\n\.pq-site:has\(\.pq-site__header--designed\) \.pq-signin__button\.utrecht-button-link \{([^}]*)\}/)
	assert.ok(rule, 'the designed sign-in button rule exists')
	assert.match(rule[1], /line-height: normal;/)
})

test('the sign-in button keeps its 52px height', () => {
	const rule = css.match(/\n\.pq-signin__button\.utrecht-button-link \{([^}]*)\}/)
	assert.ok(rule, 'the sign-in button rule exists')
	assert.match(rule[1], /min-block-size: 52px;/)
})
