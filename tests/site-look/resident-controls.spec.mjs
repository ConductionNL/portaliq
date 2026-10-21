#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// resident-controls.spec.mjs: the controls on the Mijn Zuiddrecht pages draw
// as the boards do (MijnAccount, Machtigingen, Zaak).
//
// Usage:
//   node --test tests/site-look/resident-controls.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
const css = readFileSync(join(ROOT, 'css/site-theme.css'), 'utf8')
	.replace(/\/\*[\s\S]*?\*\//g, '')
	.replace(/\s+/g, ' ')

test('an outlined button reads its own ink over the vendored !important', () => {
	assert.match(
		css,
		/\.pq-site \.utrecht-button--secondary-action \{ border-color: var\(--_utrecht-button-border-color\) !important; color: var\(--_utrecht-button-color\) !important; \}/,
	)
})
