#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// dashboard-submissions-time-first.spec.mjs: the dashboard's "Recente
// inzendingen" rows lead with the time of day, as board PtDashboard draws
// them ("09.15", then the form and its source, then the status pill). The
// day moves into the meta line, so an older submission still says when.
// Needs nextcloud-vue's record-list `leading` (feat/record-list-leading-time).
//
// Usage:
//   node --test tests/dashboard-submissions-time-first.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const manifest = JSON.parse(readFileSync(join(ROOT, 'src', 'manifest.json'), 'utf8'))
const widget = manifest.pages.flatMap((p) => p.config?.widgets || []).find((w) => w.id === 'list-submissions')

test('the submissions list leads with the time and keeps the day in its meta', () => {
	assert.equal(widget.content.leading, '{submittedAt|time}')
	assert.match(widget.content.meta, /\{submittedAt\|relative\}/)
	assert.equal(widget.content.trailing, undefined)
})
