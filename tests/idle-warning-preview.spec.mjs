#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// idle-warning-preview.spec.mjs: a test instance may open the idle warning
// on request, and only a test instance (idle-warning-preview, REQ-SIS-007).
//
// Usage:
//   node --test tests/idle-warning-preview.spec.mjs
//
// @spec openspec/specs/portal-session-idle-and-sso/spec.md

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { idlePreviewWanted } from '../src/site/lib/idleTracker.js'

test('the warning opens at once only when the session allows it and the address asks', () => {
	assert.equal(idlePreviewWanted({ idlePreview: true }, '?portal=zuiddrecht&idleWarning=now'), true)
	assert.equal(idlePreviewWanted({ idlePreview: false }, '?idleWarning=now'), false)
	assert.equal(idlePreviewWanted({}, '?idleWarning=now'), false)
	assert.equal(idlePreviewWanted(null, '?idleWarning=now'), false)
	assert.equal(idlePreviewWanted({ idlePreview: 'yes' }, '?idleWarning=now'), false)
	assert.equal(idlePreviewWanted({ idlePreview: true }, '?idleWarning=later'), false)
	assert.equal(idlePreviewWanted({ idlePreview: true }, ''), false)
})

test('the site asks right after it starts the idle window', () => {
	const app = readFileSync(new URL('../src/site/App.vue', import.meta.url), 'utf8')
	assert.match(
		app,
		/this\.idleTracker\.start\(this\.session\)\s*if \(idlePreviewWanted\(this\.session, window\.location\.search\)\) \{\s*this\.idleWarning = true/,
	)
})
