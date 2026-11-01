#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// resident-menu-label-leading.spec.mjs: the Mijn menu draws its labels at the
// font's own leading, as the Mijn boards do (MijnZaken, Berichten, MijnTaken:
// 17px on 20.4, not the page's 1.5).
//
// Usage:
//   node --test tests/resident-menu-label-leading.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'

const menu = readFileSync(new URL('../src/site/components/ResidentMenu.vue', import.meta.url), 'utf8')

test('a menu label keeps the font\'s own leading', () => {
	const rule = menu.match(/\n\.pq-resident-menu__label \{([^}]*)\}/)
	assert.ok(rule, 'the label rule exists')
	assert.match(rule[1], /line-height: normal;/)
})
